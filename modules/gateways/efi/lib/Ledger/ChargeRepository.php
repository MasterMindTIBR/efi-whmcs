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
            ->where(function ($query) use ($rail): void {
                $query->whereIn('status', self::ACTIVE_STATUSES);

                // A Pix removal is terminal at Efí, but not for the invoice lifecycle: an
                // invoice can be reopened and must then receive a fresh charge rather than a
                // dead QR code. Keep the historical row addressable until reissue() supersedes
                // it. Other rails do not have this provider state.
                if ($rail === 'pix') {
                    $query->orWhere('status', 'like', 'REMOVIDA%');
                }
            })->orderByDesc('id')->first();
    }

    public static function findByEfiChargeId(string $efiChargeId): ?object
    {
        return Capsule::table('mod_efi_charges')->where('efi_charge_id', $efiChargeId)->first();
    }

    /** Última emissão vigente da fatura neste trilho, em qualquer status (inclusive paga); ignora as substituídas. */
    public static function latestForInvoice(int $invoiceId, string $rail): ?object
    {
        return Capsule::table('mod_efi_charges')->where('invoice_id', $invoiceId)->where('rail', $rail)
            ->where('status', '!=', 'superseded')->orderByDesc('id')->first();
    }

    /**
     * Soma um estorno ao total estornado da cobrança (metadata.refunded_cents) e devolve o novo
     * total. Lê e grava sob `SELECT ... FOR UPDATE`: dois estornos simultâneos não perdem
     * um incremento.
     */
    public static function addRefundedCents(int $chargeId, int $cents): int
    {
        return (int) Capsule::connection()->transaction(static function () use ($chargeId, $cents): int {
            $row = Capsule::table('mod_efi_charges')->where('id', $chargeId)->lockForUpdate()->first(['metadata', 'amount_cents']);
            $metadata = json_decode((string) ($row->metadata ?? ''), true) ?: [];
            $total = (int) ($metadata['refunded_cents'] ?? 0) + $cents;
            $metadata['refunded_cents'] = $total;
            Capsule::table('mod_efi_charges')->where('id', $chargeId)->update([
                'metadata' => json_encode($metadata) ?: '{}',
                'status' => $total >= (int) ($row->amount_cents ?? 0) ? 'refunded' : 'partially_refunded',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $total;
        });
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

    public static function findByPixEndToEndId(string $endToEndId): ?object
    {
        return Capsule::table('mod_efi_charges')->where('rail', 'pix')
            ->where('metadata', 'like', '%"e2e_id":"' . addcslashes($endToEndId, '\\%_') . '"%')
            ->orderByDesc('id')->first();
    }
}
