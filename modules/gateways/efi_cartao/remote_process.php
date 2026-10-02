<?php

/**
 * Endpoint chamado via fetch() por remote_form.php DEPOIS que o payment_token já foi gerado no
 * navegador. Recebe SOMENTE o payment_token (nunca PAN/CVV) e finaliza: cobra a fatura (se
 * houver invoiceid/amount) e/ou salva o cartão como método de pagamento do cliente.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Card\CardService;
use EfiWhmcs\Support\Crypto;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use WHMCS\Database\Capsule;

App::load_function('gateway');
App::load_function('invoice');

header('Content-Type: application/json');

function efi_cartao_process_fail(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);

if (!is_array($body)) {
    efi_cartao_process_fail('Requisição inválida.');
}

$sessionClientId = (int) ($_SESSION['uid'] ?? 0);
$clientId = (int) ($body['clientid'] ?? 0);

if ($sessionClientId <= 0 || $sessionClientId !== $clientId) {
    efi_cartao_process_fail('Sessão inválida.', 403);
}

$paymentToken = trim((string) ($body['payment_token'] ?? ''));
$cardMask = (string) ($body['card_mask'] ?? '');
$brand = $body['brand'] ?? null;
$documentDigits = CustomerResolver::onlyDigits((string) ($body['holder_document'] ?? ''));
$saveCard = (bool) ($body['save_card'] ?? false);
$invoiceId = (int) ($body['invoiceid'] ?? 0);
$amountCents = (int) ($body['amount_cents'] ?? 0);
$installments = max(1, (int) ($body['installments'] ?? 1));

if ($paymentToken === '') {
    efi_cartao_process_fail('payment_token ausente.');
}

if (!CustomerResolver::isValidCpfOrCnpj($documentDigits)) {
    efi_cartao_process_fail('CPF/CNPJ inválido.');
}

$gatewayParams = getGatewayVariables('efi_cartao');

if (!$gatewayParams['type']) {
    efi_cartao_process_fail('Módulo de cartão não está ativo.', 500);
}

$clientDetails = Capsule::table('tblclients')->where('id', $clientId)->first();

if ($clientDetails === null) {
    efi_cartao_process_fail('Cliente não encontrado.', 500);
}

$customerName = trim($clientDetails->firstname . ' ' . $clientDetails->lastname);

$phoneNumber = CustomerResolver::brazilianPhoneNumber((string) $clientDetails->phonenumber);
if ($phoneNumber === null) {
    efi_cartao_process_fail('Cadastre um telefone brasileiro válido no seu perfil antes de pagar com cartão.', 422);
}

try {
    $api = EfiClientFactory::make($gatewayParams);
    $service = new CardService($api, $gatewayParams);

    if ($invoiceId > 0 && $amountCents > 0) {
        // Confere que a fatura pertence de fato a este cliente antes de cobrar.
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $clientId)->first();

        if ($invoice === null) {
            efi_cartao_process_fail('Fatura não encontrada para este cliente.', 403);
        }

        $tdsInfo = null; // Ver docs/ARQUITETURA.md -- 3DS depende de um passo de fingerprint
                          // adicional da Efí ainda não confirmado nesta integração; cobrança
                          // prossegue via análise de risco padrão sem tds_info.

        $result = $service->chargeWithToken(
            $invoiceId,
            $paymentToken,
            $amountCents,
            $customerName,
            $documentDigits,
            $clientDetails->email,
            $phoneNumber,
            $tdsInfo,
            min($installments, (int) ($gatewayParams['max_installments'] ?? 1))
        );

        logTransaction('efi_cartao', [
            'invoice_id' => $invoiceId,
            'charge_id' => $result['charge_id'],
            'status' => $result['status'],
        ], 'Cobrança de cartão: ' . $result['status']);

        if ($result['status'] === 'error') {
            efi_cartao_process_fail('Não foi possível processar o cartão. Tente novamente mais tarde.', 502);
        }
        if (!in_array($result['status'], ['approved', 'paid', 'waiting'], true)) {
            efi_cartao_process_fail('Pagamento recusado pela operadora.');
        }

        if (in_array($result['status'], ['approved', 'paid'], true)) {
            checkCbTransID($result['transid']);
            addInvoicePayment($invoiceId, $result['transid'], 0, 0, 'efi_cartao');
        }
        // status 'waiting' (ex.: análise/3DS assíncrono): pagamento será aplicado pelo
        // callback/efi_cartao.php quando a notificação de confirmação chegar.
    }

    if ($saveCard) {
        $encryptedToken = Crypto::encrypt($paymentToken, (string) $gatewayParams['card_encryption_key']);
        $last4 = substr(preg_replace('/\D/', '', $cardMask) ?: '0000', -4);

        createCardPayMethod(
            $clientId,
            'efi_cartao',
            $last4,
            null, // sem data de validade -- gateway remoto/tokenizado, não guardamos PAN
            $brand,
            null,
            null,
            $encryptedToken,
            'billing',
            'Efí - ' . ($brand ?: 'Cartão') . ' final ' . $last4
        );
    }

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    GatewayLog::error('efi_cartao', $gatewayParams, 'Falha ao processar cartão (remote_process)', $e->getMessage());
    efi_cartao_process_fail('Erro ao processar pagamento: ' . $e->getMessage(), 500);
}
