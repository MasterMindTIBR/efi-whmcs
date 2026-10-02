<?php

/**
 * Hooks compartilhados pelos módulos de gateway Efí.
 *
 * Instalar em /includes/hooks/efi_hooks.php (raiz do WHMCS -- NÃO dentro de modules/gateways).
 * O WHMCS carrega automaticamente todo arquivo PHP colocado em /includes/hooks/.
 *
 * Responsabilidades:
 *  - InvoiceCancelled: cancela o boleto Efí em aberto associado à fatura (efi_boleto).
 *  - UpdateInvoiceTotal: realinha o vencimento do boleto e revisa o valor de Pix ativo quando o
 *    total da fatura muda.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../modules/gateways/efi/vendor/autoload.php';

if (!function_exists('getGatewayVariables')) {
    App::load_function('gateway');
}

use EfiWhmcs\Boleto\BoletoService;
use EfiWhmcs\PixAutomatic\PixAutomaticService;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLog;
use WHMCS\Database\Capsule;

add_hook('InvoiceCancelled', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    $boletoParams = getGatewayVariables('efi_boleto');

    if (!empty($boletoParams['type'])) {
        try {
            $api = EfiClientFactory::make($boletoParams);
            (new BoletoService($api, $boletoParams))->cancel($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $boletoParams, 'Falha ao cancelar boleto no hook InvoiceCancelled', $e->getMessage());
        }
    }

    $automaticParams = getGatewayVariables(PixAutomaticService::GATEWAY);
    if (!empty($automaticParams['type'])) {
        try {
            $api = EfiClientFactory::make($automaticParams, true);
            (new PixAutomaticService($api, $automaticParams))->cancelInvoiceCharge($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Falha ao cancelar cobrança Pix Automático no hook InvoiceCancelled', $e->getMessage());
        }
    }
});

add_hook('InvoiceCreated', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'userid', 'paymentmethod', 'status', 'total', 'duedate']);

    if ($invoice === null || $invoice->paymentmethod !== PixAutomaticService::GATEWAY || $invoice->status !== 'Unpaid') {
        return;
    }

    $gatewayParams = getGatewayVariables(PixAutomaticService::GATEWAY);
    if (empty($gatewayParams['type'])) {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams, true);
        $message = (new PixAutomaticService($api, $gatewayParams))->scheduleInvoice($invoice);
        if ($message !== null) {
            logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $invoiceId], $message);
        }
    } catch (\Throwable $e) {
        GatewayLog::error(PixAutomaticService::GATEWAY, $gatewayParams, 'Falha ao agendar Pix Automático no hook InvoiceCreated', $e->getMessage());
    }
});

add_hook('UpdateInvoiceTotal', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);

    if ($invoiceId <= 0) {
        return;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    if ($invoice === null || in_array($invoice->status, ['Paid', 'Cancelled', 'Refunded'], true)) {
        return;
    }

    $boletoParams = getGatewayVariables('efi_boleto');

    if ($boletoParams['type']) {
        try {
            $api = EfiClientFactory::make($boletoParams);
            $message = (new BoletoService($api, $boletoParams))->realignDueDate($invoiceId, $invoice->duedate);

            if ($message !== null) {
                logTransaction('efi_boleto', ['invoice_id' => $invoiceId, 'new_duedate' => $invoice->duedate], $message);
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $boletoParams, 'Falha ao realinhar vencimento no hook UpdateInvoiceTotal', $e->getMessage());
        }
    }

    $pixParams = getGatewayVariables('efi_pix');

    if (!$pixParams['type'] || $invoice->paymentmethod !== 'efi_pix') {
        return;
    }

    try {
        $api = EfiClientFactory::make($pixParams, true);
        $message = (new EfiWhmcs\Pix\PixService($api, $pixParams))->synchronizeAmount(
            $invoiceId,
            EfiWhmcs\Support\Money::toCents($invoice->total)
        );

        if ($message !== null) {
            logTransaction('efi_pix', ['invoice_id' => $invoiceId, 'total' => $invoice->total], $message);
        }
    } catch (\Throwable $e) {
        GatewayLog::error('efi_pix', $pixParams, 'Falha ao revisar Pix no hook UpdateInvoiceTotal', $e->getMessage());
    }
});

/**
 * Rede de segurança contra falha pontual de callback (fila de notificação enguiçada, firewall
 * bloqueando o IP da Efí, etc.): reconsulta diretamente cobranças de boleto/Pix que continuam
 * "em aberto" no nosso ledger há mais de alguns dias, e aplica o pagamento se a Efí já o
 * confirmou. Ver docs/ARQUITETURA.md, sugestão adicional §2.
 */
