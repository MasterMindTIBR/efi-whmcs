<?php

/**
 * Endpoint chamado pelo botão "Tentar Capturar Pagamento (Efí)" injetado na página de fatura do
 * admin (ver hook AdminInvoicesControlsOutput em includes/hooks/efi_hooks.php). Apenas encapsula a ação
 * nativa `CapturePayment` da API local do WHMCS -- não reimplementa lógica de cobrança própria.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Support\AdminCsrf;

header('Content-Type: application/json');

if (empty($_SESSION['adminid'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sessão de admin inválida.']);
    exit;
}

if (!AdminCsrf::verify($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido ou ausente. Recarregue a página da fatura e tente novamente.']);
    exit;
}

// `localAPI()` só aplica as permissões REAIS do admin (incluindo restrições do seu papel) quando
// recebe um username válido; sem ele, a chamada roda com acesso total/irrestrito. Nunca deixar
// isso cair silenciosamente para `null` -- um admin sem permissão de faturamento não pode
// capturar pagamento só porque esta sessão não tinha `adminusername` setado.
$adminUsername = $_SESSION['adminusername'] ?? '';

if ($adminUsername === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sessão de admin sem usuário identificado; faça login novamente.']);
    exit;
}

$invoiceId = (int) ($_POST['invoiceid'] ?? 0);

if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invoiceid inválido.']);
    exit;
}

$results = localAPI('CapturePayment', ['invoiceid' => $invoiceId], $adminUsername);

echo json_encode([
    'success' => ($results['result'] ?? '') === 'success',
    'message' => $results['message'] ?? (($results['result'] ?? '') === 'success' ? 'Captura solicitada com sucesso.' : 'Falha ao solicitar captura.'),
]);
