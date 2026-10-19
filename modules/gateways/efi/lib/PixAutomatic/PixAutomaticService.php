<?php

namespace EfiWhmcs\PixAutomatic;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\GatewayLog;
use WHMCS\Database\Capsule;

/**
 * Jornada 3 do Pix Automático: a primeira fatura é Pix imediato; o mesmo copia-e-cola também
 * solicita a autorização para as próximas cobranças. Após a aprovação, cada nova fatura WHMCS
 * recebe uma CobR com valor e vencimento próprios.
 */
final class PixAutomaticService
{
    public const GATEWAY = 'efi_pix_automatico';

    public function __construct(
        private readonly EfiPay $api,
        private readonly array $gatewayParams
    ) {
    }

    public function issueOrFetchInitial(
        int $invoiceId,
        int $clientId,
        int $amountCents,
        string $customerName,
        string $documentDigitsOnly,
        string $invoiceDueDate
    ): array {
        $recurrence = Repository::findRecurrenceByClient($clientId);

        if ($recurrence !== null) {
            if ((int) $recurrence->initial_invoice_id !== $invoiceId && in_array($recurrence->status, ['CANCELADA', 'REJEITADA', 'EXPIRADA'], true)) {
                Repository::updateRecurrence((int) $recurrence->id, [
                    'initial_invoice_id' => $invoiceId,
                    'efi_recurrence_id' => null,
                    'initial_txid' => self::txid('initial', $invoiceId),
                    'loc_id' => null,
                    'status' => 'creating',
                    'amount_cents' => $amountCents,
                    'metadata' => '{}',
                ]);
                $recurrence = (object) [
                    'id' => $recurrence->id,
                    'initial_txid' => self::txid('initial', $invoiceId),
                    'amount_cents' => $amountCents,
                ];
            } elseif ((int) $recurrence->initial_invoice_id !== $invoiceId) {
                throw new \RuntimeException('Este cliente já possui uma autorização de Pix Automático. As próximas faturas serão cobradas automaticamente.');
            } elseif ($recurrence->efi_recurrence_id !== null) {
                return $this->presentExisting($recurrence);
            }
        } else {
            $recurrenceId = Repository::createRecurrence([
                'client_id' => $clientId,
                'initial_invoice_id' => $invoiceId,
                'initial_txid' => self::txid('initial', $invoiceId),
                'status' => 'creating',
                'amount_cents' => $amountCents,
                'metadata' => '{}',
            ]);
            $recurrence = (object) [
                'id' => $recurrenceId,
                'initial_txid' => self::txid('initial', $invoiceId),
                'amount_cents' => $amountCents,
            ];
        }

        $customer = ['nome' => mb_substr($customerName, 0, 200)];
        $customer[CustomerResolver::documentKey($documentDigitsOnly)] = $documentDigitsOnly;
        $initialTxid = (string) $recurrence->initial_txid;

        try {
            $location = $this->api->pixCreateLocationRecurrenceAutomatic();
            $locId = (string) ($location['id'] ?? '');

            if ($locId === '') {
                throw new \RuntimeException('A Efí não retornou o location da autorização Pix Automático.');
            }

            $initialCharge = $this->createOrFetchInitialCharge($initialTxid, [
                'calendario' => ['expiracao' => $this->initialExpirationSeconds()],
                'devedor' => $customer,
                'valor' => ['original' => self::formatAmount($amountCents)],
                'chave' => trim((string) $this->gatewayParams['pix_key']),
                'solicitacaoPagador' => 'Fatura #' . $invoiceId,
                'infoAdicionais' => [['nome' => 'Fatura', 'valor' => '#' . $invoiceId]],
            ]);

            $response = $this->api->pixCreateRecurrenceAutomatic([], [
                'vinculo' => [
                    'contrato' => 'WHMCS-' . $clientId,
                    'devedor' => $customer,
                    'objeto' => trim((string) ($this->gatewayParams['contract_object'] ?? 'Serviços recorrentes WHMCS')),
                ],
                'calendario' => [
                    'dataInicial' => $this->firstRecurringDate($invoiceDueDate),
                    'periodicidade' => $this->periodicity(),
                ],
                // `valorMinimoRecebedor` (em vez de `valorRec` fixo) permite que cada CobR tenha
                // um valor diferente, desde que não menor que este -- necessário porque a fatura
                // WHMCS muda de valor (multa, desconto, crédito aplicado, item adicional) sem
                // que isso deva travar a cobrança recorrente (ver scheduleInvoice()).
                'valor' => ['valorMinimoRecebedor' => self::formatAmount($amountCents)],
                'politicaRetentativa' => (string) ($this->gatewayParams['retry_policy'] ?? 'NAO_PERMITE'),
                'loc' => (int) $locId,
                'ativacao' => ['dadosJornada' => ['txid' => (string) ($initialCharge['txid'] ?? $initialTxid)]],
            ]);
        } catch (EfiException $e) {
            GatewayLog::error(self::GATEWAY, $this->gatewayParams, 'Falha ao iniciar Pix Automático (' . $e->code . ')', [
                'invoice' => $invoiceId,
                'error' => $e->errorDescription,
            ]);
            throw new \RuntimeException('Não foi possível iniciar o Pix Automático: ' . $e->errorDescription);
        }

        $efiRecurrenceId = (string) ($response['idRec'] ?? '');
        if ($efiRecurrenceId === '') {
            throw new \RuntimeException('A Efí não retornou o identificador da recorrência Pix Automático.');
        }

        $detail = $this->api->pixDetailRecurrenceAutomatic([
            'idRec' => $efiRecurrenceId,
            'txid' => (string) ($initialCharge['txid'] ?? $initialTxid),
        ]);
        $copyPaste = (string) ($detail['dadosQR']['pixCopiaECola'] ?? '');

        if ($copyPaste === '') {
            throw new \RuntimeException('A Efí não retornou o Pix copia e cola da autorização.');
        }

        $metadata = json_encode(['copy_paste' => $copyPaste]) ?: '{}';
        Repository::updateRecurrence((int) $recurrence->id, [
            'efi_recurrence_id' => $efiRecurrenceId,
            'loc_id' => $locId,
            'status' => (string) ($response['status'] ?? 'CRIADA'),
            'metadata' => $metadata,
        ]);

        return ['copy_paste' => $copyPaste, 'status' => (string) ($response['status'] ?? 'CRIADA')];
    }