add_hook('DailyCronJob', 1, function () {
    if (!function_exists('getInvoiceStatusColour')) {
        App::load_function('invoice');
    }

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

    EfiWhmcs\PixAutomatic\Schema::ensure();
    $staleAutomaticPix = Capsule::table('mod_efi_pix_auto_charges')
        ->whereIn('status', ['creating', 'CRIADA', 'ATIVA'])
        ->where('updated_at', '<', $staleSince)
        ->get();

    if ($staleAutomaticPix->isNotEmpty()) {
        efi_reconcile_pix_automatico($staleAutomaticPix);
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

function efi_reconcile_pix_automatico($charges): void
{
    $gatewayParams = getGatewayVariables(PixAutomaticService::GATEWAY);
    if (empty($gatewayParams['type'])) {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams, true);
    } catch (\Throwable $e) {
        GatewayLog::error(PixAutomaticService::GATEWAY, $gatewayParams, 'Reconciliação diária: falha ao montar client', $e->getMessage());
        return;
    }

    foreach ($charges as $charge) {
        try {
            $detail = $api->pixDetailAutomaticCharge(['txid' => $charge->efi_charge_id]);
            $status = (string) ($detail['status'] ?? '');
            $attempts = $detail['tentativas'] ?? [];
            $settled = null;

            foreach ($attempts as $attempt) {
                if (in_array($attempt['status'] ?? null, ['LIQUIDADA', 'CONCLUIDA'], true)) {
                    $settled = $attempt;
                    break;
                }
            }

            if ($status === 'CONCLUIDA' && $settled !== null && !empty($settled['endToEndId'])) {
                $e2eId = (string) $settled['endToEndId'];
                if (!EfiWhmcs\PixAutomatic\Repository::tryClaimEvent('charge', (int) $charge->id, $e2eId, $charge->status, $status)) {
                    continue;
                }

                $invoiceId = checkCbInvoiceID((int) $charge->invoice_id, PixAutomaticService::GATEWAY);
                checkCbTransID($e2eId);
                addInvoicePayment($invoiceId, $e2eId, EfiWhmcs\Support\Money::toReais((int) $charge->amount_cents), 0, PixAutomaticService::GATEWAY);
                EfiWhmcs\PixAutomatic\Repository::updateCharge((int) $charge->id, [
                    'status' => $status,
                    'metadata' => json_encode(['e2e_id' => $e2eId]) ?: '{}',
                ]);
                logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $invoiceId, 'txid' => $charge->efi_charge_id, 'e2eId' => $e2eId], 'Reconciliação diária detectou cobrança Pix Automático concluída');
            } elseif ($status !== '' && $status !== $charge->status) {
                EfiWhmcs\PixAutomatic\Repository::updateCharge((int) $charge->id, ['status' => $status]);
            }
        } catch (\Throwable $e) {
            GatewayLog::error(PixAutomaticService::GATEWAY, $gatewayParams, 'Reconciliação diária falhou para cobrança Pix Automático ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

/**
 * Controles Efí na página de fatura do admin: captura de cartão e renovação segura de Pix.
 */
add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? $vars['id'] ?? 0);

    if ($invoiceId <= 0) {
        return '';
    }

    $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $controls = '';
    $cardParams = getGatewayVariables('efi_cartao');

    if ($cardParams['type']) {
        $controls .= '<button type="button" class="btn btn-default btn-sm" id="efiCaptureBtn' . $invoiceId . '">Tentar Capturar Pagamento (Efí)</button>
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
    }

    $pixParams = getGatewayVariables('efi_pix');
    $paymentMethod = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymentmethod');

    if ($pixParams['type'] && $paymentMethod === 'efi_pix') {
        $controls .= '<button type="button" class="btn btn-warning btn-sm" id="efiRegeneratePixBtn' . $invoiceId . '">Gerar nova cobrança Pix</button>
        <script>
        (function () {
            var btn = document.getElementById("efiRegeneratePixBtn' . $invoiceId . '");
            if (!btn) { return; }
            btn.addEventListener("click", function () {
                if (!window.confirm("A cobrança Pix atual será desativada e um novo QR Code será gerado. Continuar?")) { return; }
                btn.disabled = true;
                fetch("' . $systemUrl . '/modules/gateways/efi_pix/admin_regenerate.php", {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "invoiceid=' . $invoiceId . '"
                }).then(function (r) { return r.json(); }).then(function (data) {
                    alert(data.message || (data.success ? "Sucesso" : "Falha"));
                    if (data.success) { window.location.reload(); } else { btn.disabled = false; }
                }).catch(function () {
                    btn.disabled = false;
                    alert("Falha ao renovar a cobrança Pix.");
                });
            });
        })();
        </script>';
    }

    return $controls;
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
