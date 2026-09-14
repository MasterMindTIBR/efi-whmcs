<?php

/**
 * Efí - Cartão de Crédito (WHMCS Gateway Module - Merchant + Remote Input tokenizado).
 *
 * Cartão salvo e recorrência automática: usa o mecanismo NATIVO do WHMCS para gateways
 * tokenizados via iframe ("Remote Input Gateway",
 * https://developers.whmcs.com/payment-gateways/remote-input-gateway). O PAN/CVV NUNCA passa
 * pelo nosso backend PHP -- são capturados e tokenizados inteiramente no navegador do cliente
 * pela lib oficial `payment-token-efi` dentro do iframe que o próprio WHMCS renderiza
 * (`#tokenGatewayRemoteInputOutput` / `ccframe`) tanto no checkout quanto na tela "Métodos de
 * Pagamento" do cliente. Ver docs/ARQUITETURA.md.
 *
 * Sem `_link`: para gateways de cartão tokenizados via remote input, o próprio WHMCS decide
 * exibir o iframe (não este módulo).
 */

use EfiWhmcs\Card\CardService;
use EfiWhmcs\Config\ConfigSchema;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Ledger\Schema as EfiSchema;
use EfiWhmcs\Support\Crypto;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/efi/vendor/autoload.php';

function efi_cartao_MetaData()
{
    return [
        'DisplayName' => 'Efí - Cartão de Crédito',
        'APIVersion' => '1.1',
    ];
}

function efi_cartao_config()
{
    $common = ConfigSchema::common('Efí - Cartão de Crédito');

    return $common + [
        'card_encryption_key' => [
            'FriendlyName' => 'Chave de Cifragem do Cartão',
            'Type' => 'password',
            'Size' => '64',
            'Description' => 'OBRIGATÓRIO. Segredo próprio (não é da Efí) usado para cifrar o payment_token salvo no WHMCS. Sugestão: gere com <code>openssl rand -hex 32</code>. Se você trocar este valor depois de cartões já salvos, os clientes precisarão recadastrar o cartão.',
        ],
        'max_installments' => [
            'FriendlyName' => 'Parcelamento máximo',
            'Type' => 'text',
            'Size' => '4',
            'Default' => '1',
            'Description' => 'Número máximo de parcelas oferecido no checkout. 1 desativa o parcelamento.',
        ],
        'tds_required_above_amount' => [
            'FriendlyName' => '3D Secure obrigatório acima de (R$)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '',
            'Description' => 'Deixe em branco para nunca exigir 3DS. Recorrência automática (cliente ausente) nunca usa 3DS, independente deste valor.',
        ],
        'document_field_name' => [
            'FriendlyName' => 'Campo personalizado de CPF/CNPJ',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'CPF/CNPJ',
        ],
    ];
}

function efi_cartao_config_validate($params)
{
    EfiSchema::ensure();

    $errors = [];

    if (trim((string) ($params['client_id'] ?? '')) === '' || trim((string) ($params['client_secret'] ?? '')) === '') {
        $errors[] = 'Client ID e Client Secret são obrigatórios.';
    }

    if (trim((string) ($params['account_id'] ?? '')) === '') {
        $errors[] = 'Identificador de Conta é obrigatório (necessário para a tokenização de cartão no navegador).';
    }

    if (trim((string) ($params['card_encryption_key'] ?? '')) === '') {
        $errors[] = 'Chave de Cifragem do Cartão é obrigatória antes de aceitar pagamentos com cartão.';
    }

    return $errors !== [] ? ['errors' => $errors] : [];
}

/**
 * Chamada pelo WHMCS tanto no checkout (com invoiceid/amount) quanto na tela de "Métodos de
 * Pagamento" fora de uma fatura (sem invoiceid/amount). Retorna um <form> que o WHMCS
 * auto-submete para dentro do iframe que ele mesmo renderiza -- ver
 * https://developers.whmcs.com/payment-gateways/remote-input-gateway.
 */
