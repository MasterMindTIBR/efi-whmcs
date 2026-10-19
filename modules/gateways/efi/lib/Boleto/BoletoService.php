<?php

namespace EfiWhmcs\Boleto;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\DueDate;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use WHMCS\Database\Capsule;

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
     * vencimento configurado. Ver README.md (seção Boleto) para a semântica do offset.
     *
     * @param array $items [['name'=>string,'amount'=>int,'value_cents'=>int], ...]
     */
    public function issueOrFetch(
        int $invoiceId,
        string $invoiceDueDate,
        array $items,
        string $customerName,
        string $documentDigitsOnly,
        int $totalAmountCents,
        ?string $companyName = null
    ): array {
        $locked = false;

        try {
            $locked = $this->lockInvoice($invoiceId);
            if (!$locked) {
                GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao obter trava da fatura; boleto não foi emitido', [
                    'invoice_id' => $invoiceId,
                ]);
                throw new \RuntimeException('Não foi possível reservar a emissão do boleto. Tente novamente em instantes.');
            }

            $existing = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

            if ($existing !== null && $existing->status === 'creating') {
                // Uma emissão anterior travou (processo morto entre a reserva e a chamada à
                // API) e ainda não passou pela janela de 10 minutos do cron -- não sabemos se a
                // Efí chegou a criar a cobrança. Nunca reemitir às cegas aqui.
                return [
                    'status' => 'creating',
                    'local_id' => $existing->id,
                    'message' => 'A emissão deste boleto está em andamento. Atualize a página em alguns instantes.',
                ];
            }

            if ($existing !== null && $existing->status === 'failed-unknown') {
                return [
                    'status' => 'failed-unknown',
                    'local_id' => $existing->id,
                    'message' => 'Não foi possível confirmar a emissão deste boleto. Entre em contato com o suporte antes de tentar novamente.',
                ];
            }

            if ($existing !== null && !in_array($existing->status, ['canceled', 'expired'], true)) {
                return $this->presentExisting($existing);
            }

            $offsetDays = (int) ($this->gatewayParams['due_days_offset'] ?? 0);
            $expireAt = DueDate::forCreation($invoiceDueDate, $offsetDays);
            $chargedAmountCents = $this->discountedAmountCents($totalAmountCents);
            $localId = ChargeRepository::reserveCreating($invoiceId, self::RAIL, $chargedAmountCents);
            $body = $this->buildOneStepBody($invoiceId, $expireAt, $items, $customerName, $documentDigitsOnly, $companyName);

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

            $billet = $this->extractBilletData($data);
            $barcode = $billet['barcode'];
            $link = $billet['link'];
            $status = (string) ($data['status'] ?? 'waiting');

            ChargeRepository::updateStatus($localId, $status, $chargeId);
            ChargeRepository::updateCharge($localId, [
                'due_offset_days' => $offsetDays,
                'metadata' => json_encode([
                    'base_amount_cents' => $totalAmountCents,
                    'expire_at' => $expireAt,
                    'link' => $link,
                    'barcode' => $barcode,
                ]) ?: '{}',
            ]);

            GatewayLog::debug('efi_boleto', $this->gatewayParams, 'Boleto emitido', $response);

            return [
                'charge_id' => $chargeId,
                'status' => $status,
                'expire_at' => $expireAt,
                'barcode' => $barcode,
                'link' => $link,
                'local_id' => $localId,
            ];
        } finally {
            if ($locked) {
                $this->unlockInvoice($invoiceId);
            }
        }
    }

    /**
     * Realinha o vencimento do boleto quando a fatura WHMCS é editada (hook UpdateInvoiceTotal).
     * Reaplica o MESMO offset gravado na emissão, nunca a configuração corrente do gateway.
     *
     * `PUT /v1/charge/:id/billet` só aceita `expire_at` -- a Efí não tem endpoint para mudar o
     * VALOR de um boleto já emitido. Quando o total da fatura muda (multa, desconto, crédito,
     * item novo), a única ação segura é cancelar o boleto com o valor antigo; a próxima vez que
     * `_link()` rodar (cliente reabre a fatura, ou o admin) emite um boleto novo já com o valor e
     * o vencimento corretos -- `issueOrFetch()` reemite sempre que o existente está `canceled`
     * ou `expired`.
     *
     * @return string|null null se nada precisou ser feito; caso contrário, uma mensagem de
     *                      diagnóstico (sucesso ou motivo de não ter sido possível realinhar).
     */
    public function realignDueDate(int $invoiceId, string $newInvoiceDueDate, int $currentTotalAmountCents): ?string
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($charge === null) {
            return null; // esta fatura não tem boleto Efí -- nada a fazer.
        }

        if (!in_array($charge->status, ['new', 'waiting', 'unpaid'], true)) {
            return "Boleto {$charge->efi_charge_id} está com status '{$charge->status}'; vencimento não realinhado (a Efí só permite alterar cobranças não pagas).";
        }

        if ($currentTotalAmountCents !== (int) $charge->amount_cents) {
            try {
                $this->api->cancelCharge(['id' => $charge->efi_charge_id]);
            } catch (EfiException $e) {
                GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao cancelar boleto com valor desatualizado (' . $e->code . ')', [
                    'error' => $e->error,
                    'description' => $e->errorDescription,
                    'invoice' => $invoiceId,
                    'charge_id' => $charge->efi_charge_id,
                ]);

                return "O valor da fatura mudou, mas não foi possível cancelar o boleto {$charge->efi_charge_id} com o valor antigo: {$e->errorDescription}. Cancele manualmente na Efí antes que o cliente pague o valor errado.";
            }

            ChargeRepository::updateStatus($charge->id, 'canceled');

            return "Boleto {$charge->efi_charge_id} cancelado porque o valor da fatura mudou de R$ " . number_format($charge->amount_cents / 100, 2, ',', '.') . " para R$ " . number_format($currentTotalAmountCents / 100, 2, ',', '.') . " (a Efí não altera o valor de um boleto já emitido); um boleto novo com o valor correto será emitido na próxima vez que a fatura for aberta.";
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

    /**
     * Lança o desconto já concedido pela Efí como item negativo somente quando o valor
     * confirmado pelo provedor é exatamente o valor que esta cobrança esperava receber.
     */
    public function applyDiscountLineItem(int $invoiceId, int $receivedAmountCents, object $charge): void
    {
        $metadata = json_decode((string) $charge->metadata, true) ?: [];
        $discountCents = self::discountCentsForPayment($metadata, (int) $charge->amount_cents, $receivedAmountCents);

        if ($discountCents === null) {
            GatewayLog::error('efi_boleto', $this->gatewayParams, 'Valor do boleto confirmado diverge da cobrança esperada; pagamento será aplicado sem desconto automático', [
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

        $result = localAPI('UpdateInvoice', [
            'invoiceid' => $invoiceId,
            'newitemdescription' => ['Desconto Boleto'],
            'newitemamount' => [-Money::toReais($discountCents)],
            'newitemtaxed' => [false],
        ], null);

        if (($result['result'] ?? '') !== 'success') {
            GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao lançar item de desconto Boleto na fatura', [
                'invoice_id' => $invoiceId,
                'result' => $result,
            ]);
        }
    }

    /** Returns null if the Efí-confirmed amount differs from the amount issued. */
    public static function discountCentsForPayment(array $metadata, int $expectedAmountCents, int $receivedAmountCents): ?int
    {
        if ($receivedAmountCents !== $expectedAmountCents || !isset($metadata['base_amount_cents'])) {
            return null;
        }

        $discountCents = (int) $metadata['base_amount_cents'] - $expectedAmountCents;

        return $discountCents >= 0 ? $discountCents : null;
    }

    private function presentExisting(object $charge): array
    {
        $metadata = json_decode((string) $charge->metadata, true) ?: [];
        $barcode = $metadata['barcode'] ?? null;
        $link = $metadata['link'] ?? null;

        if ($barcode === null || $link === null) {
            try {
                $response = $this->api->detailCharge(['id' => $charge->efi_charge_id]);
                $data = $response['data'] ?? $response;
                $billet = $this->extractBilletData($data);
                $barcode ??= $billet['barcode'];
                $link ??= $billet['link'];
                $metadata['barcode'] = $barcode;
                $metadata['link'] = $link;
                ChargeRepository::updateMetadata($charge->id, json_encode($metadata) ?: '{}');
            } catch (EfiException $e) {
                GatewayLog::error('efi_boleto', $this->gatewayParams, 'Falha ao recuperar boleto existente (' . $e->code . ')', [
                    'charge_id' => $charge->efi_charge_id,
                    'error' => $e->errorDescription,
                ]);
            }
        }

        return [
            'charge_id' => $charge->efi_charge_id,
            'status' => $charge->status,
            'expire_at' => $metadata['expire_at'] ?? null,
            'barcode' => $barcode,
            'link' => $link,
            'local_id' => $charge->id,
            'existing' => true,
        ];
    }

    /**
     * A API Efí devolve barcode/link diretamente em data; aceita também o formato aninhado para
     * compatibilidade com respostas antigas do SDK.
     *
     * @return array{barcode: ?string, link: ?string}
     */
    private function extractBilletData(array $data): array
    {
        $payment = $data['payment'] ?? null;
        $nested = is_array($payment) && is_array($payment['banking_billet'] ?? null)
            ? $payment['banking_billet']
            : [];

        return [
            'barcode' => isset($data['barcode']) ? (string) $data['barcode'] : ($nested['barcode'] ?? null),
            'link' => isset($data['link']) ? (string) $data['link'] : ($nested['link'] ?? null),
        ];
    }

    private function buildOneStepBody(
        int $invoiceId,
        string $expireAt,
        array $items,
        string $customerName,
        string $documentDigitsOnly,
        ?string $companyName = null
    ): array {
        $efiItems = array_map(static fn (array $item) => [
            'name' => mb_substr($item['name'], 0, 255),
            'amount' => (int) $item['amount'],
            'value' => (int) $item['value_cents'],
        ], $items);

        // CNPJ precisa do envelope `customer.juridical_person.{corporate_name,cnpj}` -- enviar
        // um CNPJ na raiz de `customer` (como o CPF) é rejeitado pela Efí (ver
        // dev.efipay.com.br/docs/api-cobrancas/boleto, exemplo "Exemplo 2 (CNPJ)").
        $customer = ['name' => $customerName]
            + CustomerResolver::chargesCustomerDocument($documentDigitsOnly, $customerName, $companyName);

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
            // Posicionamento dentro de `configurations` confirmado no payload de exemplo oficial
            // (dev.efipay.com.br/docs/api-cobrancas/boleto, "Exemplo 3 (days_to_write_off)").
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

    private function discountedAmountCents(int $baseAmountCents): int
    {
        $type = $this->gatewayParams['discount_type'] ?? 'none';
        $value = (float) ($this->gatewayParams['discount_value'] ?? 0);

        if ($type === 'none' || $value <= 0) {
            return $baseAmountCents;
        }

        $discountCents = $type === 'percentage'
            ? (int) round($baseAmountCents * $value / 100)
            : Money::toCents($value);

        return max(0, $baseAmountCents - $discountCents);
    }

    private function lockInvoice(int $invoiceId): bool
    {
        if (Capsule::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        $row = Capsule::selectOne('SELECT GET_LOCK(?, 5) AS acquired', ['efi_invoice_' . $invoiceId]);

        return $row !== null && (int) ($row->acquired ?? 0) === 1;
    }

    private function unlockInvoice(int $invoiceId): void
    {
        if (Capsule::connection()->getDriverName() === 'mysql') {
            Capsule::select('SELECT RELEASE_LOCK(?)', ['efi_invoice_' . $invoiceId]);
        }
    }

    private function notificationUrl(): string
    {
        $systemUrl = rtrim((string) ($this->gatewayParams['systemurl'] ?? ''), '/');

        return $systemUrl . '/modules/gateways/callback/efi_boleto.php';
    }
}
