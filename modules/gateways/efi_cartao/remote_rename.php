<?php

/**
 * Endpoint chamado via fetch() pelo formulário renderizado em `efi_cartao_remoteupdate()`
 * (tela "Editar" de um cartão salvo). Atualiza SOMENTE o apelido (`tblpaymethods.description`)
 * -- nunca número/validade/CVV, que não existem neste fluxo.
 */

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Support\GatewayLog;
use WHMCS\Database\Capsule;

header('Content-Type: application/json');

function efi_cartao_rename_fail(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);

if (!is_array($body)) {
    efi_cartao_rename_fail('Requisição inválida.');
}

$sessionClientId = (int) ($_SESSION['uid'] ?? 0);
$clientId = (int) ($body['clientid'] ?? 0);
$paymethodId = (int) ($body['paymethodid'] ?? 0);
$nickname = trim((string) ($body['nickname'] ?? ''));

if ($sessionClientId <= 0 || $sessionClientId !== $clientId) {
    efi_cartao_rename_fail('Sessão inválida.', 403);
}

if ($nickname === '' || mb_strlen($nickname) > 255) {
    efi_cartao_rename_fail('Informe um nome com até 255 caracteres.');
}

if ($paymethodId <= 0) {
    efi_cartao_rename_fail('Método de pagamento inválido.');
}

$gatewayParams = getGatewayVariables('efi_cartao');

$updated = Capsule::table('tblpaymethods')
    ->where('id', $paymethodId)
    ->where('userid', $clientId)
    ->where('gateway_name', 'efi_cartao')
    ->whereNull('deleted_at')
    ->update(['description' => $nickname, 'updated_at' => Capsule::raw('NOW()')]);

if ($updated === 0) {
    GatewayLog::error('efi_cartao', $gatewayParams, 'Falha ao renomear cartão: método não encontrado', [
        'client_id' => $clientId,
        'paymethod_id' => $paymethodId,
    ]);

    efi_cartao_rename_fail('Método de pagamento não encontrado.', 404);
}

GatewayLog::debug('efi_cartao', $gatewayParams, 'Cartão renomeado', [
    'client_id' => $clientId,
    'paymethod_id' => $paymethodId,
]);

echo json_encode(['success' => true]);
