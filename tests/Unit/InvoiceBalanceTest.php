<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Support\InvoiceBalance;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class InvoiceBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::schema()->dropIfExists('tblaccounts');
        Capsule::schema()->dropIfExists('tblinvoices');
        Capsule::schema()->create('tblinvoices', function ($table) { $table->increments('id'); $table->decimal('total', 10, 2); $table->string('status'); });
        Capsule::schema()->create('tblaccounts', function ($table) { $table->increments('id'); $table->unsignedInteger('invoiceid'); $table->decimal('amountin', 10, 2); $table->string('transid')->nullable(); });
        Capsule::table('tblinvoices')->insert(['id' => 1, 'total' => '100.00', 'status' => 'Unpaid']);
    }

    public function testItReturnsTheEntireTotalWhenThereIsNoPayment(): void
    {
        self::assertSame(10000, InvoiceBalance::centsDue(1));
    }

    public function testItSubtractsAllPartialPayments(): void
    {
        Capsule::table('tblaccounts')->insert([['invoiceid' => 1, 'amountin' => '10.25', 'transid' => 'a'], ['invoiceid' => 1, 'amountin' => '20.75', 'transid' => 'b']]);
        self::assertSame(6900, InvoiceBalance::centsDue(1));
    }

    public function testItReturnsZeroForASettledInvoice(): void
    {
        Capsule::table('tblaccounts')->insert(['invoiceid' => 1, 'amountin' => '100.00', 'transid' => 'a']);
        self::assertSame(0, InvoiceBalance::centsDue(1));
    }
}
