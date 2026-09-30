<?php

/**
 * Efí - Pix Automático (WHMCS Third Party Gateway).
 *
 * A primeira fatura usa a jornada 3 da Efí: um único copia-e-cola cobra a fatura presente e pede
 * consentimento para as próximas. Cobranças futuras são criadas pelo hook InvoiceCreated.
 */

use EfiWhmcs\Config\ConfigSchema;
use EfiWhmcs\PixAutomatic\PixAutomaticService;
use EfiWhmcs\PixAutomatic\Schema as PixAutomaticSchema;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/efi/vendor/autoload.php';

function efi_pix_automatico_MetaData(): array
{
    return ['DisplayName' => 'Efí - Pix Automático', 'APIVersion' => '1.1'];
}

function efi_pix_automatico_config(): array
{
    return ConfigSchema::common('Efí - Pix Automático') + ConfigSchema::webhookSecret() + [
        'pix_key' => [
            'FriendlyName' => 'Chave Pix',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Chave Pix da conta Efí que receberá a fatura inicial e as cobranças recorrentes.',
        ],
        'pix_cert_path' => [
            'FriendlyName' => 'Certificado (.p12/.pem)',
            'Type' => 'text',
            'Size' => '80',
            'Description' => 'Caminho absoluto do certificado da API Pix.',
        ],
        'pix_cert_password' => [
            'FriendlyName' => 'Senha do certificado',
            'Type' => 'password',
            'Size' => '40',
            'Description' => 'Deixe em branco se o certificado não tiver senha.',
        ],
        'periodicity' => [
            'FriendlyName' => 'Periodicidade autorizada',
            'Type' => 'dropdown',
            'Options' => [
                'SEMANAL' => 'Semanal',
                'MENSAL' => 'Mensal',
                'TRIMESTRAL' => 'Trimestral',
                'SEMESTRAL' => 'Semestral',
                'ANUAL' => 'Anual',
            ],
            'Default' => 'MENSAL',
            'Description' => 'Restrinja este gateway a produtos com o mesmo ciclo de faturamento.',
        ],
        'retry_policy' => [
            'FriendlyName' => 'Política de retentativa',
            'Type' => 'dropdown',
            'Options' => [
                'NAO_PERMITE' => 'Não permitir retentativa',
                'PERMITE_3R_7D' => 'Permitir até 3 retentativas em 7 dias',
            ],
            'Default' => 'NAO_PERMITE',
        ],
        'initial_expiration_days' => [
            'FriendlyName' => 'Validade da autorização inicial (dias)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '1',
        ],
        'contract_object' => [
            'FriendlyName' => 'Objeto da autorização',
            'Type' => 'text',
            'Size' => '80',
            'Default' => 'Serviços recorrentes WHMCS',
        ],
        'document_field_name' => [
            'FriendlyName' => 'Campo personalizado de CPF/CNPJ',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'CPF/CNPJ',
        ],
    ];
}

function efi_pix_automatico_config_validate($params): array
{
    PixAutomaticSchema::ensure();
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
    if (trim((string) ($params['webhook_secret'] ?? '')) === '') {
        $errors[] = 'Preencha o Segredo do Webhook (gere com "openssl rand -hex 32") antes de salvar.';
    }
    if ((int) ($params['initial_expiration_days'] ?? 0) < 1) {
        $errors[] = 'A validade da autorização inicial deve ser de pelo menos 1 dia.';
    }
    if ($errors !== []) {
        return ['errors' => $errors];
    }

    try {
        $api = EfiClientFactory::make($params, true);
        $service = new PixAutomaticService($api, $params);
        $webhook = ['webhookUrl' => $service->notificationUrl()];
        $api->pixConfigWebhook(['chave' => trim((string) $params['pix_key'])], $webhook);
        $automaticWebhook = ['webhookUrl' => $service->recurrenceNotificationUrl()];
        $api->pixConfigWebhookRecurrenceAutomatic([], $automaticWebhook);
        $api->pixConfigWebhookAutomaticCharge([], $automaticWebhook);
    } catch (\Throwable $e) {
        GatewayLog::error(PixAutomaticService::GATEWAY, $params, 'Falha ao registrar webhooks do Pix Automático', $e->getMessage());
        return ['errors' => ['Não foi possível registrar os webhooks na Efí: ' . $e->getMessage()]];
    }

    return [];
}

function efi_pix_automatico_link($params): string
{
    try {
        $api = EfiClientFactory::make($params, true);
    } catch (\Throwable $e) {
        return efi_pix_automatico_render_error('Configuração do gateway inválida: ' . $e->getMessage());
    }

    $clientId = (int) $params['clientdetails']['userid'];
    $document = CustomerResolver::documentFromCustomField($clientId, $params['document_field_name'] ?? null);
    $postedDocument = isset($_POST['efi_pix_auto_document'])
        ? CustomerResolver::onlyDigits((string) $_POST['efi_pix_auto_document'])
        : null;
    $document = $document ?: $postedDocument;

    if ($document === null || !CustomerResolver::isValidCpfOrCnpj($document)) {
        return efi_pix_automatico_render_document_form($document !== null);
    }

    $invoice = Capsule::table('tblinvoices')->where('id', (int) $params['invoiceid'])->first(['duedate']);
    if ($invoice === null) {
        return efi_pix_automatico_render_error('Fatura não encontrada.');
    }

    try {
        $result = (new PixAutomaticService($api, $params))->issueOrFetchInitial(
            (int) $params['invoiceid'],
            $clientId,
            Money::toCents($params['amount']),
            trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']),
            $document,
            $invoice->duedate
        );
    } catch (\Throwable $e) {
        GatewayLog::error(PixAutomaticService::GATEWAY, $params, 'Erro ao iniciar Pix Automático', $e->getMessage());
        return efi_pix_automatico_render_error($e->getMessage());
    }

    return efi_pix_automatico_render_result($result);
}

function efi_pix_automatico_render_document_form(bool $invalidAttempt): string
{
    $warning = $invalidAttempt ? '<p style="color:#b00020;">CPF/CNPJ inválido. Informe apenas os números.</p>' : '';

    return '<div class="efi-pix-box">' . $warning . '
        <form method="post">
            <label for="efi_pix_auto_document">CPF ou CNPJ do pagador</label>
            <input type="text" name="efi_pix_auto_document" id="efi_pix_auto_document" maxlength="18" required placeholder="Somente números">
            <button type="submit" class="btn btn-primary">Autorizar Pix Automático</button>
        </form>
    </div>';
}

function efi_pix_automatico_render_result(array $result): string
{
    return '<div class="efi-pix-box">
        <p><strong>Pague esta fatura e autorize os próximos pagamentos por Pix Automático.</strong></p>
        <p>Abra o aplicativo do seu banco, escolha Pix copia e cola e confirme a autorização.</p>
        <textarea readonly rows="4" style="width:100%;" onclick="this.select();">' . htmlspecialchars($result['copy_paste']) . '</textarea>
        <p>A autorização fica pendente até a confirmação no seu banco.</p>
    </div>';
}

function efi_pix_automatico_render_error(string $message): string
{
    return '<div class="efi-pix-box efi-pix-error"><p style="color:#b00020;">' . htmlspecialchars($message) . '</p></div>';
}