    /**
     * Agenda a CobR de uma fatura, OU adia para quando ela estiver dentro da janela aceita pelo
     * Bacen (o chamador -- hook `InvoiceCreated` ou o cron -- decide se tenta de novo depois).
     * Reaproveita uma cobrança anterior que ficou `error` em vez de deixá-la travada para sempre.
     */
    public function scheduleInvoice(object $invoice): ?string
    {
        $recurrence = Repository::findRecurrenceByClient((int) $invoice->userid);
        if ($recurrence === null || $recurrence->efi_recurrence_id === null || $recurrence->status !== 'APROVADA') {
            return null;
        }
        $amountCents = (int) round(((float) $invoice->total) * 100);
        // Antes havia uma checagem aqui recusando faturas com valor menor que o da autorização
        // inicial, por interpretar `valorMinimoRecebedor` como um piso imposto pelo recebedor.
        // Pelo spec do Bacen, esse campo é o contrário: um teto que o PAGADOR configura no
        // banco dele para o valor MÁXIMO que aceita ser debitado automaticamente -- não um
        // mínimo que o recebedor possa exigir por CobR. Removido; se algum valor realmente
        // violar uma regra do PSP pagador, a CobR volta com status=error e cai no fluxo de
        // retentativa já existente (ver `retryErroredCharge`).

        $existing = Repository::findChargeByInvoice((int) $invoice->id);
        if ($existing !== null && $existing->status !== 'error') {
            return null;
        }

        if (!self::isWithinSchedulingWindow((string) $invoice->duedate)) {
            return 'Pix Automático adiado: o Bacen exige que a instrução de uma cobrança recorrente chegue ao banco do pagador entre 2 e 10 dias antes do vencimento (FAQ Pix Automático, Bacen); o cron tentará novamente dentro dessa janela.';
        }

        if ($existing !== null) {
            // Retentativa de uma CobR que ficou `error`: descarta a linha antiga e recria com o
            // MESMO txid (determinístico por fatura), para que uma remota já criada na tentativa
            // anterior seja recuperada por `createOrFetchAutomaticCharge()` em vez de duplicada.
            Repository::deleteCharge((int) $existing->id);
        }

        return $this->createRecurringCharge($recurrence, $invoice, $amountCents);
    }

