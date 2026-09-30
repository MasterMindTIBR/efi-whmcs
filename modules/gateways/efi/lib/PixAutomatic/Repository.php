<?php

namespace EfiWhmcs\PixAutomatic;

use WHMCS\Database\Capsule;

final class Repository
{
    public static function findRecurrenceByClient(int $clientId): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_recurrences')->where('client_id', $clientId)->first();
    }

    public static function findRecurrenceByInitialTxid(string $txid): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_recurrences')->where('initial_txid', $txid)->first();
    }

    public static function findRecurrenceByInitialInvoice(int $invoiceId): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_recurrences')->where('initial_invoice_id', $invoiceId)->first();
    }

    public static function findRecurrenceByEfiId(string $efiRecurrenceId): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_recurrences')->where('efi_recurrence_id', $efiRecurrenceId)->first();
    }

    /** @return iterable<object> */
    public static function pendingRecurrences(): iterable
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_recurrences')
            ->where('status', 'CRIADA')
            ->whereNotNull('efi_recurrence_id')
            ->get();
    }

    public static function createRecurrence(array $attributes): int
    {
        Schema::ensure();
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_efi_pix_auto_recurrences')->insertGetId($attributes + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function updateRecurrence(int $id, array $attributes): void
    {
        Schema::ensure();
        Capsule::table('mod_efi_pix_auto_recurrences')->where('id', $id)->update($attributes + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function findChargeByInvoice(int $invoiceId): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_charges')->where('invoice_id', $invoiceId)->first();
    }

    public static function findChargeByEfiId(string $txid): ?object
    {
        Schema::ensure();

        return Capsule::table('mod_efi_pix_auto_charges')->where('efi_charge_id', $txid)->first();
    }

    public static function createCharge(array $attributes): int
    {
        Schema::ensure();
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_efi_pix_auto_charges')->insertGetId($attributes + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function updateCharge(int $id, array $attributes): void
    {
        Schema::ensure();
        Capsule::table('mod_efi_pix_auto_charges')->where('id', $id)->update($attributes + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function tryClaimEvent(string $resourceType, int $resourceId, string $eventKey, ?string $statusFrom, string $statusTo): bool
    {
        Schema::ensure();

        try {
            Capsule::table('mod_efi_pix_auto_events')->insert([
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'efi_event_key' => $eventKey,
                'status_from' => $statusFrom,
                'status_to' => $statusTo,
                'received_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
