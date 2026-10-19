<?php

/**
 * Endpoint chamado via fetch() por remote_form.php DEPOIS que o payment_token já foi gerado no
 * navegador. Recebe SOMENTE o payment_token (nunca PAN/CVV) e finaliza: cobra a fatura (se
 * houver invoiceid/amount) e/ou salva o cartão como método de pagamento do cliente.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

use EfiWhmcs\Card\CardService;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Support\Crypto;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLoader;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\InvoiceBalance;
use EfiWhmcs\Support\Money;
use EfiWhmcs\Support\PaymentApplier;
use WHMCS\Database\Capsule;

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
$cardExpiryMmYy = CustomerResolver::onlyDigits((string) ($body['card_expiry_mmyy'] ?? ''));
$saveCard = (bool) ($body['save_card'] ?? false);
$invoiceId = (int) ($body['invoiceid'] ?? 0);
$installments = max(1, (int) ($body['installments'] ?? 1));

if ($paymentToken === '') {
    efi_cartao_process_fail('payment_token ausente.');
}

if (!CustomerResolver::isValidCpfOrCnpj($documentDigits)) {
    efi_cartao_process_fail('CPF/CNPJ inválido.');
}

if ($saveCard && !preg_match('/^(0[1-9]|1[0-2])\d{2}$/', $cardExpiryMmYy)) {
    efi_cartao_process_fail('Validade do cartão inválida.');
}

$gatewayParams = GatewayLoader::variables('efi_cartao');

if ($gatewayParams === null) {
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

    if ($invoiceId > 0) {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $clientId)->first();

        if ($invoice === null) {
            efi_cartao_process_fail('Fatura não encontrada para este cliente.', 403);
        }

        if ($invoice->status !== 'Unpaid') {
            efi_cartao_process_fail('Esta fatura não está mais em aberto.', 409);
        }

        $currencyCode = Capsule::table('tblcurrencies')->where('id', $invoice->currency)->value('code');
        if ($currencyCode !== null && strtoupper((string) $currencyCode) !== 'BRL') {
            // A API da Efí cobra sempre em BRL; este módulo nunca converteu moeda. Falhar
            // explicitamente é mais seguro que cobrar o valor numérico errado em outra moeda.
            efi_cartao_process_fail('Este gateway só processa cobranças em Real (BRL).', 422);
        }

        // Protege contra reenvio (duplo clique, retry de rede, back+resubmit) olhando o ledger
        // ANTES de chamar a Efí; a trava de verdade contra a corrida (duas abas simultâneas)
        // é o GET_LOCK por fatura dentro de CardService::chargeWithToken -- esta checagem aqui
        // só evita uma chamada à API desnecessária no caso comum (não concorrente).
        $existingCharge = ChargeRepository::findByInvoiceAndRail($invoiceId, CardService::RAIL);

        if ($existingCharge !== null && in_array($existingCharge->status, ['creating', 'waiting', 'approved', 'paid', 'unknown', 'failed-unknown'], true)) {
            efi_cartao_process_fail('Já existe uma cobrança de cartão em andamento ou concluída para esta fatura.', 409);
        }

        // Valor SEMPRE calculado aqui a partir do SALDO da fatura -- nunca do total nem de
        // algo enviado pelo cliente. Uma fatura com pagamento parcial anterior cobra só o
        // restante; isso também evita cobrar de novo um valor já quitado por outro meio.
        $amountCents = InvoiceBalance::centsDue($invoiceId);

        // 3D Secure NÃO está implementado: exigiria um passo de fingerprint adicional da Efí no
        // navegador (gera o `tds_identifier`) e uma página pública de retorno de challenge
        // (`challenge_callback_url`), nenhum dos dois presentes nesta integração. Cobrança
        // prossegue via análise de risco padrão da Efí sem tds_info -- ver análise de produção.
        $tdsInfo = null;

        $result = $service->chargeWithToken(
            $invoiceId,
            $paymentToken,
            $amountCents,
            $customerName,
            $documentDigits,
            $clientDetails->email,
            $phoneNumber,
            $tdsInfo,
            min($installments, (int) ($gatewayParams['max_installments'] ?? 1)),
            $clientDetails->companyname ?? null
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
            PaymentApplier::apply([
                'invoiceId' => $invoiceId,
                'transId' => (string) $result['transid'],
                'amountCents' => $amountCents,
                'module' => 'efi_cartao',
                'gatewayParams' => $gatewayParams,
                'chargeId' => (int) $result['local_id'],
            ]);
        }
        // status 'waiting' (ex.: análise/3DS assíncrono): pagamento será aplicado pelo
        // callback/efi_cartao.php quando a notificação de confirmação chegar.

        // Grava o documento digitado no Campo Personalizado para que a recorrência automática
        // (_capture, que só lê o campo e nunca recebe entrada do cliente) o encontre depois.
        CustomerResolver::saveDocumentToCustomField($clientId, $gatewayParams['document_field_name'] ?? null, $documentDigits);
    }

    if ($saveCard) {
        $encryptedToken = Crypto::encrypt($paymentToken, (string) $gatewayParams['card_encryption_key']);
        $last4 = substr(preg_replace('/\D/', '', $cardMask) ?: '0000', -4);

        createCardPayMethod(
            $clientId,
            'efi_cartao',
            $last4,
            $cardExpiryMmYy, // WHMCS exige mmyy; o PAN continua sem armazenamento local
            $brand,
            null,
            null,
            $encryptedToken,
            'billing',
            ($brand ?: 'Cartão') . ' final ' . $last4
        );
    }

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    GatewayLog::error('efi_cartao', $gatewayParams, 'Falha ao processar cartão (remote_process)', $e->getMessage());
    efi_cartao_process_fail('Não foi possível processar o pagamento. Tente novamente em instantes ou contate o suporte.', 500);
}
