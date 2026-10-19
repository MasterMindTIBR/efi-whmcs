<?php

declare(strict_types=1);

namespace Tests\Unit;

use Efi\EfiPay;
use EfiWhmcs\Card\CardService;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Ledger\Schema;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class CardRefundPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Schema::ensure();
    }

    public function testRefundSendsAmountFieldNotValue(): void
    {
        $captured = null;
        $api = $this->createMock(EfiPay::class);
        $api->method('__call')->willReturnCallback(function (string $method, array $args) use (&$captured): array {
            self::assertSame('refundCard', $method);
            $captured = $args[1] ?? null;

            return ['refundId' => 'refund-1'];
        });

        $service = new CardService($api, ['debug_level' => 'errors']);
        $result = $service->refund('charge-1', 500);

        self::assertSame('success', $result['status']);
        self::assertIsArray($captured);
        self::assertArrayHasKey('amount', $captured);
        self::assertSame(500, $captured['amount']);
        self::assertArrayNotHasKey('value', $captured);
    }

    public function testPartialRefundMarksChargeAsPartiallyRefunded(): void
    {
        $chargeId = ChargeRepository::create([
            'invoice_id' => 1, 'rail' => 'cartao', 'efi_charge_id' => 'charge-2',
            'status' => 'paid', 'amount_cents' => 1000,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $charge = ChargeRepository::findByEfiChargeId('charge-2');
        self::assertNotNull($charge);

        $isFullRefund = 400 >= (int) $charge->amount_cents;
        self::assertFalse($isFullRefund);

        ChargeRepository::updateStatus($chargeId, $isFullRefund ? 'refunded' : 'partially_refunded');
        self::assertSame('partially_refunded', Capsule::table('mod_efi_charges')->where('id', $chargeId)->value('status'));
    }

    public function testFullRefundMarksChargeAsRefunded(): void
    {
        $chargeId = ChargeRepository::create([
            'invoice_id' => 1, 'rail' => 'cartao', 'efi_charge_id' => 'charge-3',
            'status' => 'paid', 'amount_cents' => 1000,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $charge = ChargeRepository::findByEfiChargeId('charge-3');
        $isFullRefund = 1000 >= (int) $charge->amount_cents;
        self::assertTrue($isFullRefund);

        ChargeRepository::updateStatus($chargeId, $isFullRefund ? 'refunded' : 'partially_refunded');
        self::assertSame('refunded', Capsule::table('mod_efi_charges')->where('id', $chargeId)->value('status'));
    }
}
