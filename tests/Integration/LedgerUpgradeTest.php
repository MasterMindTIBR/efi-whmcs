<?php

declare(strict_types=1);

namespace Tests\Integration;

use EfiWhmcs\Ledger\Schema;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * @group mysql
 *
 * Upgrade de um ledger anterior ao versionamento, com status livres já gravados. Só MySQL
 * modela o problema (ALTER ... MODIFY com modo estrito).
 */
final class LedgerUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        if ((getenv('EFI_TEST_DB') ?: '') !== 'mysql') { $this->markTestSkipped('Set EFI_TEST_DB=mysql to run the MySQL upgrade coverage.'); }
        try { Capsule::connection()->getPdo(); } catch (\Throwable $e) { $this->markTestSkipped('MySQL unavailable: ' . $e->getMessage()); }
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges'] as $table) { Capsule::schema()->dropIfExists($table); }
    }

    public function testLegacyLedgerWithFreeFormStatusesUpgradesWithoutLosingThem(): void
    {
        // Formato da 1.x: unique por fatura+trilho, sem superseded_by, sem tabela de versão.
        Capsule::statement("CREATE TABLE mod_efi_charges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            rail ENUM('boleto','pix','cartao') NOT NULL,
            efi_charge_id VARCHAR(64) NULL,
            efi_loc_id VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            amount_cents INT UNSIGNED NOT NULL,
            due_offset_days SMALLINT NULL,
            metadata TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_invoice_rail (invoice_id, rail),
            UNIQUE KEY uniq_efi_charge (efi_charge_id)
        ) ENGINE=InnoDB");
        Capsule::statement("CREATE TABLE mod_efi_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            charge_id INT UNSIGNED NOT NULL,
            efi_event_key VARCHAR(64) NOT NULL,
            status_from VARCHAR(32) NULL,
            status_to VARCHAR(32) NOT NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY uniq_charge_event (charge_id, efi_event_key)
        ) ENGINE=InnoDB");
        Capsule::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");

        $now = date('Y-m-d H:i:s');
        $legacy = ['error', 'refunded', 'REMOVIDA_PELO_USUARIO_RECEBEDOR'];
        foreach ($legacy as $i => $status) {
            Capsule::table('mod_efi_charges')->insert(['invoice_id' => $i + 1, 'rail' => 'pix', 'efi_charge_id' => 'tx' . $i, 'status' => $status, 'amount_cents' => 100, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::ensure();

        self::assertSame($legacy, Capsule::table('mod_efi_charges')->orderBy('id')->pluck('status')->all());
        self::assertSame(3, (int) Capsule::table('mod_efi_schema_version')->where('module', 'efi_ledger')->max('version'));

        // Insert-only: a mesma fatura pode ganhar uma segunda emissão no mesmo trilho.
        Capsule::table('mod_efi_charges')->insert(['invoice_id' => 1, 'rail' => 'pix', 'efi_charge_id' => 'tx-new', 'status' => 'ATIVA', 'amount_cents' => 100, 'created_at' => $now, 'updated_at' => $now]);
        self::assertSame(2, Capsule::table('mod_efi_charges')->where('invoice_id', 1)->count());
    }
}
