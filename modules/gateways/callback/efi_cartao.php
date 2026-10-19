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
use EfiWhmcs\Support\GatewayLoader;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\PaymentApplier;
use EfiWhmcs\Webhook\ChargeNotificationProcessor;

App::load_function('invoice');

$gatewayModuleName = 'efi_cartao';
$gatewayParams = GatewayLoader::variables($gatewayModuleName);

if ($gatewayParams === null) {
    GatewayLog::error($gatewayModuleName, [], 'Callback recebido com o módulo inativo/não configurado');
    http_response_code(200);
    die('module not activated');
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
    die('error');
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

    if ($to !== 'paid') {
        ChargeRepository::updateStatus($charge->id, $to);
        \EfiWhmcs\Ledger\EventRepository::markApplied((int) $transition['eventId']);
    }

    if ($to === 'paid') {
        PaymentApplier::apply([
            'invoiceId' => $invoiceId, 'transId' => (string) $charge->efi_charge_id,
            'amountCents' => $transition['valueCents'] ?? (int) $charge->amount_cents,
            'module' => $gatewayModuleName, 'gatewayParams' => $gatewayParams,
            'chargeId' => (int) $charge->id, 'eventId' => (int) $transition['eventId'],
        ]);
    }
}

http_response_code(200);
echo 'ok';
