<?php

namespace EfiWhmcs\Ledger;

use WHMCS\Database\Capsule;

/**
 * Garante idempotência real de processamento de notificação: cada (charge_id, efi_event_key) só
 * é aplicado uma vez, mesmo que a Efí reenvie a notificação (o que ela documenta que fará até
 * que o token/consulta seja concluído com sucesso).
 *
 * `efi_event_key` é o `id` sequencial da notificação (Cobranças: boleto/cartão) OU o `e2eid`
 * (Pix) -- ambos tratados como string opaca aqui.
 */
final class EventRepository
{
    /**
     * Tenta registrar o evento. Retorna true se este processo é quem deve aplicá-lo agora
     * (primeira vez), false se já havia sido processado antes (no-op seguro).
     */
    public static function tryClaim(int $chargeId, string $eventKey, ?string $statusFrom, string $statusTo): bool
    {
        Schema::ensure();

        try {
            Capsule::table('mod_efi_events')->insert([
                'charge_id' => $chargeId,
                'efi_event_key' => $eventKey,
                'status_from' => $statusFrom,
                'status_to' => $statusTo,
                'received_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Exception $e) {
            // Violação da UNIQUE(charge_id, efi_event_key) => evento já processado antes.
            return false;
        }
    }
}