    /**
     * Reprocessa uma CobR que ficou `error` na última tentativa, sem esperar que o cron
     * reencontre a fatura pela janela de agendamento (ela pode já estar dentro da janela).
     */
    public function retryErroredCharge(object $charge): ?string
    {
        $invoice = Capsule::table('tblinvoices')
            ->where('id', $charge->invoice_id)
            ->where('status', 'Unpaid')
            ->first(['id', 'userid', 'total', 'duedate']);

        if ($invoice === null) {
            return null;
        }

        return $this->scheduleInvoice($invoice);
    }

    private function createRecurringCharge(object $recurrence, object $invoice, int $amountCents): ?string
    {
        $txid = self::txid('charge', (int) $invoice->id);

        try {
            $chargeId = Repository::createCharge([
                'recurrence_id' => (int) $recurrence->id,
                'invoice_id' => (int) $invoice->id,
                'efi_charge_id' => $txid,
                'status' => 'creating',
                'amount_cents' => $amountCents,
                'due_date' => $invoice->duedate,
                'metadata' => '{}',
            ]);
        } catch (\Exception) {
            return null; // corrida: outro processo já criou a cobrança desta fatura.
        }

        try {
            $response = $this->createOrFetchAutomaticCharge($txid, [
                'idRec' => $recurrence->efi_recurrence_id,
                'infoAdicional' => 'Fatura WHMCS #' . $invoice->id,
                'calendario' => ['dataDeVencimento' => $invoice->duedate],
                'valor' => ['original' => self::formatAmount($amountCents)],
                'ajusteDiaUtil' => true,
                // Bacen marca `recebedor` (agencia/conta/tipoConta) como obrigatório em
                // `CobRSolicitada`, o corpo de cada CobR individual. [INFERENCE: confirmar em
                // sandbox antes de reativar para cliente real -- não há como testar sem
                // credenciais nesta sessão.]
                'recebedor' => [
                    'agencia' => trim((string) ($this->gatewayParams['recebedor_agencia'] ?? '')),
                    'conta' => trim((string) ($this->gatewayParams['recebedor_conta'] ?? '')),
                    'tipoConta' => (string) ($this->gatewayParams['recebedor_tipo_conta'] ?? 'CORRENTE'),
                ],
            ]);
        } catch (EfiException $e) {
            GatewayLog::error(self::GATEWAY, $this->gatewayParams, 'Falha ao criar cobrança recorrente (' . $e->code . ')', [
                'invoice' => $invoice->id,
                'txid' => $txid,
                'error' => $e->errorDescription,
            ]);
            Repository::updateCharge($chargeId, ['status' => 'error']);

            return 'Não foi possível agendar o Pix Automático na Efí: ' . $e->errorDescription . ' (nova tentativa automática no próximo cron)';
        }

        Repository::updateCharge($chargeId, ['status' => (string) ($response['status'] ?? 'CRIADA')]);

        return 'Cobrança Pix Automático agendada na Efí.';
    }

    /**
     * Mesmo padrão de `createOrFetchInitialCharge()`: um 409 (txid já existe) nunca é erro --
     * é exatamente o caso de uma tentativa anterior que criou a CobR na Efí antes de falhar
     * localmente (timeout, queda do processo). Buscar em vez de recriar evita CobR duplicada.
     */
    private function createOrFetchAutomaticCharge(string $txid, array $body): array
    {
        try {
            return $this->api->pixCreateAutomaticChargeTxid(['txid' => $txid], $body);
        } catch (EfiException $e) {
            if ((int) $e->code !== 409 && !self::isTxidAlreadyUsedError($e)) {
                throw $e;
            }

            return $this->api->pixDetailAutomaticCharge(['txid' => $txid]);
        }
    }

    /**
     * O Bacen também recusa com 400 (não só 409) quando o txid já está em uso para a mesma
     * CobR. [INFERENCE: o nome/texto exato do erro da Efí para esse caso específico não pôde
     * ser confirmado nesta sessão sem sandbox -- só trata como "já existe" quando a descrição
     * menciona txid E algo como "utilizad"/"existe"/"already used", nunca um 400 genérico, que
     * continua sendo relançado como erro real.]
     */
    private static function isTxidAlreadyUsedError(EfiException $e): bool
    {
        if ((int) $e->code !== 400) {
            return false;
        }

        $description = mb_strtolower((string) $e->errorDescription);

        return str_contains($description, 'txid')
            && (str_contains($description, 'utilizad') || str_contains($description, 'existe') || str_contains($description, 'already'));
    }

