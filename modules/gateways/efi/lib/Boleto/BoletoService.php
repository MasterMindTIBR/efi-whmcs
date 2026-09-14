<?php

namespace EfiWhmcs\Boleto;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\DueDate;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;

/**
 * Regras de negócio do boleto: emissão one-step, realinhamento de vencimento e cancelamento.
 * Sem HTML/HTTP aqui -- isso fica em modules/gateways/efi_boleto.php.
 */
final class BoletoService
{
    public const RAIL = 'boleto';

    public function __construct(
        private readonly EfiPay $api,
        private readonly array $gatewayParams
    ) {
    }

    /**
     * Emite (ou recupera, se já existir) o boleto para a fatura, aplicando o offset de
     * vencimento configurado. Ver docs/ARQUITETURA.md §1.7 para a semântica do offset.
     *
     * @param array $items [['name'=>string,'amount'=>int,'value_cents'=>int], ...]
     */
    public function issueOrFetch(
        int $invoiceId,
        string $invoiceDueDate,
        array $items,
        string $customerName,
        string $documentDigitsOnly,
        int $totalAmountCents
    ): array {
        $existing = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($existing !== null && $existing->status !== 'canceled') {
            return $this->presentExisting($existing);
        }

        $offsetDays = (int) ($this->gatewayParams['due_days_offset'] ?? 0);
        $expireAt = DueDate::forCreation($invoiceDueDate, $offsetDays);

        $body = $this->buildOneStepBody($invoiceId, $expireAt, $items, $customerName, $documentDigitsOnly);

        try {
            $response = $this->api->createOneStepCharge([], $body);
        } catch (EfiException $e) {
            GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao emitir boleto (' . $e->code . ')', [
                'error' => $e->error,
                'description' => $e->errorDescription,
                'invoice' => $invoiceId,
            ]);

