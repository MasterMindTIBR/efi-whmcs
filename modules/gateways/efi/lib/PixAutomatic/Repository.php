<?php

namespace EfiWhmcs\PixAutomatic;

use WHMCS\Database\Capsule;

final class Repository
{
    public static function findRecurrenceByClient(int $clientId): ?object
    {
        return Capsule::table('mod_efi_pix_auto_recurrences')->where('client_id', $clientId)->first();
    }

    public static function findRecurrenceByInitialTxid(string $txid): ?object
    {
        return Capsule::table('mod_efi_pix_auto_recurrences')->where('initial_txid', $txid)->first();
    }

    public static function findRecurrenceByInitialInvoice(int $invoiceId): ?object
    {
        return Capsule::table('mod_efi_pix_auto_recurrences')->where('initial_invoice_id', $invoiceId)->first();
    }

    public static function findRecurrenceByEfiId(string $efiRecurrenceId): ?object
    {
        return Capsule::table('mod_efi_pix_auto_recurrences')->where('efi_recurrence_id', $efiRecurrenceId)->first();
    }

    /** @return iterable<object> */
    public static function pendingRecurrences(): iterable
    {
        return Capsule::table('mod_efi_pix_auto_recurrences')
            ->where('status', 'CRIADA')
            ->whereNotNull('efi_recurrence_id')
            ->get();
    }

    public static function createRecurrence(array $attributes): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_efi_pix_auto_recurrences')->insertGetId($attributes + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function updateRecurrence(int $id, array $attributes): void
    {
        Capsule::table('mod_efi_pix_auto_recurrences')->where('id', $id)->update($attributes + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function findChargeByInvoice(int $invoiceId): ?object
    {
        return Capsule::table('mod_efi_pix_auto_charges')->where('invoice_id', $invoiceId)->first();
    }

    public static function findChargeByEfiId(string $txid): ?object
    {
        return Capsule::table('mod_efi_pix_auto_charges')->where('efi_charge_id', $txid)->first();
    }

    public static function createCharge(array $attributes): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_efi_pix_auto_charges')->insertGetId($attributes + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function deleteCharge(int $id): void
    {
        Capsule::table('mod_efi_pix_auto_charges')->where('id', $id)->delete();
    }

    public static function updateCharge(int $id, array $attributes): void
    {
        Capsule::table('mod_efi_pix_auto_charges')->where('id', $id)->update($attributes + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function tryClaimEvent(string $resourceType, int $resourceId, string $eventKey, ?string $statusFrom, string $statusTo): ?int
    {
        try {
            return (int) Capsule::table('mod_efi_pix_auto_events')->insertGetId([
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'efi_event_key' => $eventKey,
                'status_from' => $statusFrom,
                'status_to' => $statusTo,
                'status' => 'pending',
                'received_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $code = (string) $e->getCode();

            if ($code !== '23000' && $code !== '1062') {
                throw $e;
            }

            $existing = Capsule::table('mod_efi_pix_auto_events')
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where('efi_event_key', $eventKey)
                ->first(['id', 'status']);

            return $existing !== null && $existing->status === 'pending' ? (int) $existing->id : null;
        }
    }

    public static function markEventApplied(int $id): void
    {
        Capsule::table('mod_efi_pix_auto_events')->where('id', $id)->update(['status' => 'applied']);
    }

    public static function markEventFailed(int $id): void
    {
        Capsule::table('mod_efi_pix_auto_events')->where('id', $id)->update(['status' => 'failed']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function pendingChargeEventsOlderThan(int $minutes): \Illuminate\Support\Collection
    {
        return Capsule::table('mod_efi_pix_auto_events as e')
            ->join('mod_efi_pix_auto_charges as c', 'c.id', '=', 'e.resource_id')
            ->where('e.resource_type', 'charge')->where('e.status', 'pending')
            ->where('e.received_at', '<', date('Y-m-d H:i:s', strtotime('-' . max(1, $minutes) . ' minutes')))
            ->select('c.*')->get();
    }
}
