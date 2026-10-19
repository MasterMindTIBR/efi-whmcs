<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Ledger\EventRepository;
use EfiWhmcs\Ledger\Schema;
use EfiWhmcs\Pix\PixService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class PixRefundIdTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Schema::ensure();
        Capsule::table('mod_efi_charges')->insert([
            'id' => 7, 'invoice_id' => 1, 'rail' => 'pix', 'efi_charge_id' => 'txid-7',
            'status' => 'CONCLUIDA', 'amount_cents' => 1000,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testSecondPartialRefundUsesADifferentAttemptId(): void
    {
        $firstAttempt = EventRepository::reserveNextDevolutionAttempt(7, 'CONCLUIDA', 'txid-7');
        $firstId = PixService::refundId(7, $firstAttempt);
        $secondAttempt = EventRepository::reserveNextDevolutionAttempt(7, 'CONCLUIDA', 'txid-7');
        $secondId = PixService::refundId(7, $secondAttempt);

        self::assertSame(1, $firstAttempt);
        self::assertSame(2, $secondAttempt);
        self::assertNotSame($firstId, $secondId);
    }
}
