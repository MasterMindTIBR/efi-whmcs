<?php

/**
 * Endpoint chamado pelo botão "Tentar Capturar Pagamento (Efí)" injetado na página de fatura do
 * admin (ver hook AdminInvoicesControlsOutput em includes/hooks/efi_hooks.php). Apenas encapsula a ação
 * nativa `CapturePayment` da API local do WHMCS -- não reimplementa lógica de cobrança própria.
 */

require_once __DIR__ . '/../../../init.php';

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

$adminUsername = $_SESSION['adminusername'] ?? null;
$results = localAPI('CapturePayment', ['invoiceid' => $invoiceId], $adminUsername);

echo json_encode([
    'success' => ($results['result'] ?? '') === 'success',
    'message' => $results['message'] ?? ($results['result'] ?? 'unknown'),
]);
