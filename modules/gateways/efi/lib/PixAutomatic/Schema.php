<?php

declare(strict_types=1);

namespace EfiWhmcs\PixAutomatic;

use WHMCS\Database\Capsule;

/** Creates and upgrades Pix Automático's recurrence and event ledger once per process. */
final class Schema
{
    private const MODULE = 'efi_pix_automatico';
    private static bool $ensured = false;

    /** Creates the Pix Automático tables and runs its durable schema migrations. */
    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        \EfiWhmcs\Ledger\Schema::ensure();
        self::createTables();
        self::migrate(1, [self::class, 'migrateEventLifecycle']);
        self::$ensured = true;
    }

    /** Test-only escape hatch for a newly-created isolated database. */
    public static function resetForTests(): void
    {
        self::$ensured = false;
    }

    private static function createTables(): void
    {
        if (!Capsule::schema()->hasTable('mod_efi_pix_auto_recurrences')) {
            Capsule::schema()->create('mod_efi_pix_auto_recurrences', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('client_id')->unique();
                $table->unsignedInteger('initial_invoice_id')->unique();
                $table->string('efi_recurrence_id', 64)->nullable()->unique();
                $table->string('initial_txid', 64)->nullable()->unique();
                $table->string('loc_id', 64)->nullable();
                $table->string('status', 32)->default('new');
                $table->unsignedInteger('amount_cents');
                $table->text('metadata')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index('status', 'idx_efi_pix_auto_recurrence_status');
            });
        }

        if (!Capsule::schema()->hasTable('mod_efi_pix_auto_charges')) {
            Capsule::schema()->create('mod_efi_pix_auto_charges', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('recurrence_id');
                $table->unsignedInteger('invoice_id')->unique();
                $table->string('efi_charge_id', 64)->unique();
                $table->string('status', 32)->default('new');
                $table->unsignedInteger('amount_cents');
                $table->date('due_date');
                $table->text('metadata')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['recurrence_id', 'status'], 'idx_efi_pix_auto_charge_recurrence_status');
            });
        }

        if (!Capsule::schema()->hasTable('mod_efi_pix_auto_events')) {
            Capsule::schema()->create('mod_efi_pix_auto_events', static function ($table): void {
                $table->increments('id');
                $table->string('resource_type', 16);
                $table->unsignedInteger('resource_id');
                $table->string('efi_event_key', 128);
                $table->string('status_from', 32)->nullable();
                $table->string('status_to', 32);
                $table->enum('status', ['pending', 'applied', 'failed'])->default('pending');
                $table->dateTime('received_at');
                $table->unique(['resource_type', 'resource_id', 'efi_event_key'], 'uniq_efi_pix_auto_event');
                $table->index(['status', 'received_at'], 'idx_efi_pix_auto_event_pending');
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

    private static function migrateEventLifecycle(): void
    {
        if (!Capsule::schema()->hasColumn('mod_efi_pix_auto_events', 'status')) {
            Capsule::schema()->table('mod_efi_pix_auto_events', static function ($table): void {
                // Existing events belong to the pre-retry ledger; do not replay them blindly.
                $table->enum('status', ['pending', 'applied', 'failed'])->default('applied');
            });
        }

        if (Capsule::connection()->getDriverName() === 'mysql') {
            Capsule::statement("ALTER TABLE mod_efi_pix_auto_events MODIFY status ENUM('pending','applied','failed') NOT NULL DEFAULT 'pending'");
        }
    }
}
