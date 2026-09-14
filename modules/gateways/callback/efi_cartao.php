<?php

/**
 * Callback (webhook) do cartão Efí -- cobre transições assíncronas de status (ex.: cobrança que
 * ficou `waiting` por análise/3DS e depois muda para aprovada/recusada). O caminho síncrono
 * (aprovação imediata no checkout) já é tratado em modules/gateways/efi_cartao/remote_process.php;
 * este callback é a rede de segurança para tudo que muda de status depois daquele primeiro
 * request. Mesmo modelo de autenticidade da API de Cobranças usado pelo boleto -- ver
 * EfiWhmcs\Webhook\ChargeNotificationProcessor.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use EfiWhmcs\Webhook\ChargeNotificationProcessor;

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'efi_cartao';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$notificationToken = $_POST['notification'] ?? null;

if ($notificationToken === null) {
    http_response_code(400);
    die('missing notification token');
}

try {
    $api = EfiClientFactory::make($gatewayParams);
    $processor = new ChargeNotificationProcessor($api, $gatewayModuleName, $gatewayParams);
    $transitions = $processor->fetchNewTransitions((string) $notificationToken);
} catch (\Throwable $e) {
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Falha ao processar notificação de cartão', $e->getMessage());
    http_response_code(200);
    die('error: ' . $e->getMessage());
}

foreach ($transitions as $transition) {
    $charge = $transition['charge'];
    $invoiceId = (int) $charge->invoice_id;
    $to = $transition['to'];

    logTransaction($gatewayModuleName, [
        'invoice_id' => $invoiceId,
        'charge_id' => $charge->efi_charge_id,
        'from' => $transition['from'],
        'to' => $to,
    ], 'Notificação de cartão: ' . $to);

    if (in_array($to, ['paid', 'approved'], true)) {
        $invoiceIdChecked = checkCbInvoiceID($invoiceId, $gatewayModuleName);
        $amount = $transition['valueCents'] !== null ? Money::toReais($transition['valueCents']) : 0;

        checkCbTransID($charge->efi_charge_id);
        addInvoicePayment($invoiceIdChecked, $charge->efi_charge_id, $amount, 0, $gatewayModuleName);
        ChargeRepository::updateStatus($charge->id, 'paid');
    } else {
        ChargeRepository::updateStatus($charge->id, $to);
    }
}

http_response_code(200);
echo 'ok';