            throw new \RuntimeException('Não foi possível emitir o boleto: ' . $e->errorDescription);
        }

        $data = $response['data'] ?? $response;
        $chargeId = (string) ($data['charge_id'] ?? '');

        if ($chargeId === '') {
            GatewayLog::error('efi_boleto', $this->gatewayParams, 'Resposta de emissão sem charge_id', $response);
            throw new \RuntimeException('A Efí não retornou um identificador de cobrança válido.');
        }

        $barcode = $data['payment']['banking_billet']['barcode'] ?? null;
        $link = $data['payment']['banking_billet']['link'] ?? null;

        $localId = ChargeRepository::create([
            'invoice_id' => $invoiceId,
            'rail' => self::RAIL,
            'efi_charge_id' => $chargeId,
            'status' => (string) ($data['status'] ?? 'waiting'),
            'amount_cents' => $totalAmountCents,
            'due_offset_days' => $offsetDays,
            'metadata' => json_encode(['expire_at' => $expireAt, 'link' => $link, 'barcode' => $barcode]),
        ]);

        GatewayLog::debug('efi_boleto', $this->gatewayParams, 'Boleto emitido', $response);

        return [
            'charge_id' => $chargeId,
            'status' => (string) ($data['status'] ?? 'waiting'),
            'expire_at' => $expireAt,
            'barcode' => $barcode,
            'link' => $link,
            'local_id' => $localId,
        ];
    }

    /**
     * Realinha o vencimento do boleto quando a fatura WHMCS é editada (hook UpdateInvoiceTotal).
     * Reaplica o MESMO offset gravado na emissão, nunca a configuração corrente do gateway.
     *
     * @return string|null null se nada precisou ser feito; caso contrário, uma mensagem de
     *                      diagnóstico (sucesso ou motivo de não ter sido possível realinhar).
     */
    public function realignDueDate(int $invoiceId, string $newInvoiceDueDate): ?string
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($charge === null) {
            return null; // esta fatura não tem boleto Efí -- nada a fazer.
        }

        if (!in_array($charge->status, ['new', 'waiting', 'unpaid'], true)) {
            return "Boleto {$charge->efi_charge_id} está com status '{$charge->status}'; vencimento não realinhado (a Efí só permite alterar cobranças não pagas).";
        }

        $offsetDays = (int) ($charge->due_offset_days ?? 0);
        $candidate = DueDate::forRealignment($newInvoiceDueDate, $offsetDays);

        if (!DueDate::isAcceptableByEfi($candidate)) {
            return "Novo vencimento calculado ({$candidate}) não é maior que hoje; a Efí exige data futura. Realinhamento não enviado -- ajuste manualmente se necessário.";
        }

        try {
            $this->api->updateBillet(['id' => $charge->efi_charge_id], ['expire_at' => $candidate]);
        } catch (EfiException $e) {
            GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao realinhar vencimento (' . $e->code . ')', [
                'error' => $e->error,
                'description' => $e->errorDescription,
                'invoice' => $invoiceId,
                'charge_id' => $charge->efi_charge_id,
            ]);

            return "Falha ao realinhar vencimento na Efí: {$e->errorDescription}";
        }

        return "Vencimento do boleto {$charge->efi_charge_id} realinhado para {$candidate}.";
    }

    public function cancel(int $invoiceId): void
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($charge === null || in_array($charge->status, ['canceled', 'paid'], true)) {
            return;
        }

        try {
            $this->api->cancelCharge(['id' => $charge->efi_charge_id]);
            ChargeRepository::updateStatus($charge->id, 'canceled');
        } catch (EfiException $e) {
            GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao cancelar boleto (' . $e->code . ')', [
                'error' => $e->error,
                'description' => $e->errorDescription,
                'invoice' => $invoiceId,
            ]);
        }
    }

    private function presentExisting(object $charge): array
    {
        $metadata = json_decode((string) $charge->metadata, true) ?: [];

        return [
            'charge_id' => $charge->efi_charge_id,
            'status' => $charge->status,
            'expire_at' => $metadata['expire_at'] ?? null,
            'barcode' => $metadata['barcode'] ?? null,
            'link' => $metadata['link'] ?? null,
            'local_id' => $charge->id,
            'existing' => true,
        ];
    }

    private function buildOneStepBody(
        int $invoiceId,
        string $expireAt,
        array $items,
        string $customerName,
        string $documentDigitsOnly
    ): array {
        $efiItems = array_map(static fn (array $item) => [
            'name' => mb_substr($item['name'], 0, 255),
            'amount' => (int) $item['amount'],
            'value' => (int) $item['value_cents'],
        ], $items);

        $customer = ['name' => $customerName];
        $customer[CustomerResolver::documentKey($documentDigitsOnly)] = $documentDigitsOnly;

        $configurations = [];

        $fine = (float) ($this->gatewayParams['fine_percent'] ?? 0);
        if ($fine > 0) {
            $configurations['fine'] = Money::percentToFineUnits($fine);
        }

        $interest = (float) ($this->gatewayParams['interest_percent'] ?? 0);
        if ($interest > 0) {
            $configurations['interest'] = Money::percentToInterestUnits($interest);
        }

        $writeOffDays = $this->gatewayParams['days_to_write_off'] ?? '';
        if ($writeOffDays !== '' && is_numeric($writeOffDays)) {
            // [INFERENCE] nome do atributo confirmado na documentação em prosa
            // (dev.efipay.com.br/docs/api-cobrancas/boleto), mas sem um payload de exemplo no
            // SDK que confirme o posicionamento exato dentro de `configurations`. Validar em
            // sandbox antes de confiar em produção.
            $configurations['days_to_write_off'] = (int) $writeOffDays;
        }

        $billet = [
            'expire_at' => $expireAt,
            'customer' => $customer,
        ];

        $message = trim((string) ($this->gatewayParams['message'] ?? ''));
        if ($message !== '') {
            $billet['message'] = $message;
        }

        if ($configurations !== []) {
            $billet['configurations'] = $configurations;
        }

        $discount = $this->buildDiscount();
        if ($discount !== null) {
            $billet['discount'] = $discount;
        }

        return [
            'items' => $efiItems,
            'metadata' => [
                'custom_id' => 'whmcs_invoice_' . $invoiceId,
                'notification_url' => $this->notificationUrl(),
            ],
            'payment' => ['banking_billet' => $billet],
        ];
    }

    private function buildDiscount(): ?array
    {
        $type = $this->gatewayParams['discount_type'] ?? 'none';
        $value = (float) ($this->gatewayParams['discount_value'] ?? 0);

        if ($type === 'none' || $value <= 0) {
            return null;
        }

        return $type === 'percentage'
            ? ['type' => 'percentage', 'value' => (int) round($value * 100)]
            : ['type' => 'currency', 'value' => Money::toCents($value)];
    }

    private function notificationUrl(): string
    {
        $systemUrl = rtrim((string) ($this->gatewayParams['systemurl'] ?? ''), '/');

        return $systemUrl . '/modules/gateways/callback/efi_boleto.php';
    }
}
