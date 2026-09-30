<?php

namespace EfiWhmcs\PixAutomatic;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Support\GatewayLog;

/**
 * Callbacks Pix Automático não são fonte de verdade. O payload apenas identifica a recorrência ou
 * cobrança; antes de tocar uma fatura, o estado é consultado novamente na API Efí.
 */
final class NotificationProcessor
{
    public function __construct(private readonly EfiPay $api, private readonly array $gatewayParams)
    {
    }

    /** @return array<int, array{recurrence: object, e2eId: string, amountCents: int}> */
    public function initialPaymentTransitions(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        $entries = is_array($payload) ? ($payload['pix'] ?? []) : [];
        if (!is_array($entries)) {
            return [];
        }

        $transitions = [];
        foreach ($entries as $entry) {
            $txid = (string) ($entry['txid'] ?? '');
            $e2eId = (string) ($entry['endToEndId'] ?? '');
            if ($txid === '' || $e2eId === '') {
                continue;
            }

            $recurrence = Repository::findRecurrenceByInitialTxid($txid);
            if ($recurrence === null) {
                continue;
            }

            try {
                $detail = $this->api->pixDetailCharge(['txid' => $txid]);
            } catch (EfiException $e) {
                GatewayLog::error(PixAutomaticService::GATEWAY, $this->gatewayParams, 'Falha ao confirmar Pix inicial (' . $e->code . ')', $e->errorDescription);
                continue;
            }

            $received = $this->receivedPix($detail['pix'] ?? [], $e2eId);
            if ($received === null || (int) round(((float) $received['valor']) * 100) !== (int) $recurrence->amount_cents) {
                GatewayLog::error(PixAutomaticService::GATEWAY, $this->gatewayParams, 'Pix inicial não confere com a cobrança local', ['txid' => $txid, 'e2eId' => $e2eId]);
                continue;
            }

            if (!Repository::tryClaimEvent('recurrence', (int) $recurrence->id, 'initial:' . $e2eId, $recurrence->status, 'INITIAL_PAID')) {
                continue;
            }

            $transitions[] = ['recurrence' => $recurrence, 'e2eId' => $e2eId, 'amountCents' => (int) $recurrence->amount_cents];
        }

        return $transitions;
    }

    /** @return array<int, object> */
    public function recurrenceTransitions(string $rawBody): array
    {
        $ids = $this->identifiers($rawBody, 'idRec');
        $recurrences = $ids === []
            ? Repository::pendingRecurrences()
            : array_filter(array_map(Repository::findRecurrenceByEfiId(...), $ids));
        $transitions = [];

        foreach ($recurrences as $recurrence) {
            $idRec = (string) $recurrence->efi_recurrence_id;

            try {
                $detail = $this->api->pixDetailRecurrenceAutomatic(['idRec' => $idRec]);
            } catch (EfiException $e) {
                GatewayLog::error(PixAutomaticService::GATEWAY, $this->gatewayParams, 'Falha ao confirmar recorrência (' . $e->code . ')', $e->errorDescription);
                continue;
            }

            $status = (string) ($detail['status'] ?? $recurrence->status);
            $eventKey = $this->lastUpdateKey($detail['atualizacao'] ?? [], $status);
            if (!Repository::tryClaimEvent('recurrence', (int) $recurrence->id, $eventKey, $recurrence->status, $status)) {
                continue;
            }

            Repository::updateRecurrence((int) $recurrence->id, ['status' => $status]);
            $recurrence->status = $status;
            $transitions[] = $recurrence;
        }

        return $transitions;
    }

    /** @return array<int, array{charge: object, e2eId: string, amountCents: int}> */
    public function chargeTransitions(string $rawBody): array
    {
        $transitions = [];
        foreach ($this->identifiers($rawBody, 'txid') as $txid) {
            $charge = Repository::findChargeByEfiId($txid);
            if ($charge === null) {
                continue;
            }

            try {
                $detail = $this->api->pixDetailAutomaticCharge(['txid' => $txid]);
            } catch (EfiException $e) {
                GatewayLog::error(PixAutomaticService::GATEWAY, $this->gatewayParams, 'Falha ao confirmar cobrança recorrente (' . $e->code . ')', $e->errorDescription);
                continue;
            }

            $status = (string) ($detail['status'] ?? $charge->status);
            if ($status !== 'CONCLUIDA') {
                Repository::updateCharge((int) $charge->id, ['status' => $status]);
                continue;
            }

            $attempt = $this->settledAttempt($detail['tentativas'] ?? []);
            $e2eId = (string) ($attempt['endToEndId'] ?? '');
            if ($e2eId === '' || !Repository::tryClaimEvent('charge', (int) $charge->id, $e2eId, $charge->status, $status)) {
                continue;
            }

            Repository::updateCharge((int) $charge->id, ['status' => $status]);
            $transitions[] = ['charge' => $charge, 'e2eId' => $e2eId, 'amountCents' => (int) $charge->amount_cents];
        }

        return $transitions;
    }

    /** @return array<int, string> */
    private function identifiers(string $rawBody, string $key): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [];
        }

        $identifiers = [];
        $walk = function (array $node) use (&$walk, &$identifiers, $key): void {
            if (isset($node[$key]) && is_scalar($node[$key])) {
                $value = (string) $node[$key];
                if ($value !== '') {
                    $identifiers[$value] = true;
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload);

        return array_keys($identifiers);
    }

    private function receivedPix(array $received, string $e2eId): ?array
    {
        foreach ($received as $pix) {
            if (($pix['endToEndId'] ?? null) === $e2eId) {
                return $pix;
            }
        }

        return null;
    }

    private function settledAttempt(array $attempts): ?array
    {
        foreach ($attempts as $attempt) {
            if (($attempt['status'] ?? null) === 'LIQUIDADA' || ($attempt['status'] ?? null) === 'CONCLUIDA') {
                return $attempt;
            }
        }

        return null;
    }

    private function lastUpdateKey(array $updates, string $status): string
    {
        $last = end($updates);

        return $status . ':' . (string) ($last['data'] ?? $last['status'] ?? 'current');
    }
}
