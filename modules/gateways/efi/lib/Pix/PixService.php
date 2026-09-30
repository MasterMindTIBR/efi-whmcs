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
            return $this->presentExisting($existing);
        }

        return $this->issue($invoiceId, $totalAmountCents, $customerName, $documentDigitsOnly);
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

        ChargeRepository::replacePixCharge(
            $charge->id,
            $charge->efi_charge_id,
            $locId !== '' ? $locId : null,
            (string) ($updated['status'] ?? 'ATIVA'),
            $amountCents,
            $this->metadata($discountPercent, $qr['qrcode'] ?? null)
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
                ChargeRepository::updateStatus($existing->id, $status);
                throw new \RuntimeException('A cobrança Pix já foi concluída; não é possível gerar uma nova para esta fatura.');
            }

            if ($status === 'ATIVA') {
                try {
                    $this->api->pixUpdateCharge(
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

                $status = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';
            }

            ChargeRepository::updateStatus($existing->id, $status);
        }

        return $this->issue($invoiceId, $totalAmountCents, $customerName, $documentDigitsOnly, $existing);
    }

    private function issue(
        int $invoiceId,
        int $totalAmountCents,
        string $customerName,
        string $documentDigitsOnly,
        ?object $replacedCharge = null
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
        $metadata = $this->metadata($discountPercent, $qr['qrcode'] ?? null);

        if ($replacedCharge === null) {
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
            $localId = (int) $replacedCharge->id;
            ChargeRepository::replacePixCharge(
                $localId,
                $txid,
                $locId !== '' ? $locId : null,
                $status,
                $finalAmountCents,
                $metadata
            );
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

    private function metadata(float $discountPercent, ?string $copyPaste): string
    {
        return json_encode([
            'discount_percent' => $discountPercent,
            'copy_paste' => $copyPaste,
        ]) ?: '{}';
    }

    public function notificationUrl(string $webhookSecret): string
    {
        $systemUrl = rtrim((string) ($this->gatewayParams['systemurl'] ?? ''), '/');

        // `ignorar=` impede a Efí de acrescentar `/pix` ao final da URL cadastrada (ver doc
        // oficial de webhooks Pix); `ws` é nossa camada extra de validação por segredo na URL.
        return $systemUrl . '/modules/gateways/callback/efi_pix.php?ws=' . urlencode($webhookSecret) . '&ignorar=';
    }
}
