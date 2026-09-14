<?php

/**
 * Hooks compartilhados pelos módulos de gateway Efí.
 *
 * Instalar em /hooks/efi_hooks.php (raiz do WHMCS -- NÃO dentro de modules/gateways). O WHMCS
 * carrega automaticamente todo arquivo PHP colocado em /hooks/.
 *
 * Responsabilidades:
 *  - InvoiceCancelled: cancela o boleto Efí em aberto associado à fatura (efi_boleto).
 *  - UpdateInvoiceTotal: realinha o vencimento do boleto quando o vencimento da fatura muda
 *    (efi_boleto) -- ver docs/ARQUITETURA.md §1.7. Cartão/Pix não têm vencimento a realinhar.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../modules/gateways/efi/vendor/autoload.php';

App::load_function('gateway');
App::load_function('invoice');

use EfiWhmcs\Boleto\BoletoService;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use WHMCS\Database\Capsule;

add_hook('InvoiceCancelled', 1, function ($vars) {
    $gatewayParams = getGatewayVariables('efi_boleto');

    if (!$gatewayParams['type']) {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams);
        (new BoletoService($api, $gatewayParams))->cancel((int) $vars['invoiceid']);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_boleto', $gatewayParams, 'Falha ao cancelar boleto no hook InvoiceCancelled', $e->getMessage());
    }
});

add_hook('UpdateInvoiceTotal', 1, function ($vars) {
    $gatewayParams = getGatewayVariables('efi_boleto');

    if (!$gatewayParams['type']) {
        return;
    }

    $invoiceId = (int) $vars['invoiceid'];
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    if ($invoice === null || $invoice->status === 'Paid' || $invoice->status === 'Cancelled') {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams);
        $message = (new BoletoService($api, $gatewayParams))->realignDueDate($invoiceId, $invoice->duedate);

        if ($message !== null) {
            logTransaction('efi_boleto', ['invoice_id' => $invoiceId, 'new_duedate' => $invoice->duedate], $message);
        }
    } catch (\Throwable $e) {
        GatewayLog::error('efi_boleto', $gatewayParams, 'Falha ao realinhar vencimento no hook UpdateInvoiceTotal', $e->getMessage());
    }
});

/**
 * Rede de segurança contra falha pontual de callback (fila de notificação enguiçada, firewall
 * bloqueando o IP da Efí, etc.): reconsulta diretamente cobranças de boleto/Pix que continuam
 * "em aberto" no nosso ledger há mais de alguns dias, e aplica o pagamento se a Efí já o
 * confirmou. Ver docs/ARQUITETURA.md, sugestão adicional §2.
 */
add_hook('DailyCronJob', 1, function () {
    EfiWhmcs\Ledger\Schema::ensure();

    $staleSince = date('Y-m-d H:i:s', strtotime('-2 days'));

    $staleBoletos = Capsule::table('mod_efi_charges')
        ->where('rail', 'boleto')
        ->whereIn('status', ['new', 'waiting', 'unpaid'])
        ->where('created_at', '<', $staleSince)
        ->get();

    if ($staleBoletos->isNotEmpty()) {
        efi_reconcile_boleto($staleBoletos);
    }

    $stalePix = Capsule::table('mod_efi_charges')
        ->where('rail', 'pix')
        ->whereIn('status', ['ATIVA', 'new'])
        ->where('created_at', '<', $staleSince)
        ->get();

    if ($stalePix->isNotEmpty()) {
        efi_reconcile_pix($stalePix);
    }
});

