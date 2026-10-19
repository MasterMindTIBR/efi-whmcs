<?php

declare(strict_types=1);

namespace EfiWhmcs\Support;

use WHMCS\Database\Capsule;

/** Central source for the outstanding balance calculation. */
final class InvoiceBalance
{
    /** Returns invoice total less all recorded incoming payments, in cents. */
    public static function centsDue(int $invoiceId): int
    {
        $total = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
        if ($total === null) {
            return 0;
        }

        $paid = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');

        return Money::toCents((string) $total) - Money::toCents((string) ($paid ?? 0));
    }
}
