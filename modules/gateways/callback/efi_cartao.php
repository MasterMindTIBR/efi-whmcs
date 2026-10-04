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
use EfiWhmcs\Support\Money;
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

    // O ledger local é atualizado SEMPRE primeiro: `checkCbTransID()` do WHMCS interrompe o
    // script inteiro com `die()` quando encontra um transid já registrado (ex.: esta cobrança
    // já foi creditada de forma síncrona por remote_process.php e agora chega a notificação
    // assíncrona do mesmo pagamento) -- se checkCbTransID rodasse primeiro, nosso status local
    // ficaria parado no valor antigo para sempre, e qualquer transição seguinte neste MESMO
    // lote de notificações nunca seria processada.
    $alreadyPaidLocally = $charge->status === 'paid';

    if (in_array($to, ['paid', 'approved'], true)) {
        ChargeRepository::updateStatus($charge->id, 'paid');
    } else {
        ChargeRepository::updateStatus($charge->id, $to);
    }

    if (in_array($to, ['paid', 'approved'], true) && !$alreadyPaidLocally) {
        $invoiceIdChecked = checkCbInvoiceID($invoiceId, $gatewayModuleName);
        $amount = $transition['valueCents'] !== null ? Money::toReais($transition['valueCents']) : 0;

        checkCbTransID($charge->efi_charge_id);
        addInvoicePayment($invoiceIdChecked, $charge->efi_charge_id, $amount, 0, $gatewayModuleName);
    }
}

http_response_code(200);
echo 'ok';