function efi_reconcile_boleto($charges): void
{
    $gatewayParams = getGatewayVariables('efi_boleto');

    if (!$gatewayParams['type']) {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_boleto', $gatewayParams, 'Reconciliação diária: falha ao montar client', $e->getMessage());
        return;
    }

    foreach ($charges as $charge) {
        try {
            $detail = $api->detailCharge(['id' => $charge->efi_charge_id]);
            $status = (string) ($detail['data']['status']['current'] ?? $detail['status'] ?? '');

            if ($status === 'paid' && $charge->status !== 'paid') {
                logTransaction('efi_boleto', ['invoice_id' => $charge->invoice_id, 'charge_id' => $charge->efi_charge_id], 'Reconciliação diária detectou pagamento não notificado via webhook');
                $invoiceId = checkCbInvoiceID((int) $charge->invoice_id, 'efi_boleto');
                checkCbTransID($charge->efi_charge_id . ':reconcile');
                addInvoicePayment($invoiceId, $charge->efi_charge_id, 0, 0, 'efi_boleto');
                EfiWhmcs\Ledger\ChargeRepository::updateStatus($charge->id, 'paid');
            } elseif ($status !== '' && $status !== $charge->status) {
                EfiWhmcs\Ledger\ChargeRepository::updateStatus($charge->id, $status);
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $gatewayParams, 'Reconciliação diária falhou para charge_id ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

function efi_reconcile_pix($charges): void
{
    $gatewayParams = getGatewayVariables('efi_pix');

    if (!$gatewayParams['type']) {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams, true);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_pix', $gatewayParams, 'Reconciliação diária: falha ao montar client', $e->getMessage());
        return;
    }

    foreach ($charges as $charge) {
        try {
            $detail = $api->pixDetailCharge(['txid' => $charge->efi_charge_id]);
            $status = (string) ($detail['status'] ?? '');
            $pixRecebidos = $detail['pix'] ?? [];

            if ($status === 'CONCLUIDA' && $pixRecebidos !== [] && $charge->status !== 'CONCLUIDA') {
                $recebido = $pixRecebidos[0];
                logTransaction('efi_pix', ['invoice_id' => $charge->invoice_id, 'txid' => $charge->efi_charge_id], 'Reconciliação diária detectou pagamento Pix não notificado via webhook');
                $invoiceId = checkCbInvoiceID((int) $charge->invoice_id, 'efi_pix');
                checkCbTransID(($recebido['endToEndId'] ?? $charge->efi_charge_id) . ':reconcile');
                addInvoicePayment($invoiceId, $recebido['endToEndId'] ?? $charge->efi_charge_id, \EfiWhmcs\Support\Money::toReais((int) round(((float) $recebido['valor']) * 100)), 0, 'efi_pix');
                EfiWhmcs\Ledger\ChargeRepository::updateStatus($charge->id, 'CONCLUIDA');
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_pix', $gatewayParams, 'Reconciliação diária falhou para txid ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

/**
 * Botão "Tentar Capturar Pagamento (Efí)" na página de fatura do admin, para faturas com
 * cartão Efí salvo. Encapsula a ação nativa `CapturePayment` da API local -- ver
 * modules/gateways/efi_cartao/admin_capture.php. Requer confirmação em ambiente real (nome
 * exato da chave de invoiceid em $vars pode variar entre versões do WHMCS).
 */
add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    $gatewayParams = getGatewayVariables('efi_cartao');

    if (!$gatewayParams['type']) {
        return '';
    }

    $invoiceId = (int) ($vars['invoiceid'] ?? $vars['id'] ?? 0);

    if ($invoiceId <= 0) {
        return '';
    }

    $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');

    return '<button type="button" class="btn btn-default btn-sm" id="efiCaptureBtn' . $invoiceId . '">Tentar Capturar Pagamento (Efí)</button>
    <script>
    (function () {
        var btn = document.getElementById("efiCaptureBtn' . $invoiceId . '");
        if (!btn) { return; }
        btn.addEventListener("click", function () {
            btn.disabled = true;
            fetch("' . $systemUrl . '/modules/gateways/efi_cartao/admin_capture.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "invoiceid=' . $invoiceId . '"
            }).then(function (r) { return r.json(); }).then(function (data) {
                alert(data.message || (data.success ? "Sucesso" : "Falha"));
                window.location.reload();
            }).catch(function () {
                btn.disabled = false;
                alert("Falha ao chamar o endpoint de captura.");
            });
        });
    })();
    </script>';
});

/**
 * Botão "Testar Conexão" na tela de configuração de cada gateway Efí -- ver
 * modules/gateways/efi/admin_diagnostics.php. `$vars['filename']` precisa ser confirmado em
 * ambiente real (esperado: "configgateways" na tela de Gateways de Pagamento).
 */
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    if (($vars['filename'] ?? '') !== 'configgateways') {
        return '';
    }

    $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');

    return '<script>
    (function () {
        ["efi_boleto", "efi_pix", "efi_cartao"].forEach(function (mod) {
            var panel = document.querySelector("a[href*=\'gateway=" + mod + "\']");
            if (!panel) { return; }
        });

        function addTestButton(moduleName) {
            var forms = document.querySelectorAll("form[action*=\'" + moduleName + "\']");
            forms.forEach(function (form) {
                if (form.querySelector(".efi-test-connection")) { return; }
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "btn btn-default efi-test-connection";
                btn.textContent = "Testar Conexão";
                btn.addEventListener("click", function () {
                    btn.disabled = true;
                    fetch("' . $systemUrl . '/modules/gateways/efi/admin_diagnostics.php", {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "module=" + moduleName
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        alert(data.message);
                        btn.disabled = false;
                    }).catch(function () {
                        btn.disabled = false;
                        alert("Falha ao testar conexão.");
                    });
                });
                form.appendChild(btn);
            });
        }

        addTestButton("efi_boleto");
        addTestButton("efi_pix");
        addTestButton("efi_cartao");
    })();
    </script>';
});

/**
 * Expõe campos de merge `{$efi_boleto_link}` / `{$efi_boleto_barcode}` /
 * `{$efi_pix_copiaecola}` para uso manual nos modelos de email (Fatura Criada, Lembretes de
 * Pagamento, etc.) -- ver README para instruções de uso no template.
 */
add_hook('EmailTplMergeFields', 1, function ($vars) {
    $relId = (int) ($vars['relid'] ?? 0);
    $relType = (string) ($vars['reltype'] ?? '');

    if ($relId <= 0 || $relType !== 'Invoice') {
        return [];
    }

    EfiWhmcs\Ledger\Schema::ensure();

    $merge = [];
    $boleto = EfiWhmcs\Ledger\ChargeRepository::findByInvoiceAndRail($relId, 'boleto');

    if ($boleto !== null && in_array($boleto->status, ['new', 'waiting', 'unpaid'], true)) {
        $metadata = json_decode((string) $boleto->metadata, true) ?: [];
        $merge['efi_boleto_link'] = $metadata['link'] ?? '';
        $merge['efi_boleto_barcode'] = $metadata['barcode'] ?? '';
    }

    $pix = EfiWhmcs\Ledger\ChargeRepository::findByInvoiceAndRail($relId, 'pix');

    if ($pix !== null && $pix->status !== 'CONCLUIDA') {
        $metadata = json_decode((string) $pix->metadata, true) ?: [];
        $merge['efi_pix_copiaecola'] = $metadata['copy_paste'] ?? '';
    }

    return $merge;
});
