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

use EfiWhmcs\Card\CardService;
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

    // Política do módulo: `approved` (aprovado pela operadora) já dá a fatura como paga -- mesmo
    // comportamento do checkout síncrono e do `_capture` --, e uma reversão posterior da
    // antifraude (`approved` → `unpaid`/`canceled`) desfaz o crédito e reabre a fatura.
    if (in_array($to, ['approved', 'paid'], true)) {
        PaymentApplier::apply([
            'invoiceId' => $invoiceId, 'transId' => (string) $charge->efi_charge_id,
            'amountCents' => $transition['valueCents'] ?? (int) $charge->amount_cents,
            'module' => $gatewayModuleName, 'gatewayParams' => $gatewayParams,
            'chargeId' => (int) $charge->id, 'eventId' => (int) $transition['eventId'],
            'settledStatus' => $to,
        ]);
        continue;
    }

    ChargeRepository::updateStatus($charge->id, $to);

    if (in_array($to, ['unpaid', 'canceled'], true)) {
        (new CardService($api, $gatewayParams))->revertAppliedPayment($charge, $to);
    } elseif (in_array($to, ['refunded', 'contested', 'charged_back'], true)) {
        GatewayLog::error($gatewayModuleName, $gatewayParams, 'Cobrança de cartão ' . $to . ' na Efí -- se não foi iniciado pelo próprio WHMCS (estorno pela tela da fatura), revise manualmente: contestação/estorno externo não é lançado automaticamente', [
            'invoice_id' => $invoiceId, 'charge_id' => $charge->efi_charge_id, 'from' => $transition['from'],
        ]);
    }

    \EfiWhmcs\Ledger\EventRepository::markApplied((int) $transition['eventId']);
}

http_response_code(200);
echo 'ok';
