<?php

/**
 * Efí - Boleto Bancário (WHMCS Gateway Module - Third Party / transparente).
 *
 * Renderiza o status/boleto diretamente na página da fatura (sem popup/iframe). Ver
 * docs/ARQUITETURA.md para o desenho completo.
 */

use EfiWhmcs\Boleto\BoletoService;
use EfiWhmcs\Ledger\Schema as EfiSchema;
use EfiWhmcs\Support\CustomerResolver;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use EfiWhmcs\Support\Money;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/efi/vendor/autoload.php';

function efi_boleto_MetaData()
{
    return [
        'DisplayName' => 'Efí - Boleto Bancário',
        'APIVersion' => '1.1',
    ];
}

function efi_boleto_config()
{
    $common = \EfiWhmcs\Config\ConfigSchema::common('Efí - Boleto Bancário');

    return $common + [
        'due_days_offset' => [
            'FriendlyName' => 'Dias corridos para o vencimento',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '0',
            'Description' => 'Somado à data de vencimento da fatura no WHMCS para calcular o vencimento do boleto na Efí. Use 0 para manter a mesma data. Este offset é aplicado de forma consistente na emissão e em qualquer realinhamento futuro (ver documentação).',
        ],
        'days_to_write_off' => [
            'FriendlyName' => 'Dias para baixa automática após vencimento',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '',
            'Description' => 'De 0 a 120. Deixe em branco para usar o padrão da sua conta Efí. Após esse prazo sem pagamento identificado, o boleto expira.',
        ],
        'fine_percent' => [
            'FriendlyName' => 'Multa por atraso (%)',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '0',
            'Description' => 'Entre 0,01% e 10%. 0 desativa.',
        ],
        'interest_percent' => [
            'FriendlyName' => 'Juros ao dia por atraso (%)',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '0',
            'Description' => 'Entre 0,001% e 0,33% ao dia. 0 desativa.',
        ],
        'discount_type' => [
            'FriendlyName' => 'Tipo de desconto',
            'Type' => 'dropdown',
            'Options' => [
                'none' => 'Sem desconto',
                'percentage' => 'Percentual (%)',
                'currency' => 'Valor fixo (R$)',
            ],
        ],
        'discount_value' => [
            'FriendlyName' => 'Valor do desconto',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => 'Interpretado conforme o tipo selecionado acima.',
        ],
        'message' => [
            'FriendlyName' => 'Mensagem no boleto',
            'Type' => 'text',
            'Size' => '80',
            'Description' => 'Até 80 caracteres, exibida no boleto para o cliente.',
        ],
        'document_field_name' => [
            'FriendlyName' => 'Campo personalizado de CPF/CNPJ',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'CPF/CNPJ',
            'Description' => 'Nome exato do Campo Personalizado de Cliente que guarda o CPF/CNPJ. Se vazio para um cliente, será solicitado no formulário de pagamento.',
        ],
    ];
}

function efi_boleto_config_validate($params)
{
    EfiSchema::ensure();

    $errors = [];

    if (trim((string) ($params['client_id'] ?? '')) === '' || trim((string) ($params['client_secret'] ?? '')) === '') {
        $errors[] = 'Client ID e Client Secret são obrigatórios.';
    }

    $fine = (float) ($params['fine_percent'] ?? 0);
    if ($fine < 0 || $fine > 10) {
        $errors[] = 'Multa por atraso deve estar entre 0 e 10%.';
    }

    $interest = (float) ($params['interest_percent'] ?? 0);
    if ($interest < 0 || $interest > 0.33) {
        $errors[] = 'Juros ao dia deve estar entre 0 e 0,33%.';
    }

    if ($errors !== []) {
        return ['errors' => $errors];
    }

    return [];
}

/**
 * Third Party Gateway: HTML retornado é embutido diretamente na página da fatura do WHMCS
 * (sem popup/iframe/redirecionamento). Esta função cobre: pedir CPF/CNPJ quando necessário,
 * emitir o boleto e mostrar o link/status já existentes.
 */
function efi_boleto_link($params)
{
    try {
        $api = EfiClientFactory::make($params);
    } catch (\Throwable $e) {
        return efi_boleto_render_error('Configuração do gateway inválida: ' . $e->getMessage());
    }

    $service = new BoletoService($api, $params);
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
        return efi_boleto_render_document_form($params, $documentDigits !== null);
    }

    $customerName = trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']);

    try {
        $result = $service->issueOrFetch(
            $invoiceId,
            efi_boleto_invoice_due_date($invoiceId),
            [[
                'name' => 'Fatura #' . $invoiceId . ' - ' . $params['companyname'],
                'amount' => 1,
                'value_cents' => Money::toCents($params['amount']),
            ]],
            $customerName,
            $documentDigits,
            Money::toCents($params['amount'])
        );
    } catch (\Throwable $e) {
        GatewayLog::error('efi_boleto', $params, 'Erro ao emitir boleto', $e->getMessage());

        return efi_boleto_render_error($e->getMessage());
    }

    return efi_boleto_render_result($result);
}

function efi_boleto_invoice_due_date(int $invoiceId): string
{
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    return $invoice->duedate ?? date('Y-m-d');
}

function efi_boleto_render_document_form($params, bool $invalidAttempt): string
{
    $warning = $invalidAttempt
        ? '<p style="color:#b00020;">CPF/CNPJ inválido. Informe apenas os números (11 ou 14 dígitos).</p>'
        : '';

    return '<div class="efi-boleto-box">' . $warning . '
        <form method="post">
            <label for="efi_document">CPF ou CNPJ do pagador</label>
            <input type="text" name="efi_document" id="efi_document" maxlength="18" required
                   placeholder="Somente números">
            <button type="submit" class="btn btn-primary">Gerar boleto</button>
        </form>
    </div>';
}

function efi_boleto_render_result(array $result): string
{
    $status = htmlspecialchars($result['status']);
    $expireAt = htmlspecialchars((string) $result['expire_at']);

    $linkHtml = $result['link']
        ? '<p><a class="btn btn-primary" target="_blank" href="' . htmlspecialchars($result['link']) . '">Visualizar boleto</a></p>'
        : '';

    $barcodeHtml = $result['barcode']
        ? '<p><strong>Linha digitável:</strong><br><code>' . htmlspecialchars($result['barcode']) . '</code></p>'
        : '';

    return "<div class=\"efi-boleto-box\">
        <p>Status da cobrança: <strong>{$status}</strong> &middot; Vencimento: <strong>{$expireAt}</strong></p>
        {$linkHtml}{$barcodeHtml}
        <p><small>A confirmação de pagamento é automática assim que o banco processa o boleto.</small></p>
    </div>";
}

function efi_boleto_render_error(string $message): string
{
    return '<div class="efi-boleto-box efi-boleto-error"><p style="color:#b00020;">' . htmlspecialchars($message) . '</p></div>';
}

// `efi_boleto_refund` deliberadamente NÃO é implementada: a API de Cobranças da Efí não expõe
// um endpoint de estorno automático para boleto bancário já pago (diferente de cartão, que tem
// `refundCard`). Reembolso de boleto é operação manual (TED/depósito) fora da API -- não há
// nada seguro para automatizar aqui.
