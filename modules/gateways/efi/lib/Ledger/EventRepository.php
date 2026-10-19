<?php

declare(strict_types=1);

namespace EfiWhmcs\Ledger;

use WHMCS\Database\Capsule;

/**
 * Garante idempotência real de processamento de notificação: cada (charge_id, efi_event_key) só
 * produz efeito uma única vez, mesmo que a Efí reenvie a notificação (o que ela documenta que
 * fará até que o token/consulta seja concluído com sucesso) ou que o cron reconcilie a mesma
 * cobrança mais de uma vez.
 *
 * `efi_event_key` é o `id` sequencial da notificação (Cobranças: boleto/cartão), o `e2eid`
 * (Pix) ou uma chave determinística de reconciliação (`'reconcile:' . charge_id`) -- sempre
 * tratado como string opaca aqui.
 */
final class EventRepository
{
    /**
     * Tenta registrar o evento com status `pending`. Retorna o id da linha a ser aplicada por
     * `PaymentApplier`, ou `null` SOMENTE quando a mesma chave já está `applied` (efeito já
     * produzido, no-op seguro). Uma colisão cuja linha existente ainda está `pending` (por
     * exemplo, uma tentativa anterior que falhou ao obter o lock da fatura e nunca marcou o
     * evento como aplicado) devolve o id dessa linha existente, para que o chamador tente
     * aplicar de novo -- é assim que o cron recupera um evento preso em `pending`.
     */
    public static function tryClaim(int $chargeId, string $eventKey, ?string $statusFrom, string $statusTo, ?string $efiChargeId = null): ?int
    {
        try {
            return (int) Capsule::table('mod_efi_events')->insertGetId([
                'charge_id' => $chargeId,
                'efi_charge_id' => $efiChargeId,
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

            $existing = Capsule::table('mod_efi_events')
                ->where('charge_id', $chargeId)
                ->where('efi_event_key', $eventKey)
                ->first(['id', 'status']);

            return $existing !== null && $existing->status === 'pending' ? (int) $existing->id : null;
        }
    }

    public static function markApplied(int $eventRowId): void
    {
        Capsule::table('mod_efi_events')->where('id', $eventRowId)->update(['status' => 'applied']);
    }

    /** Counts durable Pix-devolution events used to allocate a distinct refund attempt id. */
    public static function countByEventKeyPrefix(int $chargeId, string $prefix): int
    {
        return (int) Capsule::table('mod_efi_events')->where('charge_id', $chargeId)
            ->where('efi_event_key', 'like', addcslashes($prefix, '\\%_') . '%')->count();
    }

    /**
     * Atomically reserves the next Pix refund attempt. A duplicate-key retry is necessary even
     * with a count because two WHMCS refund requests can arrive at the same time.
     */
    public static function reserveNextDevolutionAttempt(int $chargeId, ?string $statusFrom, ?string $efiChargeId): int
    {
        for ($retries = 0; $retries < 10; $retries++) {
            $attempt = self::countByEventKeyPrefix($chargeId, 'devolucao:') + 1;

            try {
                Capsule::table('mod_efi_events')->insert([
                    'charge_id' => $chargeId,
                    'efi_charge_id' => $efiChargeId,
                    'efi_event_key' => 'devolucao:tentativa:' . $attempt,
                    'status_from' => $statusFrom,
                    'status_to' => 'SOLICITADA',
                    'status' => 'applied',
                    'received_at' => date('Y-m-d H:i:s'),
                ]);

                return $attempt;
            } catch (\Throwable $e) {
                $code = (string) $e->getCode();
                if ($code !== '23000' && $code !== '1062') {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Não foi possível reservar um id único para devolução Pix.');
    }

    /** @return iterable<object> */
    public static function pendingOlderThan(int $minutes): iterable
    {
        return Capsule::table('mod_efi_events')->where('status', 'pending')
            ->where('received_at', '<', date('Y-m-d H:i:s', strtotime('-' . max(1, $minutes) . ' minutes')))
            ->get();
    }
}
