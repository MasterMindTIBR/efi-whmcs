<?php

/**
 * Callback de Pix imediato Efí.
 *
 * Uma chave Pix só pode ter uma URL de webhook. Por isso este endpoint também processa o pagamento
 * inicial da jornada 3 do Pix Automático; os callbacks /rec e /cobr continuam em
 * efi_pix_automatico.php. Todo pagamento é confirmado na API Efí antes de alterar uma fatura.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Pix\PixService;
use EfiWhmcs\PixAutomatic\NotificationProcessor as PixAutomaticNotificationProcessor;
use EfiWhmcs\PixAutomatic\PixAutomaticService;
use EfiWhmcs\PixAutomatic\Repository as PixAutomaticRepository;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLoader;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\PaymentApplier;
use EfiWhmcs\Support\WebhookIpAllowlist;
use EfiWhmcs\Webhook\PixNotificationProcessor;
use WHMCS\Database\Capsule;

App::load_function('invoice');

$pixParams = GatewayLoader::variables('efi_pix') ?? [];
$automaticParams = GatewayLoader::variables(PixAutomaticService::GATEWAY) ?? [];
$providedSecret = (string) ($_GET['ws'] ?? '');
$validSecrets = [];

foreach ([['efi_pix', $pixParams], [PixAutomaticService::GATEWAY, $automaticParams]] as [$module, $params]) {
    $secret = trim((string) ($params['webhook_secret'] ?? ''));
    if (!empty($params['type']) && $secret !== '' && hash_equals($secret, $providedSecret)) {
        $validSecrets[$module] = true;
    }
}

if ($validSecrets === []) {
    GatewayLog::error('efi_pix', $pixParams, 'Webhook Pix rejeitado: segredo ausente/incorreto', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(200);
    echo '200';
    exit;
}

$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (!WebhookIpAllowlist::allows($remoteAddr, $pixParams, $automaticParams)) {
    GatewayLog::error('efi_pix', $pixParams, 'Webhook Pix rejeitado: IP de origem fora da lista permitida (mTLS pulado)', ['ip' => $remoteAddr]);
    http_response_code(WebhookIpAllowlist::responseStatus(false));
    echo '403';
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

if (!empty($pixParams['type'])) {
    try {
        $api = EfiClientFactory::make($pixParams, true);
        $transitions = (new PixNotificationProcessor($api, $pixParams))->fetchNewTransitions($rawBody);

        foreach ($transitions as $transition) {
            $charge = $transition['charge'];
            $invoiceId = (int) $charge->invoice_id;
            logTransaction('efi_pix', [
                'invoice_id' => $invoiceId,
                'txid' => $charge->efi_charge_id,
                'e2eId' => $transition['e2eId'],
                'status' => $transition['status'],
            ], 'Notificação Pix: ' . $transition['status']);

            $metadata = json_decode((string) $charge->metadata, true) ?: [];
            $metadata['e2e_id'] = $transition['e2eId'];
            ChargeRepository::updateMetadata((int) $charge->id, json_encode($metadata) ?: '{}');

            $pixService = new PixService($api, $pixParams);

            PaymentApplier::apply([
                'invoiceId' => $invoiceId, 'transId' => $transition['e2eId'],
                'amountCents' => (int) $transition['valueCents'], 'module' => 'efi_pix',
                'gatewayParams' => $pixParams, 'chargeId' => (int) $charge->id,
                'eventId' => (int) $transition['eventId'],
                'markPaid' => static function () use ($pixService, $invoiceId, $transition, $charge): void {
                    $pixService->applyDiscountLineItem($invoiceId, (int) $transition['valueCents']);
                    ChargeRepository::updateStatus((int) $charge->id, 'CONCLUIDA');
                },
            ]);
        }
    } catch (\Throwable $e) {
        GatewayLog::error('efi_pix', $pixParams, 'Falha ao processar webhook Pix', $e->getMessage());
    }
}

if (!empty($automaticParams['type'])) {
    try {
        $api = EfiClientFactory::make($automaticParams, true);
        $transitions = (new PixAutomaticNotificationProcessor($api, $automaticParams))->initialPaymentTransitions($rawBody);

        foreach ($transitions as $transition) {
            $recurrence = $transition['recurrence'];
            $invoiceId = (int) $recurrence->initial_invoice_id;
            PaymentApplier::apply([
                'invoiceId' => $invoiceId, 'transId' => $transition['e2eId'],
                'amountCents' => (int) $transition['amountCents'], 'module' => PixAutomaticService::GATEWAY,
                'gatewayParams' => $automaticParams,
                'markApplied' => static function () use ($transition): void { PixAutomaticRepository::markEventApplied((int) $transition['eventId']); },
            ]);

            $metadata = json_decode((string) $recurrence->metadata, true) ?: [];
            $metadata['initial_e2e_id'] = $transition['e2eId'];
            PixAutomaticRepository::updateRecurrence((int) $recurrence->id, ['metadata' => json_encode($metadata) ?: '{}']);
            logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $invoiceId, 'txid' => $recurrence->initial_txid, 'e2eId' => $transition['e2eId']], 'Pagamento inicial do Pix Automático concluído');
        }
    } catch (\Throwable $e) {
        GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Falha ao processar Pix inicial do Pix Automático', $e->getMessage());
    }
}

http_response_code(200);
echo '200';
