<?php

namespace EfiWhmcs\Webhook;

use Efi\EfiPay;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Ledger\EventRepository;
use EfiWhmcs\Support\GatewayLog;

/**
 * Processador de notificação compartilhado por `efi_boleto` e `efi_cartao` (API de Cobranças).
 *
 * Modelo de autenticidade da própria Efí: o POST recebido carrega SOMENTE um token opaco em
 * `$_POST['notification']`. Os dados reais só existem depois de uma consulta autenticada
 * (`getNotification`) com nossas credenciais -- portanto um invasor que faça POST de um token
 * arbitrário não consegue forjar um pagamento, o pior que consegue é uma consulta que falha ou
 * retorna dados de uma cobrança nossa legítima (nunca dados fabricados). Isso substitui, de
 * forma estrutural, qualquer necessidade de HMAC manual para este fluxo.
 *
 * Processa TODOS os eventos do array (não só o último -- a própria Efí avisa que a ordem de
 * chegada de um POST pode agrupar mais de uma mudança de status), em ordem crescente de `id`,
 * e usa `mod_efi_events` para garantir que cada evento só produz efeito uma única vez.
 */
final class ChargeNotificationProcessor
{
    public function __construct(private readonly EfiPay $api, private readonly string $moduleName, private readonly array $gatewayParams)
    {
    }

    /**
     * @return array<int, array{charge: object, from: ?string, to: string, valueCents: ?int}>
     *         Lista de transições efetivamente novas (ainda não aplicadas) que o chamador deve
     *         processar (marcar fatura como paga, cancelada, etc.)
     */
    public function fetchNewTransitions(string $notificationToken): array
    {
        if (trim($notificationToken) === '') {
            throw new \InvalidArgumentException('Token de notificação vazio.');
        }

        $response = $this->api->getNotification(['token' => $notificationToken], []);
        $events = $response['data'] ?? null;

        if (!is_array($events) || $events === []) {
            GatewayLog::error($this->moduleName, $this->gatewayParams, 'Notificação sem eventos', $response);

            return [];
        }

        // Garante ordem crescente por `id` (documentado como sequencial por token).
        usort($events, static fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

        $transitions = [];

        foreach ($events as $event) {
            $chargeId = (string) ($event['identifiers']['charge_id'] ?? '');
            $eventId = (int) ($event['id'] ?? 0);
            $statusTo = (string) ($event['status']['current'] ?? '');
            $statusFrom = $event['status']['previous'] ?? null;

            if ($chargeId === '' || $eventId === 0 || $statusTo === '') {
                GatewayLog::error($this->moduleName, $this->gatewayParams, 'Evento malformado ignorado', $event);
                continue;
            }

            $charge = ChargeRepository::findByEfiChargeId($chargeId);

            if ($charge === null) {
                // Cobrança que a Efí conhece mas que não está no nosso ledger -- não foi criada
                // por este módulo (ou é de outra instalação). Nunca aplicamos efeito sem o
                // vínculo local.
                GatewayLog::error($this->moduleName, $this->gatewayParams, 'charge_id sem correspondência no ledger local', $event);
                continue;
            }

            $claimed = EventRepository::tryClaim($charge->id, (string) $eventId, $statusFrom, $statusTo);

            if (!$claimed) {
                // Já processado antes -- reentrega segura, no-op.
                continue;
            }

            $transitions[] = [
                'charge' => $charge,
                'from' => $statusFrom,
                'to' => $statusTo,
                'valueCents' => isset($event['value']) ? (int) $event['value'] : null,
                'receivedByBankAt' => $event['received_by_bank_at'] ?? null,
            ];
        }

        return $transitions;
    }
}
