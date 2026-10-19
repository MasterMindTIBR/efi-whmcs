<?php

declare(strict_types=1);

namespace EfiWhmcs\Ledger;

use WHMCS\Database\Capsule;

final class ChargeRepository
{
    private const ACTIVE_STATUSES = ['new', 'waiting', 'unpaid', 'ATIVA', 'creating', 'approved', 'unknown', 'failed-unknown'];

    public static function findByInvoiceAndRail(int $invoiceId, string $rail): ?object
    {
        return Capsule::table('mod_efi_charges')->where('invoice_id', $invoiceId)->where('rail', $rail)
            ->whereIn('status', self::ACTIVE_STATUSES)->orderByDesc('id')->first();
    }

    public static function findByEfiChargeId(string $efiChargeId): ?object
    {
        return Capsule::table('mod_efi_charges')->where('efi_charge_id', $efiChargeId)->first();
    }

    public static function create(array $attributes): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) Capsule::table('mod_efi_charges')->insertGetId($attributes + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** Supersedes the preceding unresolved issuance and inserts an immutable new one. */
    public static function reissue(int $invoiceId, string $rail, array $attributes): int
    {
        $old = self::findByInvoiceAndRail($invoiceId, $rail);
        $newId = self::create($attributes + ['invoice_id' => $invoiceId, 'rail' => $rail]);
        if ($old !== null) {
            Capsule::table('mod_efi_charges')->where('id', $old->id)->update(['status' => 'superseded', 'superseded_by' => $newId, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        return $newId;
    }

    /** Caller holds the named invoice lock before reserving provider issuance. */
    public static function reserveCreating(int $invoiceId, string $rail, int $amountCents): int
    {
        return self::reissue($invoiceId, $rail, ['status' => 'creating', 'amount_cents' => $amountCents]);
    }

    public static function updateStatus(int $id, string $status, ?string $efiChargeId = null): void
    {
        $update = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($efiChargeId !== null) { $update['efi_charge_id'] = $efiChargeId; }
        Capsule::table('mod_efi_charges')->where('id', $id)->update($update);
    }

    public static function updateLocId(int $id, string $locId): void
    {
        Capsule::table('mod_efi_charges')->where('id', $id)->update(['efi_loc_id' => $locId, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** Updates all mutable facts for a Pix charge whose provider identifier is unchanged. */
    public static function replacePixCharge(
        int $id,
        string $efiChargeId,
        ?string $efiLocId,
        string $status,
        int $amountCents,
        string $metadata
    ): void {
        Capsule::table('mod_efi_charges')->where('id', $id)->update([
            'efi_charge_id' => $efiChargeId,
            'efi_loc_id' => $efiLocId,
            'status' => $status,
            'amount_cents' => $amountCents,
            'metadata' => $metadata,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Updates facts for the same external charge; a new provider id must use reissue(). */
    public static function updateCharge(int $id, array $attributes): void
    {
        unset($attributes['efi_charge_id']);
        Capsule::table('mod_efi_charges')->where('id', $id)->update($attributes + ['updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function updateMetadata(int $id, string $metadata): void
    {
        Capsule::table('mod_efi_charges')->where('id', $id)->update(['metadata' => $metadata, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
