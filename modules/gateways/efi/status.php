<?php

/**
 * Endpoint leve e compartilhado usado pelo JS de checkout (Pix e Cartão) para saber se a fatura
 * já foi paga, sem expor nenhum dado sensível -- responde apenas {"paid": bool}. Autenticação é
 * a mesma sessão de cliente do WHMCS (requer login), e o invoiceid é validado contra o cliente
 * logado antes de responder.
 */

require_once __DIR__ . '/../../../init.php';

use WHMCS\Database\Capsule;

header('Content-Type: application/json');

$invoiceId = (int) ($_GET['invoiceid'] ?? 0);
$clientId = (int) ($_SESSION['uid'] ?? 0);

if ($invoiceId <= 0 || $clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid request']);
    exit;
}

$invoice = Capsule::table('tblinvoices')
    ->where('id', $invoiceId)
    ->where('userid', $clientId)
    ->first(['status']);

if ($invoice === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

echo json_encode([
    'paid' => in_array($invoice->status, ['Paid', 'Refunded'], true),
    'status' => $invoice->status,
]);
