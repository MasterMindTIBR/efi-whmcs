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
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use WHMCS\Database\Capsule;

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = PixAutomaticService::GATEWAY;
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$expectedSecret = trim((string) ($gatewayParams['webhook_secret'] ?? ''));
$providedSecret = (string) ($_GET['ws'] ?? '');
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Webhook Pix Automático rejeitado: segredo ausente/incorreto', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(200);
    echo '200';
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
    $invoiceId = checkCbInvoiceID((int) $charge->invoice_id, $gatewayModuleName);
    checkCbTransID($e2eId);
    addInvoicePayment($invoiceId, $e2eId, Money::toReais($transition['amountCents']), 0, $gatewayModuleName);
    Repository::updateCharge((int) $charge->id, [
        'status' => 'CONCLUIDA',
        'metadata' => json_encode(['e2e_id' => $e2eId]) ?: '{}',
    ]);
    logTransaction($gatewayModuleName, ['invoice_id' => $invoiceId, 'txid' => $charge->efi_charge_id, 'e2eId' => $e2eId], 'Cobrança Pix Automático concluída');
}

http_response_code(200);
echo '200';
