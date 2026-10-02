<?php

/**
 * Chamado via fetch() pelo hook `disable_unsafe_card_delete.php` na tela "Métodos de Pagamento".
 * Devolve os IDs de `tblpaymethods` da Efí que o cliente logado NÃO deve poder excluir agora:
 * o único cartão da Efí que ele tem, ou o cartão padrão (menor `order_preference`) quando há
 * mais de um. Não recebe nem retorna nenhum dado de cartão (número/token/etc.), só IDs internos.
 */

require_once __DIR__ . '/../../../init.php';

use WHMCS\Database\Capsule;

header('Content-Type: application/json');

$clientId = (int) ($_SESSION['uid'] ?? 0);

if ($clientId <= 0) {
    http_response_code(403);
    echo json_encode(['unsafeIds' => []]);
    exit;
}

$cards = Capsule::table('tblpaymethods')
    ->where('userid', $clientId)
    ->where('gateway_name', 'efi_cartao')
    ->whereNull('deleted_at')
    ->orderBy('order_preference')
    ->get(['id', 'order_preference']);

if ($cards->count() <= 1) {
    $unsafeIds = $cards->pluck('id')->values()->all();
} else {
    $minPreference = $cards->min('order_preference');
    $unsafeIds = $cards->where('order_preference', $minPreference)->pluck('id')->values()->all();
}

echo json_encode(['unsafeIds' => array_map('intval', $unsafeIds)]);
