<?php

namespace EfiWhmcs\Card;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;

/**
 * Regras de negócio do cartão: cobrança em um passo com payment_token (checkout e recorrência
 * via `_capture`). NUNCA recebe/manuseia PAN/CVV -- só o payment_token gerado no navegador pela
 * lib `payment-token-efi` (ver modules/gateways/efi_cartao/remote_form.php).
 */
final class CardService
{
    public const RAIL = 'cartao';

    public function __construct(
        private readonly EfiPay $api,
        private readonly array $gatewayParams
    ) {
    }

    /**
     * Cobra com `POST /v1/charge/one-step`, o fluxo oficial para payment_token. Usado tanto
     * na primeira cobrança quanto na recorrência automática via `_capture`.
     *
     * @return array{status:string, transid:?string, charge_id:?string, tds_challenge:?array, raw:array}
     */
    public function chargeWithToken(
        int $invoiceId,
        string $paymentToken,
        int $amountCents,
        string $customerName,
        string $documentDigitsOnly,
        ?string $customerEmail,
        string $phoneNumber,
        ?array $tdsInfo = null,
        int $installments = 1
    ): array {
        $customer = ['name' => $customerName];
        $customer[CustomerResolver::documentKey($documentDigitsOnly)] = $documentDigitsOnly;
        if ($customerEmail) {
            $customer['email'] = $customerEmail;
        }
        $customer['phone_number'] = $phoneNumber;

        $creditCard = [
            'customer' => $customer,
            'installments' => max(1, $installments),
            'payment_token' => $paymentToken,
        ];

        if ($tdsInfo !== null) {
            $creditCard['tds_info'] = $tdsInfo;
        }

        $body = [
            'items' => [[
                'name' => 'Fatura #' . $invoiceId,
                'amount' => 1,
                'value' => $amountCents,
            ]],
            'metadata' => [
                'custom_id' => 'whmcs_invoice_' . $invoiceId,
                'notification_url' => $this->notificationUrl(),
            ],
            'payment' => [
                'credit_card' => $creditCard,
            ],
        ];

        try {
            $response = $this->api->createOneStepCharge([], $body);
        } catch (EfiException $e) {
            GatewayLog::error('efi_cartao', $this->gatewayParams, 'Falha ao cobrar cartão (' . $e->code . ')', [
                'error' => $e->error,
                'description' => $e->errorDescription,
                'invoice' => $invoiceId,
            ]);

            return [
                'status' => 'error',
                'transid' => null,
                'charge_id' => null,
                'tds_challenge' => null,
                'raw' => [
                    'error' => $e->error,
                    'description' => $e->errorDescription,
                    'http_code' => $e->code,
                ],
            ];
        }

        GatewayLog::debug('efi_cartao', $this->gatewayParams, 'Cobrança de cartão criada', $response);

        $charge = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $chargeId = isset($charge['charge_id']) ? (string) $charge['charge_id'] : null;
        $status = (string) ($charge['status'] ?? 'unknown');

        if ($chargeId !== null) {
            $this->recordLedger($invoiceId, $chargeId, $status, $amountCents);
        }

        return [
            'status' => $status,
            'transid' => $chargeId,
            'charge_id' => $chargeId,
            'tds_challenge' => $charge['tdsChallenge'] ?? null,
            'raw' => $response,
        ];
    }

    public function refund(string $chargeId, int $amountCents): array
    {
        try {
            $response = $this->api->refundCard(['id' => $chargeId], [
                'value' => $amountCents,
            ]);

            return ['status' => 'success', 'transid' => $response['refundId'] ?? $chargeId, 'raw' => $response];
        } catch (EfiException $e) {
            GatewayLog::error('efi_cartao', $this->gatewayParams, 'Falha ao estornar cartão (' . $e->code . ')', $e->errorDescription);

            return ['status' => 'error', 'raw' => $e->errorDescription];
        }
    }

    private function recordLedger(int $invoiceId, string $chargeId, string $status, int $amountCents): void
    {
        $existing = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($existing === null) {
            ChargeRepository::create([
                'invoice_id' => $invoiceId,
                'rail' => self::RAIL,
                'efi_charge_id' => $chargeId,
                'status' => $status,
                'amount_cents' => $amountCents,
            ]);

            return;
        }

        ChargeRepository::updateStatus($existing->id, $status, $chargeId);
    }

    private function notificationUrl(): string
    {
        $systemUrl = rtrim((string) ($this->gatewayParams['systemurl'] ?? ''), '/');

        return $systemUrl . '/modules/gateways/callback/efi_cartao.php';
    }
}
