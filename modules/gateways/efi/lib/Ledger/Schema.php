<?php

namespace EfiWhmcs\Ledger;

use WHMCS\Database\Capsule;

/**
 * Cria as duas tabelas compartilhadas pelos três módulos (mod_efi_charges, mod_efi_events).
 * Chamado a partir de `_config_validate` / `_activate` de qualquer um dos módulos -- é seguro
 * chamar repetidas vezes (idempotente via `hasTable`).
 *
 * Nenhuma tabela própria de cartão é criada aqui: o token fica no armazenamento nativo de
 * métodos de pagamento do WHMCS (ver docs/ARQUITETURA.md §1.2/§3.2).
 */
final class Schema
{
    public static function ensure(): void
    {
        self::ensureCharges();
        self::ensureEvents();
    }

    private static function ensureCharges(): void
    {
        if (Capsule::schema()->hasTable('mod_efi_charges')) {
            return;
        }

        Capsule::schema()->create('mod_efi_charges', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->enum('rail', ['boleto', 'pix', 'cartao']);
            $table->string('efi_charge_id', 64)->nullable();
            $table->string('efi_loc_id', 64)->nullable();
            $table->string('status', 32)->default('new');
            $table->unsignedInteger('amount_cents');
            $table->smallInteger('due_offset_days')->nullable();
            $table->text('metadata')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['invoice_id', 'rail'], 'uniq_invoice_rail');
            $table->unique('efi_charge_id', 'uniq_efi_charge');
            $table->index('status', 'idx_status');
        });
    }

    private static function ensureEvents(): void
    {
        if (Capsule::schema()->hasTable('mod_efi_events')) {
            return;
        }

        Capsule::schema()->create('mod_efi_events', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('charge_id');
            // string, não inteiro: cobre tanto o `id` sequencial da Efí (Cobranças/boleto e
            // cartão) quanto o `e2eid` (Pix), que não é numérico.
            $table->string('efi_event_key', 64);
            $table->string('status_from', 32)->nullable();
            $table->string('status_to', 32);
            $table->dateTime('received_at');

            $table->unique(['charge_id', 'efi_event_key'], 'uniq_charge_event');
        });
    }
}
