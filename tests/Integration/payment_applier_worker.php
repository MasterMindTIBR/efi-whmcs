<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap/bootstrap.php';

use EfiWhmcs\Support\PaymentApplier;
use EfiWhmcs\Ledger\EventRepository;
use WHMCS\Database\Capsule;

[$script, $chargeId, $eventKey, $transId] = $argv;
$GLOBALS['efi_test_add_invoice_payment_fn'] = static function (int $invoiceId, string $transactionId, $amount): void {
    Capsule::table('tblaccounts')->insert(['invoiceid' => $invoiceId, 'amountin' => $amount, 'transid' => $transactionId]);
    Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid']);
};
$eventId = EventRepository::tryClaim((int) $chargeId, $eventKey, 'waiting', 'paid', $transId);
if ($eventId === null) { exit(0); }
PaymentApplier::apply([
    'invoiceId' => 1, 'transId' => $transId, 'amountCents' => 1000,
    'module' => 'efi_boleto', 'gatewayParams' => ['debug_level' => 'errors'],
    'chargeId' => (int) $chargeId, 'eventId' => $eventId,
]);
