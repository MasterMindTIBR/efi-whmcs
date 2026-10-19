<?php

/**
 * Webhook de status da recorrência (/rec) e das cobranças recorrentes (/cobr) do Pix Automático.
 * O endpoint Pix imediato compartilhado permanece em efi_pix.php, pois uma chave Pix aceita uma
 * única URL de webhook e a primeira fatura da jornada 3 é uma cobrança Pix normal.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\PixAutomatic\NotificationProcessor;
use EfiWhmcs\PixAutomatic\PixAutomaticService;
use EfiWhmcs\PixAutomatic\Repository;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLoader;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\PaymentApplier;
use EfiWhmcs\Support\WebhookIpAllowlist;
use WHMCS\Database\Capsule;

App::load_function('invoice');

$gatewayModuleName = PixAutomaticService::GATEWAY;
$gatewayParams = GatewayLoader::variables($gatewayModuleName);

if ($gatewayParams === null) {
    GatewayLog::error($gatewayModuleName, [], 'Callback recebido com o módulo inativo/não configurado');
    http_response_code(200);
    die('module not activated');
}

$expectedSecret = trim((string) ($gatewayParams['webhook_secret'] ?? ''));
$providedSecret = (string) ($_GET['ws'] ?? '');
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Webhook Pix Automático rejeitado: segredo ausente/incorreto', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(200);
    echo '200';
    exit;
}

$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (!WebhookIpAllowlist::allows($remoteAddr, $gatewayParams)) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Webhook Pix Automático rejeitado: IP de origem fora da lista permitida (mTLS pulado)', ['ip' => $remoteAddr]);
    http_response_code(WebhookIpAllowlist::responseStatus(false));
    echo '403';
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

try {
    $api = EfiClientFactory::make($gatewayParams, true);
    $processor = new NotificationProcessor($api, $gatewayParams);
    $recurrences = $processor->recurrenceTransitions($rawBody);
    $charges = $processor->chargeTransitions($rawBody);
} catch (\Throwable $e) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Falha ao processar webhook Pix Automático', $e->getMessage());
    http_response_code(200);
    echo '200';
    exit;
}

$service = new PixAutomaticService($api, $gatewayParams);
foreach ($recurrences as $recurrence) {
    logTransaction($gatewayModuleName, ['idRec' => $recurrence->efi_recurrence_id, 'status' => $recurrence->status], 'Notificação de recorrência Pix Automático: ' . $recurrence->status);

    if ($recurrence->status !== 'APROVADA') {
        continue;
    }

    $invoices = Capsule::table('tblinvoices')
        ->where('userid', $recurrence->client_id)
        ->where('paymentmethod', $gatewayModuleName)
        ->where('status', 'Unpaid')
        ->where('id', '!=', $recurrence->initial_invoice_id)
        ->get(['id', 'userid', 'total', 'duedate']);

    foreach ($invoices as $invoice) {
        try {
            $message = $service->scheduleInvoice($invoice);
            if ($message !== null) {
                logTransaction($gatewayModuleName, ['invoice_id' => $invoice->id], $message);
            }
        } catch (\Throwable $e) {
            GatewayLog::error($gatewayModuleName, $gatewayParams, 'Falha ao agendar fatura pendente após aprovação', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }
}

foreach ($charges as $transition) {
    $charge = $transition['charge'];
    $e2eId = $transition['e2eId'];
    $invoiceId = (int) $charge->invoice_id;
    PaymentApplier::apply([
        'invoiceId' => $invoiceId, 'transId' => $e2eId, 'amountCents' => (int) $transition['amountCents'],
        'module' => $gatewayModuleName, 'gatewayParams' => $gatewayParams,
        'markPaid' => static function () use ($charge, $e2eId): void { Repository::updateCharge((int) $charge->id, ['status' => 'CONCLUIDA', 'metadata' => json_encode(['e2e_id' => $e2eId]) ?: '{}']); },
        'markApplied' => static function () use ($transition): void { Repository::markEventApplied((int) $transition['eventId']); },
    ]);
    logTransaction($gatewayModuleName, ['invoice_id' => $invoiceId, 'txid' => $charge->efi_charge_id, 'e2eId' => $e2eId], 'Cobrança Pix Automático concluída');
}

http_response_code(200);
echo '200';
