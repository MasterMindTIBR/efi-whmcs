<?php

namespace EfiWhmcs\Pix;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;

/**
 * Regras de negócio do Pix: cobrança imediata + QR code, sempre renderizada inline na página da
 * fatura (nunca popup/redirecionamento). Sem HTML/HTTP aqui -- isso fica em
 * modules/gateways/efi_pix.php.
 */
final class PixService
{
    public const RAIL = 'pix';

    public function __construct(
        private readonly EfiPay $api,
        private readonly array $gatewayParams
    ) {
    }

    public function issueOrFetch(
        int $invoiceId,
        int $totalAmountCents,
        string $customerName,
        string $documentDigitsOnly
    ): array {
        $existing = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($existing !== null) {
            // Pix imediato NUNCA transiciona o status sozinho quando expira (diferente de
            // boleto/CobR): uma cobrança `ATIVA` cujo `calendario.expiracao` já passou continua
            // `ATIVA` para sempre na Efí, só que não pode mais ser paga. Por isso rastreamos
            // `expires_at` (calculado na emissão) no nosso próprio metadata -- é a ÚNICA forma
            // de detectar a expiração e renovar automaticamente ao reabrir a fatura.
            if (($existing->status === 'ATIVA' && $this->isExpiredLocally($existing))
                || str_starts_with((string) $existing->status, 'REMOVIDA_')) {
                return $this->regenerate($invoiceId, $totalAmountCents, $customerName, $documentDigitsOnly);
            }

            return $this->presentExisting($existing);
        }

        return $this->issue($invoiceId, $totalAmountCents, $customerName, $documentDigitsOnly);
    }

    private function isExpiredLocally(object $charge): bool
    {
        $metadata = json_decode((string) $charge->metadata, true) ?: [];
        $expiresAt = isset($metadata['expires_at']) ? (int) $metadata['expires_at'] : null;

        return $expiresAt !== null && time() > $expiresAt;
    }

    /**
     * Revisa o valor da cobrança ativa depois de uma alteração de total da fatura. Pix imediato
     * não tem vencimento de fatura para realinhar; quando expirado, deve ser renovado pelo admin.
     */
    public function synchronizeAmount(int $invoiceId, int $totalAmountCents): ?string
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($charge === null || $charge->status !== 'ATIVA') {
            return null;
        }

