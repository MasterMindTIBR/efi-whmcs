<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Ledger\Schema;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class CardRefundLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Schema::ensure();
        Capsule::table('mod_efi_charges')->insert([
            'id' => 1, 'invoice_id' => 1, 'rail' => 'cartao', 'efi_charge_id' => '321', 'status' => 'paid',
            'amount_cents' => 10000, 'metadata' => json_encode(['installments' => 1]),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testPartialRefundsAccumulateUntilTheChargedAmount(): void
    {
        self::assertSame(4000, ChargeRepository::addRefundedCents(1, 4000));
        self::assertSame('partially_refunded', Capsule::table('mod_efi_charges')->where('id', 1)->value('status'));

        self::assertSame(10000, ChargeRepository::addRefundedCents(1, 6000));
        self::assertSame('refunded', Capsule::table('mod_efi_charges')->where('id', 1)->value('status'));

        $metadata = json_decode((string) Capsule::table('mod_efi_charges')->where('id', 1)->value('metadata'), true);
        self::assertSame(1, $metadata['installments']); // metadata existente preservada
    }
}
