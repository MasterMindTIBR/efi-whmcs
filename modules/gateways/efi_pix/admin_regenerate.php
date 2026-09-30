<?php

/**
 * Renova uma cobrança Pix pelo painel administrativo. A cobrança ativa anterior é desativada
 * antes da emissão, evitando dois QR Codes válidos para a mesma fatura.
 */

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Pix\PixService;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use WHMCS\Database\Capsule;

header('Content-Type: application/json');

if (empty($_SESSION['adminid'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sessão de admin inválida.']);
    exit;
}

$invoiceId = (int) ($_POST['invoiceid'] ?? 0);

if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invoiceid inválido.']);
    exit;
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first([
    'id',
    'userid',
    'total',
    'status',
    'paymentmethod',
]);

if ($invoice === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Fatura não encontrada.']);
    exit;
}

if ($invoice->paymentmethod !== 'efi_pix') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A fatura não usa o gateway Efí - Pix.']);
    exit;
}

if (in_array($invoice->status, ['Paid', 'Cancelled', 'Refunded'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Não é possível renovar Pix de uma fatura paga, cancelada ou estornada.']);
    exit;
}

$gatewayParams = getGatewayVariables('efi_pix');

if (!$gatewayParams['type']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Gateway Efí - Pix não está ativo.']);
    exit;
}

$client = Capsule::table('tblclients')->where('id', $invoice->userid)->first(['firstname', 'lastname']);
$document = CustomerResolver::documentFromCustomField((int) $invoice->userid, $gatewayParams['document_field_name'] ?? null);

if ($client === null || $document === null || !CustomerResolver::isValidCpfOrCnpj($document)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'O cliente precisa ter CPF/CNPJ válido no Campo Personalizado configurado para renovar a cobrança Pix.']);
    exit;
}

try {
    $api = EfiClientFactory::make($gatewayParams, true);
    $result = (new PixService($api, $gatewayParams))->regenerate(
        $invoiceId,
        Money::toCents($invoice->total),
        trim($client->firstname . ' ' . $client->lastname),
        $document
    );

    logTransaction('efi_pix', [
        'invoice_id' => $invoiceId,
        'txid' => $result['txid'],
        'admin_id' => $_SESSION['adminid'],
    ], 'Cobrança Pix renovada manualmente pelo administrador.');

    echo json_encode([
        'success' => true,
        'message' => 'Cobrança Pix renovada. Novo txid: ' . $result['txid'],
    ]);
} catch (\Throwable $e) {
    GatewayLog::error('efi_pix', $gatewayParams, 'Falha ao renovar cobrança Pix pelo admin', [
        'invoice' => $invoiceId,
        'error' => $e->getMessage(),
    ]);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
