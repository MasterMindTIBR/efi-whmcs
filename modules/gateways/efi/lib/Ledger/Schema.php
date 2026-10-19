<?php

declare(strict_types=1);

namespace EfiWhmcs\Ledger;

use WHMCS\Database\Capsule;

/** Creates and upgrades the shared Efí payment ledger. */
final class Schema
{
    private const MODULE = 'efi_ledger';
    private static bool $ensured = false;

    /** Creates the ledger tables and applies durable migrations once per process. */
    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        self::ensureVersionTable();
        self::ensureCharges();
        self::ensureEvents();
        self::migrate(1, [self::class, 'migrateInsertOnlyCharges']);
        self::migrate(2, [self::class, 'migrateEventLifecycle']);
        self::migrate(3, [self::class, 'migratePixRemovalStatuses']);
        self::$ensured = true;
    }

    /** Allows each isolated in-memory test database to run the migrations. */
    public static function resetForTests(): void
    {
        self::$ensured = false;
    }

    private static function ensureVersionTable(): void
    {
        if (!Capsule::schema()->hasTable('mod_efi_schema_version')) {
            Capsule::schema()->create('mod_efi_schema_version', function ($table) {
                $table->string('module', 64);
                $table->unsignedInteger('version');
                $table->dateTime('applied_at');
                $table->primary(['module', 'version']);
            });
        }
    }

    /** @param callable():void $migration */
    private static function migrate(int $version, callable $migration): void
    {
        if (Capsule::table('mod_efi_schema_version')->where('module', self::MODULE)->where('version', $version)->exists()) {
            return;
        }
        $migration();
        Capsule::table('mod_efi_schema_version')->insert([
            'module' => self::MODULE,
            'version' => $version,
            'applied_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function ensureCharges(): void
    {
        if (Capsule::schema()->hasTable('mod_efi_charges')) { return; }
        Capsule::schema()->create('mod_efi_charges', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->enum('rail', ['boleto', 'pix', 'cartao']);
            $table->string('efi_charge_id', 64)->nullable()->unique('uniq_efi_charge');
            $table->string('efi_loc_id', 64)->nullable();
            // Deliberately a string, not an enum: provider states are extended over time.
            $table->string('status', 32)->default('new');
            $table->unsignedInteger('amount_cents');
            $table->smallInteger('due_offset_days')->nullable();
            $table->text('metadata')->nullable();
            $table->unsignedInteger('superseded_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['invoice_id', 'rail'], 'idx_invoice_rail');
            $table->index('status', 'idx_status');
            $table->foreign('superseded_by', 'fk_efi_charge_superseded_by')->references('id')->on('mod_efi_charges')->nullOnDelete();
        });
    }

    private static function ensureEvents(): void
    {
        if (Capsule::schema()->hasTable('mod_efi_events')) { return; }
        Capsule::schema()->create('mod_efi_events', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('charge_id');
            $table->string('efi_charge_id', 64)->nullable();
            $table->string('efi_event_key', 64);
            $table->string('status_from', 32)->nullable();
            $table->string('status_to', 32);
            $table->enum('status', ['pending', 'applied', 'failed'])->default('pending');
            $table->dateTime('received_at');
            $table->unique(['charge_id', 'efi_event_key'], 'uniq_charge_event');
            $table->index(['status', 'received_at'], 'idx_efi_event_pending');
        });
    }

    private static function migrateInsertOnlyCharges(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasColumn('mod_efi_charges', 'superseded_by')) {
            $schema->table('mod_efi_charges', function ($table) { $table->unsignedInteger('superseded_by')->nullable(); });
        }
        try {
            $schema->table('mod_efi_charges', function ($table) {
                $table->foreign('superseded_by', 'fk_efi_charge_superseded_by')->references('id')->on('mod_efi_charges')->nullOnDelete();
            });
        } catch (\Throwable) {}
        try { $schema->table('mod_efi_charges', function ($table) { $table->dropUnique('uniq_invoice_rail'); }); } catch (\Throwable) {}
        try { $schema->table('mod_efi_charges', function ($table) { $table->index(['invoice_id', 'rail'], 'idx_invoice_rail'); }); } catch (\Throwable) {}
        if (Capsule::connection()->getDriverName() === 'mysql') {
            Capsule::statement("ALTER TABLE mod_efi_charges MODIFY status ENUM('new','waiting','unpaid','ATIVA','creating','approved','unknown','failed-unknown','paid','CONCLUIDA','canceled','expired','superseded') NOT NULL DEFAULT 'new'");
        }
    }

    private static function migrateEventLifecycle(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasColumn('mod_efi_events', 'efi_charge_id')) {
            $schema->table('mod_efi_events', function ($table) { $table->string('efi_charge_id', 64)->nullable(); });
            foreach (Capsule::table('mod_efi_events as e')->join('mod_efi_charges as c', 'c.id', '=', 'e.charge_id')->get(['e.id', 'c.efi_charge_id']) as $event) {
                Capsule::table('mod_efi_events')->where('id', $event->id)->update(['efi_charge_id' => $event->efi_charge_id]);
            }
        }
        if (!$schema->hasColumn('mod_efi_events', 'status')) {
            // Existing effects are applied unless a provider-paid charge lacks its canonical
            // account transaction, in which case cron can recover it as pending work.
            $schema->table('mod_efi_events', function ($table) { $table->enum('status', ['pending', 'applied', 'failed'])->default('applied'); });
            $paidEvents = Capsule::table('mod_efi_events as e')->join('mod_efi_charges as c', 'c.id', '=', 'e.charge_id')
                ->whereIn('e.status_to', ['paid', 'settled', 'CONCLUIDA'])
                ->get(['e.id', 'c.rail', 'c.invoice_id', 'c.efi_charge_id']);
            foreach ($paidEvents as $event) {
                // Historical Pix transactions store e2eId, never txid, so matching txid here
                // would incorrectly label every old Pix payment as safely applied.
                $hasPayment = (string) $event->rail !== 'pix'
                    && Capsule::table('tblaccounts')->where('invoiceid', $event->invoice_id)
                        ->where('transid', (string) $event->efi_charge_id)->exists();
                if (!$hasPayment) {
                    Capsule::table('mod_efi_events')->where('id', $event->id)->update(['status' => 'pending']);
                }
            }
        }
        foreach (Capsule::table('mod_efi_charges')->whereIn('rail', ['boleto', 'cartao'])
            ->whereIn('status', ['new', 'waiting', 'unpaid', 'approved'])->whereNotNull('efi_charge_id')->get() as $charge) {
            if (Capsule::table('tblaccounts')->where('invoiceid', $charge->invoice_id)->where('transid', $charge->efi_charge_id)->exists()) {
                Capsule::table('mod_efi_charges')->where('id', $charge->id)->update(['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        try { $schema->table('mod_efi_events', function ($table) { $table->index(['status', 'received_at'], 'idx_efi_event_pending'); }); } catch (\Throwable) {}
    }

    /** Old installations may still have the pre-Rock-1 MySQL enum. */
    private static function migratePixRemovalStatuses(): void
    {
        if (Capsule::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Capsule::statement("ALTER TABLE mod_efi_charges MODIFY status VARCHAR(32) NOT NULL DEFAULT 'new'");
    }
}
