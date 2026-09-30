<?php

namespace EfiWhmcs\PixAutomatic;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\GatewayLog;

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
                'valor' => ['valorRec' => self::formatAmount($amountCents)],
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

    public function scheduleInvoice(object $invoice): ?string
    {
        $recurrence = Repository::findRecurrenceByClient((int) $invoice->userid);
        if ($recurrence === null || $recurrence->efi_recurrence_id === null || $recurrence->status !== 'APROVADA') {
            return null;
        }

        $amountCents = (int) round(((float) $invoice->total) * 100);
        if ($amountCents !== (int) $recurrence->amount_cents) {
            return 'Pix Automático não agendado: a fatura possui valor diferente da autorização original; solicite uma nova autorização ao cliente.';
        }

        $existing = Repository::findChargeByInvoice((int) $invoice->id);
        if ($existing !== null) {
            return null;
        }

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
            return null;
        }

        try {
            $response = $this->api->pixCreateAutomaticChargeTxid(['txid' => $txid], [
                'idRec' => $recurrence->efi_recurrence_id,
                'infoAdicional' => 'Fatura WHMCS #' . $invoice->id,
                'calendario' => ['dataDeVencimento' => $invoice->duedate],
                'valor' => ['original' => self::formatAmount($amountCents)],
                'ajusteDiaUtil' => true,
            ]);
        } catch (EfiException $e) {
            GatewayLog::error(self::GATEWAY, $this->gatewayParams, 'Falha ao criar cobrança recorrente (' . $e->code . ')', [
                'invoice' => $invoice->id,
                'txid' => $txid,
                'error' => $e->errorDescription,
            ]);
            Repository::updateCharge($chargeId, ['status' => 'error']);
            return 'Não foi possível agendar o Pix Automático na Efí: ' . $e->errorDescription;
        }

        Repository::updateCharge($chargeId, ['status' => (string) ($response['status'] ?? 'CRIADA')]);
        return 'Cobrança Pix Automático agendada na Efí.';
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
            if ((int) $e->code !== 409) {
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

        return $date->add($interval)->format('Y-m-d');
    }

    private static function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }
}
