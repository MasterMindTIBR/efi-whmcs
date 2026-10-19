<?php

declare(strict_types=1);

namespace Tests\Unit;

use Efi\EfiPay;
use EfiWhmcs\Ledger\EventRepository;
use EfiWhmcs\Ledger\Schema;
use EfiWhmcs\Pix\PixService;
use EfiWhmcs\PixAutomatic\Repository as PixAutomaticRepository;
use EfiWhmcs\PixAutomatic\Schema as PixAutomaticSchema;
use EfiWhmcs\Support\PaymentApplier;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class PaymentApplierTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges', 'tblaccounts', 'tblinvoices'] as $table) { Capsule::schema()->dropIfExists($table); }
        Capsule::schema()->create('tblinvoices', function ($table) { $table->increments('id'); $table->decimal('total', 10, 2); $table->string('status'); });
        Capsule::schema()->create('tblaccounts', function ($table) { $table->increments('id'); $table->unsignedInteger('invoiceid'); $table->decimal('amountin', 10, 2); $table->decimal('amountout', 10, 2)->default(0); $table->string('transid')->nullable(); });
        Capsule::table('tblinvoices')->insert(['id' => 1, 'total' => '10.00', 'status' => 'Unpaid']);
        Schema::ensure();
        Capsule::table('mod_efi_charges')->insert(['id' => 1, 'invoice_id' => 1, 'rail' => 'boleto', 'efi_charge_id' => 'charge-1', 'status' => 'waiting', 'amount_cents' => 1000, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $GLOBALS['efi_test_invoice_payments'] = [];
        $GLOBALS['efi_test_log_transactions'] = [];
        $GLOBALS['efi_test_local_api_calls'] = [];
        unset($GLOBALS['efi_test_local_api_fn']);
        $GLOBALS['efi_test_add_invoice_payment_fn'] = static function (int $invoiceId, string $transId, $amount): void {
            Capsule::table('tblaccounts')->insert(['invoiceid' => $invoiceId, 'amountin' => $amount, 'transid' => $transId]);
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid']);
        };
    }

    private function payment(int $eventId, string $transId = 'e2e-1'): array
    {
        return ['invoiceId' => 1, 'transId' => $transId, 'amountCents' => 1000, 'module' => 'efi_boleto', 'gatewayParams' => ['debug_level' => 'errors'], 'chargeId' => 1, 'eventId' => $eventId];
    }

    public function testPendingEventBecomesAppliedAfterSuccessfulPayment(): void
    {
        $eventId = EventRepository::tryClaim(1, 'event-1', 'waiting', 'paid', 'charge-1');
        self::assertIsInt($eventId);
        self::assertTrue(PaymentApplier::apply($this->payment($eventId)));
        self::assertSame('applied', Capsule::table('mod_efi_events')->where('id', $eventId)->value('status'));
        self::assertSame('paid', Capsule::table('mod_efi_charges')->where('id', 1)->value('status'));
        self::assertCount(1, $GLOBALS['efi_test_invoice_payments']);
    }

    public function testExistingCanonicalTransactionMarksEventAppliedWithoutAnotherPayment(): void
    {
        Capsule::table('tblinvoices')->where('id', 1)->update(['status' => 'Paid']);
        Capsule::table('tblaccounts')->insert(['invoiceid' => 1, 'amountin' => '10.00', 'transid' => 'e2e-1']);
        $eventId = EventRepository::tryClaim(1, 'event-1', 'waiting', 'paid', 'charge-1');
        PaymentApplier::apply($this->payment($eventId));
        self::assertSame('applied', Capsule::table('mod_efi_events')->where('id', $eventId)->value('status'));
        self::assertCount(0, $GLOBALS['efi_test_invoice_payments']);
    }

    public function testPaidInvoiceWithOtherStagingTransactionsDoesNotCreditANewOne(): void
    {
        Capsule::table('tblinvoices')->where('id', 1)->update(['total' => '9.90', 'status' => 'Paid']);
        Capsule::table('tblaccounts')->insert([
            ['invoiceid' => 1, 'amountin' => '9.90', 'transid' => '45033238'],
            ['invoiceid' => 1, 'amountin' => '9.90', 'transid' => 'E09089356202609301436APIc2351b56'],
        ]);
        $eventId = EventRepository::tryClaim(1, 'third-attempt', 'waiting', 'paid', '45033238');
        self::assertFalse(PaymentApplier::apply($this->payment($eventId, 'third-provider-transaction')));
        self::assertCount(0, $GLOBALS['efi_test_invoice_payments']);
        self::assertSame('pagamento recebido fora do ciclo de cobrança', $GLOBALS['efi_test_log_transactions'][0]['status']);
    }

    public function testClaimOnlySuppressesSqlstateDuplicateAndPropagatesOtherDatabaseErrors(): void
    {
        $firstId = EventRepository::tryClaim(1, 'same', null, 'paid');
        self::assertIsInt($firstId);
        self::assertSame($firstId, EventRepository::tryClaim(1, 'same', null, 'paid'));
        EventRepository::markApplied($firstId);
        self::assertNull(EventRepository::tryClaim(1, 'same', null, 'paid'));
        Capsule::schema()->drop('mod_efi_events');
        Capsule::schema()->create('mod_efi_events', function ($table) { $table->increments('id'); });
        $this->expectException(\Throwable::class);
        EventRepository::tryClaim(1, 'different', null, 'paid');
    }

    public function testLedgerIsMarkedPaidBeforeWhmcsPaymentFunction(): void
    {
        $order = [];
        $GLOBALS['efi_test_add_invoice_payment_fn'] = static function (int $invoiceId, string $transId, $amount) use (&$order): void { $order[] = 'add'; };
        $eventId = EventRepository::tryClaim(1, 'event-1', null, 'paid');
        $payment = $this->payment($eventId);
        $payment['markPaid'] = static function () use (&$order): void { $order[] = 'paid'; };
        PaymentApplier::apply($payment);
        self::assertSame(['paid', 'add'], $order);
    }

    public function testOverpaymentIsAppliedAndLoggedForManualReconciliation(): void
    {
        $eventId = EventRepository::tryClaim(1, 'overpayment', 'waiting', 'paid', 'charge-1');
        $payment = $this->payment($eventId, 'overpayment-e2e');
        $payment['amountCents'] = 1500;

        self::assertTrue(PaymentApplier::apply($payment));
        self::assertCount(1, $GLOBALS['efi_test_invoice_payments']);
        self::assertSame(15.0, $GLOBALS['efi_test_invoice_payments'][0]['amount']);
        self::assertSame('Pagamento confirmado excede o saldo da fatura; crédito será mantido para conciliação', $GLOBALS['efi_test_log_transactions'][0]['status']);
    }

    public function testPendingClaimCanBeRetriedUntilItIsApplied(): void
    {
        $eventId = EventRepository::tryClaim(1, 'retry-me', 'waiting', 'paid', 'charge-1');
        self::assertIsInt($eventId);
        self::assertSame($eventId, EventRepository::tryClaim(1, 'retry-me', 'waiting', 'paid', 'charge-1'));

        self::assertTrue(PaymentApplier::apply($this->payment($eventId, 'retry-e2e')));
        self::assertSame('applied', Capsule::table('mod_efi_events')->where('id', $eventId)->value('status'));
        self::assertNull(EventRepository::tryClaim(1, 'retry-me', 'waiting', 'paid', 'charge-1'));
    }

    public function testOutOfCyclePixPaymentClosesItsEventAndNeverLaunchesTheDiscount(): void
    {
        Capsule::table('mod_efi_charges')->insert([
            'id' => 2, 'invoice_id' => 1, 'rail' => 'pix', 'efi_charge_id' => 'pix-charge-2',
            'status' => 'ATIVA', 'amount_cents' => 900,
            'metadata' => json_encode(['base_amount_cents' => 1000, 'discount_percent' => 10]),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $eventId = EventRepository::tryClaim(2, 'pix-out-of-cycle', 'ATIVA', 'CONCLUIDA', 'pix-charge-2');
        self::assertIsInt($eventId);

        $pixService = new PixService($this->createMock(EfiPay::class), ['debug_level' => 'errors']);
        $payment = [
            'invoiceId' => 1, 'transId' => 'pix-e2e-late', 'amountCents' => 900,
            'module' => 'efi_pix', 'gatewayParams' => ['debug_level' => 'errors'],
            'chargeId' => 2, 'eventId' => $eventId, 'settledStatus' => 'CONCLUIDA',
            'markPaid' => static function () use ($pixService): void {
                Capsule::table('mod_efi_charges')->where('id', 2)->update(['status' => 'CONCLUIDA']);
                $pixService->applyDiscountLineItem(1, 900);
            },
        ];

        // Fatura já quitada por outro meio: nada é creditado nem descontado, a cobrança fica
        // registrada como recebida e o evento sai da fila (não volta toda madrugada).
        Capsule::table('tblinvoices')->where('id', 1)->update(['status' => 'Paid']);
        self::assertFalse(PaymentApplier::apply($payment));
        self::assertSame('failed', Capsule::table('mod_efi_events')->where('id', $eventId)->value('status'));
        self::assertSame('CONCLUIDA', Capsule::table('mod_efi_charges')->where('id', 2)->value('status'));
        self::assertCount(0, $GLOBALS['efi_test_local_api_calls']);
        self::assertCount(0, $GLOBALS['efi_test_invoice_payments']);
        self::assertNull(EventRepository::tryClaim(2, 'pix-out-of-cycle', 'ATIVA', 'CONCLUIDA', 'pix-charge-2'));
    }

    public function testReversedCardPaymentRestoresTheInvoiceBalance(): void
    {
        Capsule::table('tblaccounts')->insert(['invoiceid' => 1, 'amountin' => '10.00', 'transid' => 'card-1']);
        self::assertSame(0, \EfiWhmcs\Support\InvoiceBalance::centsDue(1));

        Capsule::table('tblaccounts')->insert(['invoiceid' => 1, 'amountin' => '0.00', 'amountout' => '10.00', 'transid' => '1-reversao']);
        self::assertSame(1000, \EfiWhmcs\Support\InvoiceBalance::centsDue(1));
    }

    public function testPendingPixAutomaticClaimCanBeRetriedUntilItIsApplied(): void
    {
        PixAutomaticSchema::ensure();
        $eventId = PixAutomaticRepository::tryClaimEvent('charge', 42, 'retry-e2e', 'CRIADA', 'CONCLUIDA');
        self::assertIsInt($eventId);
        self::assertSame($eventId, PixAutomaticRepository::tryClaimEvent('charge', 42, 'retry-e2e', 'CRIADA', 'CONCLUIDA'));

        PixAutomaticRepository::markEventApplied($eventId);
        self::assertNull(PixAutomaticRepository::tryClaimEvent('charge', 42, 'retry-e2e', 'CRIADA', 'CONCLUIDA'));
    }
}
