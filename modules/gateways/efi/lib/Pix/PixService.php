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

        if ($existing !== null && !in_array($existing->status, ['CONCLUIDA', 'REMOVIDA_PELO_USUARIO_RECEBEDOR'], true)) {
            return $this->presentExisting($existing);
        }

        $discountPercent = (float) ($this->gatewayParams['discount_percent'] ?? 0);
        $finalAmountCents = $discountPercent > 0
            ? (int) round($totalAmountCents * (1 - $discountPercent / 100))
            : $totalAmountCents;

        $expirationDays = max(1, (int) ($this->gatewayParams['expiration_days'] ?? 1));

        $customer = ['nome' => mb_substr($customerName, 0, 200)];
        $customer[CustomerResolver::documentKey($documentDigitsOnly)] = $documentDigitsOnly;

        $body = [
            'calendario' => ['expiracao' => $expirationDays * 86400],
            'devedor' => $customer,
            'valor' => ['original' => number_format($finalAmountCents / 100, 2, '.', '')],
            'chave' => trim((string) ($this->gatewayParams['pix_key'] ?? '')),
            'solicitacaoPagador' => 'Fatura #' . $invoiceId,
            'infoAdicionais' => [
                ['nome' => 'Fatura', 'valor' => '#' . $invoiceId],
            ],
        ];

        try {
            $charge = $this->api->pixCreateImmediateCharge([], $body);
            $locId = (string) ($charge['loc']['id'] ?? '');
            $qr = $locId !== '' ? $this->api->pixGenerateQRCode(['id' => $locId]) : [];
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

        $localId = ChargeRepository::create([
            'invoice_id' => $invoiceId,
            'rail' => self::RAIL,
            'efi_charge_id' => $txid,
            'efi_loc_id' => $locId !== '' ? $locId : null,
            'status' => (string) ($charge['status'] ?? 'ATIVA'),
            'amount_cents' => $finalAmountCents,
            'metadata' => json_encode([
                'discount_percent' => $discountPercent,
                'copy_paste' => $qr['qrcode'] ?? null,
            ]),
        ]);

        GatewayLog::debug('efi_pix', $this->gatewayParams, 'Cobrança Pix criada', $charge);

        return [
            'txid' => $txid,
            'status' => (string) ($charge['status'] ?? 'ATIVA'),
            'qr_image' => $qr['imagemQrcode'] ?? null,
            'copy_paste' => $qr['qrcode'] ?? null,
            'amount_cents' => $finalAmountCents,
            'local_id' => $localId,
        ];
    }

    private function presentExisting(object $charge): array
    {
        $qr = [];

        if ($charge->efi_loc_id) {
            try {
                $qr = $this->api->pixGenerateQRCode(['id' => $charge->efi_loc_id]);
            } catch (EfiException $e) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao recuperar QR code existente (' . $e->code . ')', $e->errorDescription);
            }
        }

        return [
            'txid' => $charge->efi_charge_id,
            'status' => $charge->status,
            'qr_image' => $qr['imagemQrcode'] ?? null,
            'copy_paste' => $qr['qrcode'] ?? null,
            'amount_cents' => $charge->amount_cents,
            'local_id' => $charge->id,
            'existing' => true,
        ];
    }

    public function notificationUrl(string $webhookSecret): string
    {
        $systemUrl = rtrim((string) ($this->gatewayParams['systemurl'] ?? ''), '/');

        // `ignorar=` impede a Efí de acrescentar `/pix` ao final da URL cadastrada (ver doc
        // oficial de webhooks Pix); `ws` é nossa camada extra de validação por segredo na URL.
        return $systemUrl . '/modules/gateways/callback/efi_pix.php?ws=' . urlencode($webhookSecret) . '&ignorar=';
    }
}
