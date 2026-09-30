<?php

namespace EfiWhmcs\PixAutomatic;

use WHMCS\Database\Capsule;

/**
 * Ledger exclusivo do Pix Automático. A recorrência pertence ao cliente; cada cobrança recorrente
 * pertence a uma fatura WHMCS. Mantê-los separados de mod_efi_charges evita colidir com o Pix
 * imediato, que pode permanecer ativo como outro método de pagamento.
 */
final class Schema
{
    public static function ensure(): void
    {
        if (!Capsule::schema()->hasTable('mod_efi_pix_auto_recurrences')) {
            Capsule::schema()->create('mod_efi_pix_auto_recurrences', function ($table) {
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
            Capsule::schema()->create('mod_efi_pix_auto_charges', function ($table) {
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
            Capsule::schema()->create('mod_efi_pix_auto_events', function ($table) {
                $table->increments('id');
                $table->string('resource_type', 16);
                $table->unsignedInteger('resource_id');
                $table->string('efi_event_key', 128);
                $table->string('status_from', 32)->nullable();
                $table->string('status_to', 32);
                $table->dateTime('received_at');
                $table->unique(['resource_type', 'resource_id', 'efi_event_key'], 'uniq_efi_pix_auto_event');
            });
        }
    }
}