function efi_cartao_remoteinput($params)
{
    $systemUrl = rtrim((string) $params['systemurl'], '/');
    $isCheckout = isset($params['invoiceid']) && $params['invoiceid'] && isset($params['amount']) && $params['amount'];
    $clientId = (int) ($params['clientdetails']['id'] ?? $params['clientdetails']['userid'] ?? 0);

    $document = \EfiWhmcs\Support\CustomerResolver::documentFromCustomField(
        $clientId,
        $params['document_field_name'] ?? null
    );

    $fields = [
        'clientid' => $clientId,
        'account_id' => $params['account_id'],
        'environment' => EfiClientFactory::isSandbox($params) ? 'sandbox' : 'production',
        'invoiceid' => $isCheckout ? $params['invoiceid'] : '',
        'amount_cents' => $isCheckout ? Money::toCents($params['amount']) : '',
        'max_installments' => max(1, (int) ($params['max_installments'] ?? 1)),
        'firstname' => $params['clientdetails']['firstname'],
        'lastname' => $params['clientdetails']['lastname'],
        'email' => $params['clientdetails']['email'],
        'document' => $document ?? '',
    ];

    $inputs = '';
    foreach ($fields as $key => $value) {
        $inputs .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string) $value) . '">' . PHP_EOL;
    }

    return '<form id="efiCardRemoteInputForm" method="post" action="' . htmlspecialchars($systemUrl) . '/modules/gateways/efi_cartao/remote_form.php" target="ccframe">'
        . $inputs .
        '<noscript><input type="submit" value="Continuar"></noscript>
        </form>
        <script>
            (function () {
                var f = document.getElementById("efiCardRemoteInputForm");
                if (f) { f.submit(); }
            })();
        </script>';
}

function efi_cartao_remoteupdate($params)
{
    return '<div class="alert alert-info text-center">'
        . 'Não é possível editar um cartão salvo. Cadastre um novo método de pagamento para substituí-lo.'
        . '</div>';
}

/**
 * Cobrança de fatura usando o cartão salvo (gatewayid = payment_token cifrado). Chamada pelo
 * próprio motor de cobrança automática do WHMCS -- é ESTE o mecanismo de recorrência: nenhuma
 * tabela/cron próprio, o WHMCS decide quando cobrar com base no vencimento da fatura.
 */
function efi_cartao_capture($params)
{
    $gatewayId = trim((string) ($params['gatewayid'] ?? ''));

    if ($gatewayId === '') {
        // Não deveria acontecer para um gateway puramente tokenizado (sem armazenamento local
        // de cardnum), mas responde de forma segura em vez de silenciosamente falhar.
        return ['status' => 'error', 'rawdata' => 'gatewayid ausente: nenhum cartão tokenizado associado a esta fatura.'];
    }

    try {
        $paymentToken = Crypto::decrypt($gatewayId, (string) $params['card_encryption_key']);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_cartao', $params, 'Falha ao decifrar token salvo', $e->getMessage());

        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }

    $documentDigits = \EfiWhmcs\Support\CustomerResolver::documentFromCustomField(
        (int) $params['clientdetails']['id'],
        $params['document_field_name'] ?? null
    );

    if ($documentDigits === null) {
        return ['status' => 'error', 'rawdata' => 'CPF/CNPJ do cliente não encontrado no campo personalizado configurado; recorrência automática não pode prosseguir sem documento.'];
    }

    $api = EfiClientFactory::make($params);
    $service = new CardService($api, $params);

    $customerName = trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']);

    // Recorrência automática: cliente ausente, nunca envia tds_info (equivalente a MOTO/COF).
    $result = $service->chargeWithToken(
        (int) $params['invoiceid'],
        $paymentToken,
        Money::toCents($params['amount']),
        $customerName,
        $documentDigits,
        $params['clientdetails']['email'] ?? null,
        null
    );

    return efi_cartao_map_result($result, $gatewayId);
}

function efi_cartao_refund($params)
{
    $charge = ChargeRepository::findByInvoiceAndRail((int) $params['invoiceid'], CardService::RAIL);

    if ($charge === null) {
        return ['status' => 'error', 'rawdata' => 'Cobrança de cartão não encontrada no ledger local para esta fatura.'];
    }

    $api = EfiClientFactory::make($params);
    $result = (new CardService($api, $params))->refund($charge->efi_charge_id, Money::toCents($params['amount']));

    if ($result['status'] === 'success') {
        ChargeRepository::updateStatus($charge->id, 'refunded');
    }

    return [
        'status' => $result['status'] === 'success' ? 'success' : 'error',
        'transid' => $result['transid'] ?? null,
        'rawdata' => $result['raw'],
    ];
}

/**
 * Traduz o resultado do CardService para o contrato de retorno esperado pelo WHMCS em
 * `_capture`. `gatewayid` só é devolvido quando o token efetivamente muda (não é o caso aqui,
 * já que reutilizamos o mesmo payment_token reutilizável -- devolver o mesmo valor de novo é
 * inofensivo e mantido para clareza).
 */
function efi_cartao_map_result(array $result, string $gatewayId): array
{
    return match ($result['status']) {
        'approved', 'paid' => [
            'status' => 'success',
            'transid' => $result['transid'],
            'gatewayid' => $gatewayId,
            'rawdata' => $result['raw'],
        ],
        'waiting' => [
            'status' => 'pending',
            'transid' => $result['transid'],
            'rawdata' => $result['raw'],
        ],
        default => [
            'status' => 'declined',
            'declinereason' => $result['raw']['error'] ?? $result['status'],
            'rawdata' => $result['raw'],
        ],
    };
}
