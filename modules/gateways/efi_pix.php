<?php

/**
 * Efí - Pix (WHMCS Gateway Module - Third Party / transparente).
 *
 * QR code + copia-e-cola renderizados diretamente na página da fatura (sem popup/iframe).
 * Ver docs/ARQUITETURA.md para o desenho completo.
 */

use EfiWhmcs\Config\ConfigSchema;
use EfiWhmcs\Ledger\Schema as EfiSchema;
use EfiWhmcs\Pix\PixService;
use EfiWhmcs\Support\Crypto;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/efi/vendor/autoload.php';

function efi_pix_MetaData()
{
    return [
        'DisplayName' => 'Efí - Pix',
        'APIVersion' => '1.1',
    ];
}

function efi_pix_config()
{
    $common = ConfigSchema::common('Efí - Pix');
    $webhook = ConfigSchema::webhookSecret();

    return $common + $webhook + [
        'pix_key' => [
            'FriendlyName' => 'Chave Pix',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Chave Pix cadastrada na sua conta Efí, usada para receber as cobranças.',
        ],
        'pix_cert_path' => [
            'FriendlyName' => 'Certificado (.p12/.pem)',
            'Type' => 'text',
            'Size' => '80',
            'Description' => 'Caminho absoluto do certificado da API Pix (obrigatório -- diferente da API de Cobranças, o Pix exige mTLS).',
        ],
        'pix_cert_password' => [
            'FriendlyName' => 'Senha do certificado',
            'Type' => 'password',
            'Size' => '40',
            'Description' => 'Deixe em branco se o certificado não tiver senha.',
        ],
        'skip_mtls_webhook' => [
            'FriendlyName' => 'Pular verificação mTLS no cadastro do webhook',
            'Type' => 'yesno',
            'Description' => 'Ative SOMENTE se o seu servidor não suportar validar o certificado mTLS da Efí ao RECEBER o webhook (comum em hospedagem compartilhada). Envia `x-skip-mtls-checking` só na chamada de cadastro do webhook -- as demais chamadas (cobrança, QR code) continuam exigindo o certificado normalmente. Sem mTLS, este módulo já valida a origem das notificações pelo segredo da URL (`ws=`) e pelo IP documentado da Efí.',
        ],
        'efi_webhook_allowed_ips' => [
            'FriendlyName' => 'IPs permitidos para o webhook (com mTLS pulado)',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '34.193.116.226',
            'Description' => 'Só é checado quando "Pular verificação mTLS" acima está ativo. Lista separada por vírgula dos IPs de origem aceitos para o webhook; o valor padrão é o IP documentado hoje pela Efí (pode mudar -- ver a doc oficial de Webhooks Pix se as notificações pararem de chegar).',
        ],
        'expiration_days' => [
            'FriendlyName' => 'Validade da cobrança (dias)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '1',
        ],
        'discount_percent' => [
            'FriendlyName' => 'Desconto no Pix (%)',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '0',
        ],
        'document_field_name' => [
            'FriendlyName' => 'Campo personalizado de CPF/CNPJ',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'CPF/CNPJ',
        ],
    ];
}

function efi_pix_config_validate($params)
{
    EfiSchema::ensure();

    $errors = [];

    if (trim((string) ($params['client_id'] ?? '')) === '' || trim((string) ($params['client_secret'] ?? '')) === '') {
        $errors[] = 'Client ID e Client Secret são obrigatórios.';
    }

    if (trim((string) ($params['pix_key'] ?? '')) === '') {
        $errors[] = 'Chave Pix é obrigatória.';
    }

    $certPath = trim((string) ($params['pix_cert_path'] ?? ''));
    if ($certPath === '' || !is_readable($certPath)) {
        $errors[] = 'Certificado Pix não encontrado/legível em: ' . ($certPath !== '' ? $certPath : '(vazio)');
    }

    if ($errors !== []) {
        return ['errors' => $errors];
    }

    $webhookSecret = trim((string) ($params['webhook_secret'] ?? ''));
    if ($webhookSecret === '') {
        $errors[] = 'Preencha o Segredo do Webhook (gere com "openssl rand -hex 32") antes de salvar.';
        return ['errors' => $errors];
    }

    try {
        $api = EfiClientFactory::makeForWebhookRegistration($params);
        $service = new PixService($api, $params);
        $api->pixConfigWebhook(['chave' => trim($params['pix_key'])], ['webhookUrl' => $service->notificationUrl($webhookSecret)]);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_pix', $params, 'Falha ao registrar webhook Pix na Efí', $e->getMessage());

        return ['errors' => ['Não foi possível registrar o webhook na Efí: ' . $e->getMessage()]];
    }

    return [];
}

