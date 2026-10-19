<?php

namespace EfiWhmcs\Card;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use WHMCS\Database\Capsule;

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
        int $installments = 1,
        ?string $companyName = null
    ): array {
        $customer = ['name' => $customerName]
            + CustomerResolver::chargesCustomerDocument($documentDigitsOnly, $customerName, $companyName);
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

        $locked = false;

        try {
            $locked = $this->lockInvoice($invoiceId);

            if (!$locked) {
                GatewayLog::error('efi_cartao', $this->gatewayParams, 'Falha ao obter trava da fatura; cartão não foi cobrado', ['invoice_id' => $invoiceId]);

                return [
                    'status' => 'error', 'transid' => null, 'charge_id' => null, 'tds_challenge' => null,
                    'raw' => ['error' => 'lock_failed', 'description' => 'Não foi possível reservar a cobrança. Tente novamente em instantes.'],
                ];
            }

            $localId = ChargeRepository::reserveCreating($invoiceId, self::RAIL, $amountCents);

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

                // Rejeição limpa da Efí (não um timeout/erro de rede): não há ambiguidade sobre
                // o resultado, então 'error' não bloqueia uma nova tentativa (ao contrário de
                // 'creating' sem resposta, que fica para o cron resolver).
                ChargeRepository::updateStatus($localId, 'error');

                return [
                    'status' => 'error',
                    'transid' => null,
                    'charge_id' => null,
                    'local_id' => $localId,
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
                ChargeRepository::updateStatus($localId, $status, $chargeId);
            }
            // Se $chargeId for null a linha fica 'creating' sem charge_id -- a varredura do
            // cron (Rock 1) a resolve como failed-unknown após 10 minutos sem reemitir.

            return [
                'status' => $status,
                'transid' => $chargeId,
                'charge_id' => $chargeId,
                'local_id' => $localId,
                'tds_challenge' => $charge['tdsChallenge'] ?? null,
                'raw' => $response,
            ];
        } finally {
            if ($locked) {
                $this->unlockInvoice($invoiceId);
            }
        }
    }

    /** @param int $amountCents valor a estornar; o chamador decide estorno total vs. parcial. */
    public function refund(string $chargeId, int $amountCents): array
    {
        try {
            $response = $this->api->refundCard(['id' => $chargeId], [
                'amount' => $amountCents,
            ]);

            return ['status' => 'success', 'transid' => $response['refundId'] ?? $chargeId, 'raw' => $response];
        } catch (EfiException $e) {
            GatewayLog::error('efi_cartao', $this->gatewayParams, 'Falha ao estornar cartão (' . $e->code . ')', $e->errorDescription);

            return ['status' => 'error', 'raw' => $e->errorDescription];
        }
    }

    /**
     * Cancela a cobrança de cartão em aberto ('new'/'waiting'/'creating'/'unknown') quando a
     * fatura sai do ciclo de cobrança por outro caminho (`InvoicePaid`/`InvoiceCancelled`/
     * `InvoiceRefunded`). Uma cobrança 'approved'/'paid' nunca é cancelada aqui -- o dinheiro
     * já foi capturado; se isso acontecer numa fatura que não deveria mais ser cobrada, é a
     * mesma situação de "pagamento fora do ciclo" das outras trilhas, resolvida manualmente.
     */
    public function cancel(int $invoiceId): void
    {
        $charge = ChargeRepository::findByInvoiceAndRail($invoiceId, self::RAIL);

        if ($charge === null || !in_array($charge->status, ['new', 'waiting', 'creating', 'unknown'], true)) {
            return;
        }

        if ($charge->efi_charge_id === null) {
            // 'creating' sem charge_id capturado: nada foi emitido na Efí para cancelar.
            return;
        }

        try {
            $this->api->cancelCharge(['id' => $charge->efi_charge_id]);
            ChargeRepository::updateStatus($charge->id, 'canceled');
        } catch (EfiException $e) {
            GatewayLog::error('efi_cartao', $this->gatewayParams, 'Falha ao cancelar cobrança de cartão (' . $e->code . ')', [
                'error' => $e->error,
                'description' => $e->errorDescription,
                'invoice' => $invoiceId,
            ]);
        }
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

        return $systemUrl . '/modules/gateways/callback/efi_cartao.php';
    }
}
