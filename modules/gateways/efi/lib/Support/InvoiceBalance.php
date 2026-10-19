<?php

declare(strict_types=1);

namespace EfiWhmcs\Support;

use WHMCS\Database\Capsule;

/** Central source for the outstanding balance calculation. */
final class InvoiceBalance
{
    /**
     * Total da fatura menos o líquido recebido (entradas − saídas), em centavos -- a mesma conta
     * do saldo do WHMCS. As saídas contam: a reversão de cartão da antifraude é lançada como
     * `amountout` e precisa devolver o saldo da fatura.
     */
    public static function centsDue(int $invoiceId): int
    {
        $total = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
        if ($total === null) {
            return 0;
        }

        $paidIn = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
        $paidOut = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');

        return Money::toCents((string) $total) - Money::toCents((string) ($paidIn ?? 0)) + Money::toCents((string) ($paidOut ?? 0));
    }

    /**
     * Código ISO da moeda da fatura. O WHMCS não grava moeda em `tblinvoices`: ela é a moeda do
     * cliente (`tblclients.currency` → `tblcurrencies.code`). null quando não resolvível.
     */
    public static function currencyCode(int $invoiceId): ?string
    {
        $code = Capsule::table('tblinvoices as i')
            ->join('tblclients as c', 'c.id', '=', 'i.userid')
            ->join('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->where('i.id', $invoiceId)
            ->value('cur.code');

        return $code !== null ? strtoupper((string) $code) : null;
    }

    /** A API da Efí cobra sempre em BRL; o módulo nunca converte moeda. */
    public static function isBrl(int $invoiceId): bool
    {
        $code = self::currencyCode($invoiceId);

        return $code === null || $code === 'BRL';
    }
}