function efi_pix_link($params)
{
    try {
        $api = EfiClientFactory::make($params, true);
    } catch (\Throwable $e) {
        return efi_pix_render_error('Configuração do gateway inválida: ' . $e->getMessage());
    }

    $service = new PixService($api, $params);
    $invoiceId = (int) $params['invoiceid'];

    $documentDigits = CustomerResolver::documentFromCustomField(
        (int) $params['clientdetails']['userid'],
        $params['document_field_name'] ?? null
    );

    $postedDocument = isset($_POST['efi_document'])
        ? CustomerResolver::onlyDigits((string) $_POST['efi_document'])
        : null;

    $documentDigits = $documentDigits ?: $postedDocument;

    if ($documentDigits === null || !CustomerResolver::isValidCpfOrCnpj($documentDigits)) {
        return efi_pix_render_document_form($documentDigits !== null);
    }

    $customerName = trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']);

    try {
        $result = $service->issueOrFetch($invoiceId, Money::toCents($params['amount']), $customerName, $documentDigits);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_pix', $params, 'Erro ao gerar cobrança Pix', $e->getMessage());

        return efi_pix_render_error($e->getMessage());
    }

    return efi_pix_render_result($result, $invoiceId, rtrim((string) $params['systemurl'], '/'));
}

function efi_pix_render_document_form(bool $invalidAttempt): string
{
    $warning = $invalidAttempt
        ? '<p style="color:#b00020;">CPF/CNPJ inválido. Informe apenas os números (11 ou 14 dígitos).</p>'
        : '';

    return '<div class="efi-pix-box">' . $warning . '
        <form method="post">
            <label for="efi_document">CPF ou CNPJ do pagador</label>
            <input type="text" name="efi_document" id="efi_document" maxlength="18" required
                   placeholder="Somente números">
            <button type="submit" class="btn btn-primary">Gerar Pix</button>
        </form>
    </div>';
}

function efi_pix_render_result(array $result, int $invoiceId, string $systemUrl): string
{
    $img = $result['qr_image']
        ? '<p><img alt="QR Code Pix" src="' . htmlspecialchars($result['qr_image']) . '" style="max-width:260px;"></p>'
        : '';

    $copyPaste = $result['copy_paste'] ?? '';

    return '<div class="efi-pix-box">
        ' . $img . '
        <p><strong>Pix copia e cola:</strong></p>
        <textarea readonly rows="3" style="width:100%;" onclick="this.select();">' . htmlspecialchars($copyPaste) . '</textarea>
        <p id="efi-pix-waiting">Aguardando confirmação do pagamento...</p>
        <script src="' . htmlspecialchars($systemUrl) . '/modules/gateways/efi/assets/js/status-poll.js"></script>
        <script>
            if (window.efiStartStatusPoll) {
                window.efiStartStatusPoll(' . (int) $invoiceId . ', "' . htmlspecialchars($systemUrl) . '", 4000);
            }
        </script>
    </div>';
}

function efi_pix_render_error(string $message): string
{
    return '<div class="efi-pix-box efi-pix-error"><p style="color:#b00020;">' . htmlspecialchars($message) . '</p></div>';
}

function efi_pix_refund($params)
{
    $charge = \EfiWhmcs\Ledger\ChargeRepository::findByInvoiceAndRail((int) $params['invoiceid'], PixService::RAIL);

    if ($charge === null) {
        return ['status' => 'error', 'rawdata' => 'Cobrança Pix não encontrada no ledger local.'];
    }

    $metadata = json_decode((string) $charge->metadata, true) ?: [];
    $e2eId = $metadata['e2e_id'] ?? null;

    if ($e2eId === null) {
        return ['status' => 'error', 'rawdata' => 'e2eId não registrado para esta cobrança (pagamento pode não ter sido confirmado via webhook ainda).'];
    }

    try {
        $api = EfiClientFactory::make($params, true);

        // Id de devolução: só alfanumérico, 1-35 chars (regra do Bacen) e DETERMINÍSTICO a
        // partir do charge local -- se o WHMCS re-chamar refund() (timeout, retry manual),
        // reenvia o MESMO id, que a Efí trata como a mesma devolução em vez de criar um novo
        // estorno duplicado. `uniqid(..., true)` antigo gerava `_` e `.`, rejeitado pela Efí.
        $devolutionId = substr('whmcsrefund' . $charge->id, 0, 35);

        $response = $api->pixDevolution(
            ['e2eId' => $e2eId, 'id' => $devolutionId],
            ['valor' => number_format((float) $params['amount'], 2, '.', '')]
        );

        return [
            'status' => 'success',
            'transid' => $response['id'] ?? $e2eId,
            'rawdata' => $response,
        ];
    } catch (\Throwable $e) {
        GatewayLog::error('efi_pix', $params, 'Falha ao estornar Pix', $e->getMessage());

        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }
}
