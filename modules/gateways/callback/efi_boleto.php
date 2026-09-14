<?php

/**
 * Callback (webhook) do boleto Efí.
 *
 * Modelo de autenticidade: o POST só carrega um token opaco (`$_POST['notification']`); os
 * dados reais só existem após consulta autenticada (`getNotification`) -- ver
 * EfiWhmcs\Webhook\ChargeNotificationProcessor. Processa TODOS os eventos novos em ordem,
 * nunca só o último, com idempotência garantida por `mod_efi_events`.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Boleto\BoletoService;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use EfiWhmcs\Webhook\ChargeNotificationProcessor;

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'efi_boleto';
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
    GatewayLog::error($gatewayModuleName, $gatewayParams, 'Falha ao processar notificação', $e->getMessage());
    http_response_code(200); // 200 para não entrar em retry-storm por erro de config local
    die('error: ' . $e->getMessage());
}

efi_boleto_apply_transitions($transitions, $gatewayModuleName, $gatewayParams);

http_response_code(200);
echo 'ok';

/**
 * @param array $transitions ver ChargeNotificationProcessor::fetchNewTransitions
 */
function efi_boleto_apply_transitions(array $transitions, string $gatewayModuleName, array $gatewayParams): void
{
    foreach ($transitions as $transition) {
        $charge = $transition['charge'];
        $invoiceId = (int) $charge->invoice_id;
        $to = $transition['to'];

        logTransaction($gatewayModuleName, [
            'invoice_id' => $invoiceId,
            'charge_id' => $charge->efi_charge_id,
            'from' => $transition['from'],
            'to' => $to,
        ], 'Notificação: ' . $to);

        switch ($to) {
            case 'paid':
            case 'settled':
                $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
                $amount = $transition['valueCents'] !== null
                    ? Money::toReais($transition['valueCents'])
                    : 0; // 0 = WHMCS assume o saldo devedor total da fatura

                checkCbTransID($charge->efi_charge_id);
                addInvoicePayment($invoiceId, $charge->efi_charge_id, $amount, 0, $gatewayModuleName);
                ChargeRepository::updateStatus($charge->id, 'paid');
                break;

            case 'unpaid':
                ChargeRepository::updateStatus($charge->id, 'unpaid');
                break;

            case 'canceled':
            case 'expired':
                ChargeRepository::updateStatus($charge->id, $to);
                break;

            default:
                ChargeRepository::updateStatus($charge->id, $to);
        }
    }
}
