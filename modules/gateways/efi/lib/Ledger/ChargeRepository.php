<?php

namespace EfiWhmcs\Ledger;

use WHMCS\Database\Capsule;

/**
 * Fonte única de verdade sobre "qual cobrança Efí pertence a qual fatura WHMCS, e de que tipo
 * (boleto/pix/cartão)". Substitui o proxy frágil do módulo antigo de olhar a última transação
 * WHMCS do invoice.
 */
final class ChargeRepository
{
    public static function findByInvoiceAndRail(int $invoiceId, string $rail): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_charges')
            ->where('invoice_id', $invoiceId)
            ->where('rail', $rail)
            ->first();
    }

    public static function findByEfiChargeId(string $efiChargeId): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_charges')
            ->where('efi_charge_id', $efiChargeId)
            ->first();
    }

    public static function create(array $attributes): int
    {
        Schema::ensure();

        $now = date('Y-m-d H:i:s');

        return Capsule::table('mod_efi_charges')->insertGetId(array_merge($attributes, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * Cria a cobrança da fatura/trilho, ou atualiza a já existente (ver `UNIQUE(invoice_id,
     * rail)` em `Schema`) -- necessário para reemitir um boleto depois de cancelado: um segundo
     * `create()` para o mesmo par violaria a constraint, deixando a reemissão travada mesmo
     * sendo exatamente o caso que `issueOrFetch()` pretende cobrir.
     */
    public static function upsert(int $invoiceId, string $rail, array $attributes): int
    {
        Schema::ensure();

        $now = date('Y-m-d H:i:s');
        $existing = Capsule::table('mod_efi_charges')->where('invoice_id', $invoiceId)->where('rail', $rail)->first();

        if ($existing !== null) {
            Capsule::table('mod_efi_charges')->where('id', $existing->id)->update($attributes + ['updated_at' => $now]);

            return (int) $existing->id;
        }

        return Capsule::table('mod_efi_charges')->insertGetId($attributes + [
            'invoice_id' => $invoiceId,
            'rail' => $rail,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function updateStatus(int $id, string $status, ?string $efiChargeId = null): void
    {
        Schema::ensure();

        $update = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($efiChargeId !== null) {
            $update['efi_charge_id'] = $efiChargeId;
        }

        Capsule::table('mod_efi_charges')->where('id', $id)->update($update);
    }

    public static function updateLocId(int $id, string $locId): void
    {
        Schema::ensure();

        Capsule::table('mod_efi_charges')->where('id', $id)->update([
            'efi_loc_id' => $locId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function replacePixCharge(
        int $id,
        string $efiChargeId,
        ?string $efiLocId,
        string $status,
        int $amountCents,
        string $metadata
    ): void {
        Schema::ensure();

        Capsule::table('mod_efi_charges')->where('id', $id)->update([
            'efi_charge_id' => $efiChargeId,
            'efi_loc_id' => $efiLocId,
            'status' => $status,
            'amount_cents' => $amountCents,
            'metadata' => $metadata,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function updateMetadata(int $id, string $metadata): void
    {
        Schema::ensure();

        Capsule::table('mod_efi_charges')->where('id', $id)->update([
            'metadata' => $metadata,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
