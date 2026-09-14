<?php

/**
 * Endpoint do botão "Testar Conexão" injetado na tela de configuração de cada gateway (ver hook
 * AdminAreaFooterOutput em hooks/efi_hooks.php). Valida autenticação com a Efí e, no caso do
 * Pix, o certificado/registro do webhook -- sem alterar nenhuma configuração.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/vendor/autoload.php';

use Efi\Exception\EfiException;
use EfiWhmcs\Support\EfiClientFactory;

header('Content-Type: application/json');

if (empty($_SESSION['adminid'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sessão de admin inválida.']);
    exit;
}

$module = (string) ($_POST['module'] ?? '');

if (!in_array($module, ['efi_boleto', 'efi_pix', 'efi_cartao'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Módulo inválido.']);
    exit;
}

$gatewayParams = getGatewayVariables($module);

if (!$gatewayParams['type']) {
    echo json_encode(['success' => false, 'message' => 'Módulo não está ativo.']);
    exit;
}

try {
    $requiresCert = $module === 'efi_pix';
    $api = EfiClientFactory::make($gatewayParams, $requiresCert);
    $extra = '';

    if ($module === 'efi_pix') {
        // Chamada de escopo Pix (webhook.read) -- autentica e confirma o registro do webhook
        // na mesma chamada. Um endpoint de Cobranças (ex.: listPlans) não serve aqui: as
        // credenciais Pix podem não ter escopo de Cobranças.
        $webhook = $api->pixDetailWebhook(['chave' => trim((string) ($gatewayParams['pix_key'] ?? ''))]);
        $extra = ' Webhook registrado em: ' . ($webhook['webhookUrl'] ?? '(não encontrado)');
    } else {
        // Chamada leve de escopo Cobranças, apenas para forçar a autenticação OAuth e
        // confirmar client_id/secret.
        $api->listPlans(['limit' => 1]);
    }

    echo json_encode(['success' => true, 'message' => 'Autenticação OK.' . $extra]);
} catch (EfiException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro Efí: ' . $e->errorDescription]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
