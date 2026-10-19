<?php

declare(strict_types=1);

namespace Tests\Integration;

use EfiWhmcs\Ledger\Schema;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * @group mysql
 *
 * Run manually with EFI_TEST_DB=mysql and the EFI_TEST_MYSQL_* variables. The test requires a
 * MySQL 8 instance because GET_LOCK is a production invariant and SQLite cannot model it.
 */
final class ConcurrentClaimTest extends TestCase
{
    protected function setUp(): void
    {
        if ((getenv('EFI_TEST_DB') ?: '') !== 'mysql') { $this->markTestSkipped('Set EFI_TEST_DB=mysql to run GET_LOCK integration coverage.'); }
        try { Capsule::connection()->getPdo(); } catch (\Throwable $e) { $this->markTestSkipped('MySQL unavailable: ' . $e->getMessage()); }
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges', 'tblaccounts', 'tblinvoices'] as $table) { Capsule::schema()->dropIfExists($table); }
        Capsule::schema()->create('tblinvoices', function ($table) { $table->increments('id'); $table->decimal('total', 10, 2); $table->string('status'); });
        Capsule::schema()->create('tblaccounts', function ($table) { $table->increments('id'); $table->unsignedInteger('invoiceid'); $table->decimal('amountin', 10, 2); $table->string('transid')->nullable(); });
        Capsule::table('tblinvoices')->insert(['id' => 1, 'total' => '10.00', 'status' => 'Unpaid']);
        Schema::ensure();
        Capsule::table('mod_efi_charges')->insert([
            ['id' => 1, 'invoice_id' => 1, 'rail' => 'boleto', 'efi_charge_id' => 'boleto-charge', 'status' => 'waiting', 'amount_cents' => 1000, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => 2, 'invoice_id' => 1, 'rail' => 'cartao', 'efi_charge_id' => 'card-charge', 'status' => 'waiting', 'amount_cents' => 1000, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
        ]);
    }

    public function testSameEventAndDistinctEventsCannotDoubleCreditOneInvoice(): void
    {
        $this->runWorkers([[1, 'same-event', 'same-transid'], [1, 'same-event', 'same-transid']]);
        self::assertSame(1, Capsule::table('tblaccounts')->count());
        fwrite(STDOUT, "same-event workers: addInvoicePayment entries=1\n");

        Capsule::table('tblaccounts')->delete();
        Capsule::table('tblinvoices')->where('id', 1)->update(['status' => 'Unpaid']);
        $this->runWorkers([[1, 'boleto-paid', 'boleto-transid'], [2, 'card-paid', 'card-transid']]);
        self::assertSame(1, Capsule::table('tblaccounts')->count());
        fwrite(STDOUT, "distinct-event workers: addInvoicePayment entries=1\n");
    }

    /** @param array<int, array{int,string,string}> $workers */
    private function runWorkers(array $workers): void
    {
        $processes = [];
        foreach ($workers as [$chargeId, $event, $transId]) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/payment_applier_worker.php') . ' ' . escapeshellarg((string) $chargeId) . ' ' . escapeshellarg($event) . ' ' . escapeshellarg($transId);
            $pipes = [];
            $env = $_ENV;
            $env['EFI_TEST_DB'] = 'mysql';
            foreach (['EFI_TEST_MYSQL_HOST', 'EFI_TEST_MYSQL_PORT', 'EFI_TEST_MYSQL_DB', 'EFI_TEST_MYSQL_USER', 'EFI_TEST_MYSQL_PASSWORD'] as $name) {
                if (getenv($name) !== false) { $env[$name] = getenv($name); }
            }
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
        }
    }
}