    /**
     * Janela que o Bacen exige para o agendamento de uma cobrança recorrente: a instrução
     * precisa chegar ao PSP pagador entre 2 e 10 dias antes do vencimento (FAQ Pix Automático
     * do Banco Central, "prazo entre 2 e 10 dias para que a instrução de pagamento seja
     * recebida pelo PSP pagador"). Fora dessa janela a Efí rejeita a criação da CobR.
     */
    public static function isWithinSchedulingWindow(string $dueDate): bool
    {
        $start = date('Y-m-d', strtotime('+2 days'));
        $end = date('Y-m-d', strtotime('+10 days'));

        return $dueDate >= $start && $dueDate <= $end;
    }

    public function cancelInvoiceCharge(int $invoiceId): void
    {
        $charge = Repository::findChargeByInvoice($invoiceId);
        if ($charge !== null && in_array($charge->status, ['CRIADA', 'ATIVA'], true)) {
            $response = $this->api->pixUpdateAutomaticCharge(['txid' => $charge->efi_charge_id], ['status' => 'CANCELADA']);
            Repository::updateCharge((int) $charge->id, ['status' => (string) ($response['status'] ?? 'CANCELADA')]);
        }

        $recurrence = Repository::findRecurrenceByInitialInvoice($invoiceId);
        if ($recurrence === null || $recurrence->efi_recurrence_id === null || $recurrence->status !== 'CRIADA') {
            return;
        }

        $this->api->pixUpdateCharge(['txid' => $recurrence->initial_txid], ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
        $response = $this->api->pixUpdateRecurrenceAutomatic(['idRec' => $recurrence->efi_recurrence_id], ['status' => 'CANCELADA']);
        Repository::updateRecurrence((int) $recurrence->id, ['status' => (string) ($response['status'] ?? 'CANCELADA')]);
    }

    public function notificationUrl(): string
    {
        return rtrim((string) $this->gatewayParams['systemurl'], '/') . '/modules/gateways/callback/efi_pix.php?ws=' . urlencode((string) $this->gatewayParams['webhook_secret']) . '&ignorar=';
    }

    public function recurrenceNotificationUrl(): string
    {
        return rtrim((string) $this->gatewayParams['systemurl'], '/') . '/modules/gateways/callback/efi_pix_automatico.php?ws=' . urlencode((string) $this->gatewayParams['webhook_secret']) . '&ignorar=';
    }

    public static function txid(string $prefix, int $invoiceId): string
    {
        return substr($prefix . hash('sha256', 'efi-pix-auto:' . $invoiceId), 0, 35);
    }

    private function createOrFetchInitialCharge(string $txid, array $body): array
    {
        try {
            return $this->api->pixCreateCharge(['txid' => $txid], $body);
        } catch (EfiException $e) {
            if ((int) $e->code !== 409 && !self::isTxidAlreadyUsedError($e)) {
                throw $e;
            }

            return $this->api->pixDetailCharge(['txid' => $txid]);
        }
    }

    private function presentExisting(object $recurrence): array
    {
        $metadata = json_decode((string) $recurrence->metadata, true) ?: [];
        $copyPaste = (string) ($metadata['copy_paste'] ?? '');

        if ($copyPaste === '') {
            throw new \RuntimeException('A autorização Pix Automático já existe, mas o copia e cola não foi localizado.');
        }

        return ['copy_paste' => $copyPaste, 'status' => $recurrence->status];
    }

    private function initialExpirationSeconds(): int
    {
        return max(1, (int) ($this->gatewayParams['initial_expiration_days'] ?? 1)) * 86400;
    }

    private function periodicity(): string
    {
        $periodicity = (string) ($this->gatewayParams['periodicity'] ?? 'MENSAL');

        return in_array($periodicity, ['SEMANAL', 'MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL'], true)
            ? $periodicity
            : 'MENSAL';
    }

    private function firstRecurringDate(string $invoiceDueDate): string
    {
        $date = new \DateTimeImmutable($invoiceDueDate);
        $interval = match ($this->periodicity()) {
            'SEMANAL' => new \DateInterval('P1W'),
            'TRIMESTRAL' => new \DateInterval('P3M'),
            'SEMESTRAL' => new \DateInterval('P6M'),
            'ANUAL' => new \DateInterval('P1Y'),
            default => new \DateInterval('P1M'),
        };

        $result = $date->add($interval)->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');

        // Uma fatura inicial já vencida há mais de um ciclo (ou um ciclo muito curto) pode
        // gerar uma `dataInicial` no passado/hoje; o Bacen exige data futura.
        return $result > $tomorrow ? $result : $tomorrow;
    }

    private static function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }
}
