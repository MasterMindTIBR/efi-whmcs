<?php

declare(strict_types=1);

namespace EfiWhmcs\Support;

use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Ledger\EventRepository;
use WHMCS\Database\Capsule;

/** The sole path that turns a confirmed Efí payment into a WHMCS account entry. */
final class PaymentApplier
{
    /**
     * @param array{invoiceId:int,transId:string,amountCents:int,module:string,gatewayParams:array,chargeId?:int,eventId?:int,settledStatus?:string,markPaid?:callable,markSettled?:callable,markApplied?:callable,markFailed?:callable} $payment
     *
     * Callbacks:
     * - markSettled: só grava no ledger que o provedor confirmou o pagamento (sem efeitos
     *   colaterais). Padrão: `chargeId` → status `settledStatus` (default 'paid').
     * - markPaid: roda imediatamente antes de addInvoicePayment, só no caminho que de fato
     *   aplica o pagamento (é onde ficam efeitos únicos, como o item de desconto). Padrão:
     *   markSettled.
     * - markApplied / markFailed: fecham o evento como aplicado ou como terminal-sem-efeito.
     */
    public static function apply(array $payment): bool
    {
        $invoiceId = (int) $payment['invoiceId'];
        $transId = (string) $payment['transId'];
        $module = (string) $payment['module'];
        $params = $payment['gatewayParams'];
        $locked = false;

        try {
            $locked = self::lock($invoiceId);
            if (!$locked) {
                // Transitório: o evento continua pending e o cron tenta de novo.
                GatewayLog::error($module, $params, 'Falha ao obter trava da fatura; pagamento confirmado não foi aplicado', ['invoice_id' => $invoiceId, 'transid' => $transId]);
                return false;
            }

            // Always check the canonical transaction before looking at the invoice state.
            if (Capsule::table('tblaccounts')->where('transid', $transId)->exists()) {
                // Já aplicado (outra entrega, o cron, ou o próprio WHMCS via _capture): só
                // alinha o ledger -- nunca repete efeitos de markPaid (ex.: desconto).
                self::markSettled($payment);
                self::markApplied($payment);
                return true;
            }

            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status']);
            if ($invoice === null || in_array((string) $invoice->status, ['Cancelled', 'Refunded', 'Paid'], true)) {
                GatewayLog::error($module, $params, 'pagamento recebido fora do ciclo de cobrança', [
                    'invoice_id' => $invoiceId, 'transid' => $transId, 'amount_cents' => (int) $payment['amountCents'],
                    'invoice_status' => $invoice->status ?? 'missing',
                ]);
                // Terminal: o dinheiro entrou na Efí, mas não há mais fatura em aberto para
                // creditar -- revisão manual (crédito ou devolução). O ledger reflete o
                // provedor e o evento sai da fila, em vez de ser reprocessado todo dia.
                self::markSettled($payment);
                self::markFailed($payment);
                return false;
            }

            $due = InvoiceBalance::centsDue($invoiceId);
            if ((int) $payment['amountCents'] > $due) {
                GatewayLog::error($module, $params, 'Pagamento confirmado excede o saldo da fatura; crédito será mantido para conciliação', [
                    'invoice_id' => $invoiceId, 'transid' => $transId, 'amount_cents' => (int) $payment['amountCents'], 'due_cents' => $due,
                ]);
            }

            // The synchronous InvoicePaid hook must observe paid before WHMCS fires it.
            self::markPaid($payment);
            addInvoicePayment($invoiceId, $transId, Money::toReais((int) $payment['amountCents']), 0, $module);
            self::markApplied($payment);

            return true;
        } finally {
            if ($locked) {
                self::unlock($invoiceId);
            }
        }
    }

    private static function markPaid(array $payment): void
    {
        if (isset($payment['markPaid'])) {
            ($payment['markPaid'])();
        } else {
            self::markSettled($payment);
        }
    }

    private static function markSettled(array $payment): void
    {
        if (isset($payment['markSettled'])) {
            ($payment['markSettled'])();
        } elseif (isset($payment['chargeId'])) {
            ChargeRepository::updateStatus((int) $payment['chargeId'], (string) ($payment['settledStatus'] ?? 'paid'));
        }
    }

    private static function markApplied(array $payment): void
    {
        if (isset($payment['markApplied'])) {
            ($payment['markApplied'])();
        } elseif (isset($payment['eventId'])) {
            EventRepository::markApplied((int) $payment['eventId']);
        }
    }

    private static function markFailed(array $payment): void
    {
        if (isset($payment['markFailed'])) {
            ($payment['markFailed'])();
        } elseif (isset($payment['eventId'])) {
            EventRepository::markFailed((int) $payment['eventId']);
        }
    }

    private static function lock(int $invoiceId): bool
    {
        if (Capsule::connection()->getDriverName() !== 'mysql') {
            return true; // SQLite test harness: named locks are a MySQL production invariant.
        }

        $row = Capsule::selectOne('SELECT GET_LOCK(?, 5) AS acquired', ['efi_invoice_' . $invoiceId]);

        return $row !== null && (int) ($row->acquired ?? 0) === 1;
    }

    private static function unlock(int $invoiceId): void
    {
        if (Capsule::connection()->getDriverName() === 'mysql') {
            Capsule::select('SELECT RELEASE_LOCK(?)', ['efi_invoice_' . $invoiceId]);
        }
    }
}
