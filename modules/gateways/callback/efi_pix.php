<?php

/**
 * Callback (webhook) do Pix Efí.
 *
 * Autenticidade em camadas (ver docs/ARQUITETURA.md §1.4 e docs/INSTALACAO.md):
 *   1. mTLS na camada de transporte (configurado no servidor web -- fora do alcance deste
 *      arquivo PHP, é pré-requisito de infraestrutura documentado na instalação).
 *   2. Segredo `?ws=` na própria URL cadastrada (`PixService::notificationUrl`).
 *   3. NUNCA aplica o valor/status do payload recebido sem antes confirmar via consulta
 *      autoritativa (`pixDetailCharge`) -- ver EfiWhmcs\Webhook\PixNotificationProcessor.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use EfiWhmcs\Webhook\PixNotificationProcessor;
use WHMCS\Database\Capsule;

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'efi_pix';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$expectedSecret = trim((string) ($gatewayParams['webhook_secret'] ?? ''));
$providedSecret = (string) ($_GET['ws'] ?? '');

if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Webhook rejeitado: segredo ausente/incorreto', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(200); // responde 200 mesmo assim -- não confirmar/negar para quem não tem o segredo
    echo '200';
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

try {
    $api = EfiClientFactory::make($gatewayParams, true);
    $processor = new PixNotificationProcessor($api, $gatewayParams);
    $transitions = $processor->fetchNewTransitions($rawBody);
} catch (\Throwable $e) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Falha ao processar webhook Pix', $e->getMessage());
    http_response_code(200);
    echo '200';
    exit;
}

foreach ($transitions as $transition) {
    $charge = $transition['charge'];
    $invoiceId = (int) $charge->invoice_id;

    logTransaction($gatewayModuleName, [
        'invoice_id' => $invoiceId,
        'txid' => $charge->efi_charge_id,
        'e2eId' => $transition['e2eId'],
        'status' => $transition['status'],
    ], 'Notificação Pix: ' . $transition['status']);

    $metadata = json_decode((string) $charge->metadata, true) ?: [];
    $metadata['e2e_id'] = $transition['e2eId'];

    Capsule::table('mod_efi_charges')->where('id', $charge->id)->update([
        'metadata' => json_encode($metadata),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
    checkCbTransID($transition['e2eId']);
    addInvoicePayment($invoiceId, $transition['e2eId'], Money::toReais($transition['valueCents']), 0, $gatewayModuleName);
    ChargeRepository::updateStatus($charge->id, 'CONCLUIDA');
}

http_response_code(200);
echo '200';
