<?php

declare(strict_types=1);

namespace Tests\Unit;

use Efi\EfiPay;
use EfiWhmcs\Card\CardService;
use EfiWhmcs\Support\InvoiceBalance;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/** approved → unpaid/canceled pela antifraude: o crédito dado em 'approved' precisa ser desfeito uma única vez. */
final class CardReversalTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['tblaccounts', 'tblinvoices'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tblinvoices', function ($table) { $table->increments('id'); $table->unsignedInteger('userid'); $table->decimal('total', 10, 2); $table->string('status'); });
        Capsule::schema()->create('tblaccounts', function ($table) { $table->increments('id'); $table->unsignedInteger('invoiceid'); $table->decimal('amountin', 10, 2); $table->decimal('amountout', 10, 2)->default(0); $table->string('transid')->nullable(); });
        Capsule::table('tblinvoices')->insert(['id' => 7, 'userid' => 3, 'total' => '50.00', 'status' => 'Paid']);
        Capsule::table('tblaccounts')->insert(['invoiceid' => 7, 'amountin' => '50.00', 'transid' => '987']);

        $GLOBALS['efi_test_local_api_calls'] = [];
        $GLOBALS['efi_test_local_api_fn'] = static function (string $command, array $params): array {
            if ($command === 'AddTransaction') {
                Capsule::table('tblaccounts')->insert(['invoiceid' => $params['invoiceid'], 'amountin' => 0, 'amountout' => $params['amountout'], 'transid' => $params['transid']]);
            }
            if ($command === 'UpdateInvoice') {
                Capsule::table('tblinvoices')->where('id', $params['invoiceid'])->update(['status' => $params['status']]);
            }

            return ['result' => 'success'];
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['efi_test_local_api_fn']);
    }

    public function testRevertedApprovalReopensInvoiceOnceAndRestoresBalance(): void
    {
        $service = new CardService($this->createMock(EfiPay::class), ['debug_level' => 'errors']);
        $charge = (object) ['efi_charge_id' => '987', 'invoice_id' => 7];

        $service->revertAppliedPayment($charge, 'unpaid');
        $service->revertAppliedPayment($charge, 'canceled'); // segunda notificação/reconciliação

        $commands = array_column($GLOBALS['efi_test_local_api_calls'], 'command');
        self::assertSame(['AddTransaction', 'UpdateInvoice'], $commands);
        self::assertSame(50.0, (float) $GLOBALS['efi_test_local_api_calls'][0]['params']['amountout']);
        self::assertSame('987-reversao', $GLOBALS['efi_test_local_api_calls'][0]['params']['transid']);
        self::assertSame('Unpaid', Capsule::table('tblinvoices')->where('id', 7)->value('status'));
        self::assertSame(5000, InvoiceBalance::centsDue(7));
    }

    public function testChargeThatWasNeverCreditedIsNotReverted(): void
    {
        $service = new CardService($this->createMock(EfiPay::class), ['debug_level' => 'errors']);

        $service->revertAppliedPayment((object) ['efi_charge_id' => '555', 'invoice_id' => 7], 'canceled');

        self::assertSame([], $GLOBALS['efi_test_local_api_calls']);
    }
}