        try {
            $detail = $this->api->pixDetailCharge(['txid' => $charge->efi_charge_id]);
        } catch (EfiException $e) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao consultar Pix antes de revisar valor (' . $e->code . ')', [
                'error' => $e->errorDescription,
                'invoice' => $invoiceId,
                'txid' => $charge->efi_charge_id,
            ]);

            return 'Não foi possível confirmar o estado da cobrança Pix antes de atualizá-la.';
        }

        $status = (string) ($detail['status'] ?? $charge->status);

        if ($status === 'CONCLUIDA') {
            $this->logPaidOutsideBillingCycle($invoiceId, (string) $charge->efi_charge_id, $charge, $detail);

            return "Cobrança Pix {$charge->efi_charge_id} já foi concluída; aguardando reconciliação do pagamento.";
        }

        if ($status !== 'ATIVA') {
            ChargeRepository::updateStatus($charge->id, $status);

            return "Cobrança Pix {$charge->efi_charge_id} está com status '{$status}'; valor não revisado.";
        }

        $metadata = json_decode((string) $charge->metadata, true) ?: [];
        $discountPercent = isset($metadata['discount_percent'])
            ? (float) $metadata['discount_percent']
            : (float) ($this->gatewayParams['discount_percent'] ?? 0);
        $amountCents = $this->finalAmountCents($totalAmountCents, $discountPercent);

        if ((int) $charge->amount_cents === $amountCents) {
            return null;
        }

        try {
            $updated = $this->api->pixUpdateCharge(
                ['txid' => $charge->efi_charge_id],
                ['valor' => ['original' => $this->formatAmount($amountCents)]]
            );
        } catch (EfiException $e) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao revisar valor da cobrança Pix (' . $e->code . ')', [
                'error' => $e->errorDescription,
                'invoice' => $invoiceId,
                'txid' => $charge->efi_charge_id,
            ]);

            return 'Não foi possível atualizar o valor da cobrança Pix na Efí: ' . $e->errorDescription;
        }

        $locId = (string) ($updated['loc']['id'] ?? $charge->efi_loc_id ?? '');
        $qr = $this->generateQrCode($locId);
        $expiresAt = $this->expiresAtFromCalendario($detail['calendario'] ?? null)
            ?? (isset($metadata['expires_at']) ? (int) $metadata['expires_at'] : null);
        $updatedStatus = (string) ($updated['status'] ?? 'ATIVA');

        if ($updatedStatus === 'CONCLUIDA') {
            $this->logPaidOutsideBillingCycle($invoiceId, (string) $charge->efi_charge_id, $charge, $updated);

            return "Cobrança Pix {$charge->efi_charge_id} foi concluída durante a revisão; aguardando reconciliação do pagamento.";
        }

        ChargeRepository::replacePixCharge(
            $charge->id,
            $charge->efi_charge_id,
            $locId !== '' ? $locId : null,
            $updatedStatus,
            $amountCents,
            $this->metadata($totalAmountCents, $discountPercent, $qr['qrcode'] ?? null, $qr['imagemQrcode'] ?? null, $expiresAt)
        );

        return "Valor da cobrança Pix {$charge->efi_charge_id} atualizado para R$ " . number_format($amountCents / 100, 2, ',', '.') . '.';
    }

    /**
     * Desativa a cobrança ativa, se houver, e cria uma nova com a expiração contada a partir de
     * agora. Cobranças concluídas nunca são renovadas.
     */
    public function regenerate(
        int $invoiceId,
        int $totalAmountCents,
        string $customerName,
        string $documentDigitsOnly
    ): array {
        $existing = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($existing !== null) {
            try {
                $detail = $this->api->pixDetailCharge(['txid' => $existing->efi_charge_id]);
            } catch (EfiException $e) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao consultar Pix antes de renovar (' . $e->code . ')', [
                    'error' => $e->errorDescription,
                    'invoice' => $invoiceId,
                    'txid' => $existing->efi_charge_id,
                ]);

                throw new \RuntimeException('Não foi possível confirmar o estado da cobrança Pix atual.');
            }

            $status = (string) ($detail['status'] ?? $existing->status);

            if ($status === 'CONCLUIDA') {
                $this->logPaidOutsideBillingCycle($invoiceId, (string) $existing->efi_charge_id, $existing, $detail);
                throw new \RuntimeException('A cobrança Pix já foi concluída; não é possível gerar uma nova para esta fatura.');
            }

            if ($status === 'ATIVA') {
                try {
                    $removed = $this->api->pixUpdateCharge(
                        ['txid' => $existing->efi_charge_id],
                        ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']
                    );
                } catch (EfiException $e) {
                    GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao desativar Pix antes de renovar (' . $e->code . ')', [
                        'error' => $e->errorDescription,
                        'invoice' => $invoiceId,
                        'txid' => $existing->efi_charge_id,
                    ]);

                    throw new \RuntimeException('Não foi possível desativar a cobrança Pix atual: ' . $e->errorDescription);
                }

                $status = (string) ($removed['status'] ?? 'REMOVIDA_PELO_USUARIO_RECEBEDOR');
                if ($status === 'CONCLUIDA') {
                    $this->logPaidOutsideBillingCycle($invoiceId, (string) $existing->efi_charge_id, $existing, $removed);
                    throw new \RuntimeException('A cobrança Pix foi concluída durante a renovação; aguardando reconciliação do pagamento.');
                }
            }

            ChargeRepository::updateStatus($existing->id, $status);
        }

        return $this->issue($invoiceId, $totalAmountCents, $customerName, $documentDigitsOnly, true);
    }

    private function issue(
        int $invoiceId,
        int $totalAmountCents,
        string $customerName,
        string $documentDigitsOnly,
        bool $reissue = false
    ): array {
        $discountPercent = (float) ($this->gatewayParams['discount_percent'] ?? 0);
        $finalAmountCents = $this->finalAmountCents($totalAmountCents);
        $expirationDays = max(1, (int) ($this->gatewayParams['expiration_days'] ?? 1));
        $customer = ['nome' => mb_substr($customerName, 0, 200)];
        $customer[CustomerResolver::documentKey($documentDigitsOnly)] = $documentDigitsOnly;
        $body = [
            'calendario' => ['expiracao' => $expirationDays * 86400],
            'devedor' => $customer,
            'valor' => ['original' => $this->formatAmount($finalAmountCents)],
            'chave' => trim((string) ($this->gatewayParams['pix_key'] ?? '')),
            'solicitacaoPagador' => 'Fatura #' . $invoiceId,
            'infoAdicionais' => [
                ['nome' => 'Fatura', 'valor' => '#' . $invoiceId],
            ],
        ];

        try {
            $charge = $this->api->pixCreateImmediateCharge([], $body);
            $locId = (string) ($charge['loc']['id'] ?? '');
            $qr = $this->generateQrCode($locId);
        } catch (EfiException $e) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao criar cobrança Pix (' . $e->code . ')', [
                'error' => $e->error,
                'description' => $e->errorDescription,
                'invoice' => $invoiceId,
            ]);

            throw new \RuntimeException('Não foi possível gerar a cobrança Pix: ' . $e->errorDescription);
        }

        $txid = (string) ($charge['txid'] ?? '');

        if ($txid === '') {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Resposta sem txid', $charge);
            throw new \RuntimeException('A Efí não retornou um txid válido para a cobrança Pix.');
        }

        $status = (string) ($charge['status'] ?? 'ATIVA');
        $expiresAt = $this->expiresAtFromCalendario($charge['calendario'] ?? null);
        $metadata = $this->metadata($totalAmountCents, $discountPercent, $qr['qrcode'] ?? null, $qr['imagemQrcode'] ?? null, $expiresAt);

        if (!$reissue) {
            $localId = ChargeRepository::create([
                'invoice_id' => $invoiceId,
                'rail' => self::RAIL,
                'efi_charge_id' => $txid,
                'efi_loc_id' => $locId !== '' ? $locId : null,
                'status' => $status,
                'amount_cents' => $finalAmountCents,
                'metadata' => $metadata,
            ]);
        } else {
            $localId = ChargeRepository::reissue($invoiceId, self::RAIL, [
                'efi_charge_id' => $txid,
                'efi_loc_id' => $locId !== '' ? $locId : null,
                'status' => $status,
                'amount_cents' => $finalAmountCents,
                'metadata' => $metadata,
            ]);
        }

        GatewayLog::debug('efi_pix', $this->gatewayParams, 'Cobrança Pix criada', $charge);

        return [
            'txid' => $txid,
            'status' => $status,
            'qr_image' => $qr['imagemQrcode'] ?? null,
            'copy_paste' => $qr['qrcode'] ?? null,
            'amount_cents' => $finalAmountCents,
            'local_id' => $localId,
        ];
    }

    private function presentExisting(object $charge): array
    {
        $qr = $this->generateQrCode((string) ($charge->efi_loc_id ?? ''));
        $metadata = json_decode((string) $charge->metadata, true) ?: [];

        return [
            'txid' => $charge->efi_charge_id,
            'status' => $charge->status,
            'qr_image' => $qr['imagemQrcode'] ?? null,
            'copy_paste' => $qr['qrcode'] ?? $metadata['copy_paste'] ?? null,
            'amount_cents' => $charge->amount_cents,
            'local_id' => $charge->id,
            'existing' => true,
        ];
    }

    private function generateQrCode(string $locId): array
    {
        if ($locId === '') {
            return [];
        }

        try {
            return $this->api->pixGenerateQRCode(['id' => $locId]);
        } catch (EfiException $e) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao recuperar QR code (' . $e->code . ')', $e->errorDescription);

            return [];
        }
    }

    private function finalAmountCents(int $totalAmountCents, ?float $discountPercent = null): int
    {
        $discountPercent ??= (float) ($this->gatewayParams['discount_percent'] ?? 0);

        return $discountPercent > 0
            ? (int) round($totalAmountCents * (1 - $discountPercent / 100))
            : $totalAmountCents;
    }

    private function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    private function metadata(int $baseAmountCents, float $discountPercent, ?string $copyPaste, ?string $qrImage, ?int $expiresAt = null): string
    {
        return json_encode([
            'base_amount_cents' => $baseAmountCents,
            'discount_percent' => $discountPercent,
            'copy_paste' => $copyPaste,
            'qr_image' => $qrImage,
            'expires_at' => $expiresAt,
        ]) ?: '{}';
    }

    /**
     * `calendario.criacao` (ISO 8601) + `calendario.expiracao` (segundos) vêm tanto na criação
     * quanto na consulta da cobrança -- são a única fonte confiável de quando ela expira,
     * já que o campo `status` não muda sozinho quando isso acontece.
     */
    private function expiresAtFromCalendario(?array $calendario): ?int
    {
        if (!isset($calendario['criacao'], $calendario['expiracao'])) {
            return null;
        }

        $createdAt = strtotime((string) $calendario['criacao']);

        if ($createdAt === false) {
            return null;
        }

        return $createdAt + (int) $calendario['expiracao'];
    }

    public function notificationUrl(string $webhookSecret): string
    {
        $systemUrl = rtrim((string) ($this->gatewayParams['systemurl'] ?? ''), '/');

        // `ignorar=` impede a Efí de acrescentar `/pix` ao final da URL cadastrada (ver doc
        // oficial de webhooks Pix); `ws` é nossa camada extra de validação por segredo na URL.
        return $systemUrl . '/modules/gateways/callback/efi_pix.php?ws=' . urlencode($webhookSecret) . '&ignorar=';
    }

    /**
     * Quando `discount_percent` está configurado, o valor realmente recebido via Pix é MENOR
     * que o total da fatura no WHMCS. Creditar só esse valor com `addInvoicePayment()` deixaria
     * a fatura com saldo residual (o desconto) e ela NUNCA seria marcada como paga. Em vez
     * disso, lança o desconto como um item de fatura NEGATIVO (`UpdateInvoice`), reduzindo o
     * total da fatura para bater exatamente com o valor cobrado -- só então o pagamento
     * integral quita a fatura. Idempotente: só chamar uma vez por cobrança (ver callback, que
     * guarda por `charge->status`); chamar de novo duplicaria o desconto.
     */
    public function applyDiscountLineItem(int $invoiceId, int $receivedAmountCents): void
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($charge === null) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Pagamento Pix sem cobrança local para calcular desconto; revisão manual necessária', [
                'invoice_id' => $invoiceId,
                'received_amount_cents' => $receivedAmountCents,
            ]);
            return;
        }

        $metadata = json_decode((string) $charge->metadata, true) ?: [];
        $discountCents = self::discountCentsForPayment($metadata, (int) $charge->amount_cents, $receivedAmountCents);

        if ($discountCents === null) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Valor Pix confirmado diverge da cobrança esperada; pagamento será aplicado sem desconto automático', [
                'invoice_id' => $invoiceId,
                'charge_id' => $charge->id,
                'expected_amount_cents' => (int) $charge->amount_cents,
                'received_amount_cents' => $receivedAmountCents,
            ]);
            return;
        }

        if ($discountCents <= 0) {
            return;
        }

        $results = localAPI('UpdateInvoice', [
            'invoiceid' => $invoiceId,
            'newitemdescription' => ['Desconto Pix'],
            'newitemamount' => [-Money::toReais($discountCents)],
            'newitemtaxed' => [false],
        ], null);

        if (($results['result'] ?? '') !== 'success') {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao lançar item de desconto Pix na fatura', [
                'invoice_id' => $invoiceId,
                'result' => $results,
            ]);
        }
    }

    /**
     * Returns null when the authoritative received amount does not match the issued charge.
     * The base is durable issuance metadata, never a later invoice-total subtraction.
     */
    public static function discountCentsForPayment(array $metadata, int $expectedAmountCents, int $receivedAmountCents): ?int
    {
        if ($receivedAmountCents !== $expectedAmountCents
            || !isset($metadata['base_amount_cents'], $metadata['discount_percent'])) {
            return null;
        }

        $discountPercent = (float) $metadata['discount_percent'];
        if ($discountPercent <= 0) {
            return 0;
        }

        return (int) round((int) $metadata['base_amount_cents'] * $discountPercent / 100);
    }

    /** The only lifecycle path allowed to persist a confirmed Pix payment is PaymentApplier. */
    private function logPaidOutsideBillingCycle(int $invoiceId, string $txid, object $charge, array $detail): void
    {
        GatewayLog::error('efi_pix', $this->gatewayParams, 'Pix pago em fatura fora do ciclo de cobrança — revisão manual', [
            'invoice_id' => $invoiceId,
            'txid' => $txid,
            'charge_id' => $charge->id,
            'local_status' => $charge->status,
            'provider_status' => $detail['status'] ?? null,
        ]);
    }

    /** Bacen permits 1–35 alphanumeric chars; the suffix remains intact for large local ids. */
    public static function refundId(int $chargeId, int $attempt): string
    {
        $suffix = 'n' . max(1, $attempt);

        return substr('whmcsrefund' . $chargeId, 0, 35 - strlen($suffix)) . $suffix;
    }

    /** Cancels only an open local Pix after Efí authoritatively confirms it is still open. */
    public function cancelInvoiceCharge(int $invoiceId): void
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);
        if ($charge === null || $charge->status !== 'ATIVA') {
            return;
        }

        try {
            $detail = $this->api->pixDetailCharge(['txid' => $charge->efi_charge_id]);
        } catch (EfiException $e) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao consultar Pix antes de cancelar (' . $e->code . ')', [
                'error' => $e->errorDescription, 'invoice' => $invoiceId, 'txid' => $charge->efi_charge_id,
            ]);
            return;
        }

        $status = (string) ($detail['status'] ?? $charge->status);
        if ($status === 'CONCLUIDA') {
            $this->logPaidOutsideBillingCycle($invoiceId, (string) $charge->efi_charge_id, $charge, $detail);
            return;
        }

        if ($status !== 'ATIVA') {
            ChargeRepository::updateStatus((int) $charge->id, $status);
            return;
        }

        try {
            // Bacen rejects a removal body carrying any field other than status.
            $removed = $this->api->pixUpdateCharge(['txid' => $charge->efi_charge_id], ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
            $removedStatus = (string) ($removed['status'] ?? 'REMOVIDA_PELO_USUARIO_RECEBEDOR');
            if ($removedStatus === 'CONCLUIDA') {
                $this->logPaidOutsideBillingCycle($invoiceId, (string) $charge->efi_charge_id, $charge, $removed);
                return;
            }
            ChargeRepository::updateStatus((int) $charge->id, $removedStatus);
        } catch (EfiException $e) {
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao cancelar cobrança Pix (' . $e->code . ')', [
                'error' => $e->errorDescription, 'invoice' => $invoiceId, 'txid' => $charge->efi_charge_id,
            ]);
        }
    }
}
