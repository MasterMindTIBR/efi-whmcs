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


use EfiWhmcs\Boleto\BoletoService;
use EfiWhmcs\PixAutomatic\PixAutomaticService;
use EfiWhmcs\Support\AdminCsrf;
use EfiWhmcs\Support\EfiClientFactory;
use EfiWhmcs\Support\GatewayLoader;
use EfiWhmcs\Support\GatewayLog;
use WHMCS\Database\Capsule;

/**
 * `null` quando o módulo não está configurado/ativo -- ver `GatewayLoader` para o porquê de
 * nunca chamar `getGatewayVariables()` direto aqui (ela derrubaria hooks que também cobrem
 * outro gateway Efí ativo).
 */
function efi_gateway_variables(string $module): ?array
{
    return GatewayLoader::variables($module);
}

add_hook('InvoiceCancelled', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    $boletoParams = efi_gateway_variables('efi_boleto');

    if ($boletoParams !== null) {
        try {
            $api = EfiClientFactory::make($boletoParams);
            (new BoletoService($api, $boletoParams))->cancel($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $boletoParams, 'Falha ao cancelar boleto no hook InvoiceCancelled', $e->getMessage());
        }
    }

    $pixParams = efi_gateway_variables('efi_pix');
    if ($pixParams !== null) {
        try {
            $api = EfiClientFactory::make($pixParams, true);
            (new EfiWhmcs\Pix\PixService($api, $pixParams))->cancelInvoiceCharge($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error('efi_pix', $pixParams, 'Falha ao cancelar Pix no hook InvoiceCancelled', $e->getMessage());
        }
    }

    $automaticParams = efi_gateway_variables(PixAutomaticService::GATEWAY);
    if ($automaticParams !== null) {
        try {
            $api = EfiClientFactory::make($automaticParams, true);
            (new PixAutomaticService($api, $automaticParams))->cancelInvoiceCharge($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Falha ao cancelar cobrança Pix Automático no hook InvoiceCancelled', $e->getMessage());
        }
    }
});

/**
 * Mesmo tratamento para InvoicePaid, InvoiceDeleted e InvoiceRefunded: uma fatura que sai do
 * ciclo de cobrança por qualquer motivo não pode deixar boleto, Pix ou CobR em aberto -- é
 * assim que a mesma fatura acaba sendo paga duas vezes (uma por outro meio, outra quando o
 * cliente ainda consegue pagar a cobrança Efí esquecida). Reusa exatamente a mesma lógica do
 * InvoiceCancelled.
 */
function efi_cancel_open_charges(int $invoiceId): void
{
    $boletoParams = efi_gateway_variables('efi_boleto');
    if ($boletoParams !== null) {
        try {
            $api = EfiClientFactory::make($boletoParams);
            (new BoletoService($api, $boletoParams))->cancel($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $boletoParams, 'Falha ao cancelar boleto após a fatura sair do ciclo de cobrança', $e->getMessage());
        }
    }

    $pixParams = efi_gateway_variables('efi_pix');
    if ($pixParams !== null) {
        try {
            $api = EfiClientFactory::make($pixParams, true);
            (new EfiWhmcs\Pix\PixService($api, $pixParams))->cancelInvoiceCharge($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error('efi_pix', $pixParams, 'Falha ao cancelar Pix após a fatura sair do ciclo de cobrança', $e->getMessage());
        }
    }

    $automaticParams = efi_gateway_variables(PixAutomaticService::GATEWAY);
    if ($automaticParams !== null) {
        try {
            $api = EfiClientFactory::make($automaticParams, true);
            (new PixAutomaticService($api, $automaticParams))->cancelInvoiceCharge($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Falha ao cancelar cobrança Pix Automático após a fatura sair do ciclo de cobrança', $e->getMessage());
        }
    }
}

add_hook('InvoicePaid', 1, function ($vars) {
    efi_cancel_open_charges((int) ($vars['invoiceid'] ?? 0));
});

add_hook('InvoiceRefunded', 1, function ($vars) {
    efi_cancel_open_charges((int) ($vars['invoiceid'] ?? 0));
});

add_hook('InvoiceDeleted', 1, function ($vars) {
    efi_cancel_open_charges((int) ($vars['invoiceid'] ?? 0));
});

add_hook('InvoiceCreated', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'userid', 'paymentmethod', 'status', 'total', 'duedate']);

    if ($invoice === null || $invoice->paymentmethod !== PixAutomaticService::GATEWAY || $invoice->status !== 'Unpaid') {
        return;
    }

    $gatewayParams = efi_gateway_variables(PixAutomaticService::GATEWAY);
    if ($gatewayParams === null) {
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

    $boletoParams = efi_gateway_variables('efi_boleto');

    if ($boletoParams !== null) {
        try {
            $api = EfiClientFactory::make($boletoParams);
            $message = (new BoletoService($api, $boletoParams))->realignDueDate($invoiceId, $invoice->duedate, EfiWhmcs\Support\Money::toCents($invoice->total));

            if ($message !== null) {
                logTransaction('efi_boleto', ['invoice_id' => $invoiceId, 'new_duedate' => $invoice->duedate], $message);
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $boletoParams, 'Falha ao realinhar vencimento/valor no hook UpdateInvoiceTotal', $e->getMessage());
        }
    }

    $pixParams = efi_gateway_variables('efi_pix');

    if ($pixParams === null || $invoice->paymentmethod !== 'efi_pix') {
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
 * bloqueando o IP da Efí, etc.): reconsulta diretamente cobranças de boleto/Pix/cartão que
 * continuam "em aberto" no nosso ledger há mais de 2 dias, e aplica o pagamento se a Efí já o
 * confirmou. Parada de 60 dias no outro extremo: uma cobrança mais velha que isso já teve
 * reconciliação suficiente e só continuaria crescendo a consulta diária para sempre; vira um
 * aviso no Module Log em vez de repetir a consulta à Efí indefinidamente.
 *
 * Também agenda, dentro da janela de 2 a 10 dias exigida pelo Bacen, as CobRs do Pix Automático
 * que o hook `InvoiceCreated` adiou por a fatura ter nascido cedo demais, e reprocessa qualquer
 * CobR que ficou `error` na última tentativa.
 */
add_hook('DailyCronJob', 1, function () {
    if (!function_exists('getInvoiceStatusColour')) {
        App::load_function('invoice');
    }

    EfiWhmcs\Ledger\Schema::ensure();

    $staleSince = date('Y-m-d H:i:s', strtotime('-2 days'));
    $tooOldSince = date('Y-m-d H:i:s', strtotime('-60 days'));

    $staleBoletos = Capsule::table('mod_efi_charges')
        ->where('rail', 'boleto')
        ->whereIn('status', ['new', 'waiting', 'unpaid'])
        ->whereBetween('created_at', [$tooOldSince, $staleSince])
        ->get();

    if ($staleBoletos->isNotEmpty()) {
        efi_reconcile_boleto($staleBoletos);
    }

    $stalePix = Capsule::table('mod_efi_charges')
        ->where('rail', 'pix')
        ->whereIn('status', ['ATIVA', 'new'])
        ->whereBetween('created_at', [$tooOldSince, $staleSince])
        ->get();

    if ($stalePix->isNotEmpty()) {
        efi_reconcile_pix($stalePix);
    }

    $staleCards = Capsule::table('mod_efi_charges')
        ->where('rail', 'cartao')
        ->where('status', 'waiting')
        ->whereBetween('created_at', [$tooOldSince, $staleSince])
        ->get();

    if ($staleCards->isNotEmpty()) {
        efi_reconcile_cartao($staleCards);
    }

    $tooOldCharges = Capsule::table('mod_efi_charges')
        ->whereIn('rail', ['boleto', 'pix', 'cartao'])
        ->whereIn('status', ['new', 'waiting', 'unpaid', 'ATIVA'])
        ->where('created_at', '<', $tooOldSince)
        ->get();

    foreach ($tooOldCharges as $charge) {
        GatewayLog::error($charge->rail === 'cartao' ? 'efi_cartao' : ('efi_' . $charge->rail), [], 'Cobrança aberta há mais de 60 dias sem confirmação -- revisão manual necessária', [
            'invoice_id' => $charge->invoice_id,
            'charge_id' => $charge->efi_charge_id,
            'status' => $charge->status,
            'created_at' => $charge->created_at,
        ]);
    }

    EfiWhmcs\PixAutomatic\Schema::ensure();

    $staleAutomaticPix = Capsule::table('mod_efi_pix_auto_charges')
        ->whereIn('status', ['creating', 'CRIADA', 'ATIVA'])
        ->where('updated_at', '<', $staleSince)
        ->get();

    if ($staleAutomaticPix->isNotEmpty()) {
        efi_reconcile_pix_automatico($staleAutomaticPix);
    }

    $automaticParams = efi_gateway_variables(PixAutomaticService::GATEWAY);
    if ($automaticParams !== null) {
        try {
            $api = EfiClientFactory::make($automaticParams, true);
            $service = new PixAutomaticService($api, $automaticParams);

            $dueInvoices = Capsule::table('tblinvoices')
                ->where('paymentmethod', PixAutomaticService::GATEWAY)
                ->where('status', 'Unpaid')
                ->whereBetween('duedate', [date('Y-m-d', strtotime('+2 days')), date('Y-m-d', strtotime('+10 days'))])
                ->get(['id', 'userid', 'total', 'duedate']);

            foreach ($dueInvoices as $invoice) {
                try {
                    $message = $service->scheduleInvoice($invoice);
                    if ($message !== null) {
                        logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $invoice->id], $message);
                    }
                } catch (\Throwable $e) {
                    GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Falha ao agendar CobR dentro da janela no cron', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
                }
            }

            $erroredCharges = Capsule::table('mod_efi_pix_auto_charges')->where('status', 'error')->get();
            foreach ($erroredCharges as $charge) {
                try {
                    $message = $service->retryErroredCharge($charge);
                    if ($message !== null) {
                        logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $charge->invoice_id], 'Retentativa de CobR com erro: ' . $message);
                    }
                } catch (\Throwable $e) {
                    GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Falha ao reprocessar CobR com erro no cron', ['invoice' => $charge->invoice_id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            GatewayLog::error(PixAutomaticService::GATEWAY, $automaticParams, 'Cron Pix Automático: falha ao montar client', $e->getMessage());
        }
    }
});

function efi_reconcile_boleto($charges): void
{
    $gatewayParams = efi_gateway_variables('efi_boleto');

    if ($gatewayParams === null) {
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

function efi_reconcile_cartao($charges): void
{
    $gatewayParams = efi_gateway_variables('efi_cartao');

    if ($gatewayParams === null) {
        return;
    }

    try {
        $api = EfiClientFactory::make($gatewayParams);
    } catch (\Throwable $e) {
        GatewayLog::error('efi_cartao', $gatewayParams, 'Reconciliação diária: falha ao montar client', $e->getMessage());
        return;
    }

    foreach ($charges as $charge) {
        try {
            $detail = $api->detailCharge(['id' => $charge->efi_charge_id]);
            $status = (string) ($detail['data']['status']['current'] ?? $detail['status'] ?? '');

            if (in_array($status, ['paid', 'approved'], true) && $charge->status !== 'paid') {
                logTransaction('efi_cartao', ['invoice_id' => $charge->invoice_id, 'charge_id' => $charge->efi_charge_id], 'Reconciliação diária detectou pagamento de cartão não aplicado pelo callback');
                $invoiceId = checkCbInvoiceID((int) $charge->invoice_id, 'efi_cartao');
                checkCbTransID($charge->efi_charge_id . ':reconcile');
                addInvoicePayment($invoiceId, $charge->efi_charge_id, 0, 0, 'efi_cartao');
                EfiWhmcs\Ledger\ChargeRepository::updateStatus($charge->id, 'paid');
            } elseif ($status !== '' && $status !== $charge->status) {
                EfiWhmcs\Ledger\ChargeRepository::updateStatus($charge->id, $status);
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_cartao', $gatewayParams, 'Reconciliação diária falhou para charge_id ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

function efi_reconcile_pix($charges): void
{
    $gatewayParams = efi_gateway_variables('efi_pix');

    if ($gatewayParams === null) {
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
                $e2eId = (string) ($recebido['endToEndId'] ?? $charge->efi_charge_id);
                logTransaction('efi_pix', ['invoice_id' => $charge->invoice_id, 'txid' => $charge->efi_charge_id], 'Reconciliação diária detectou pagamento Pix não notificado via webhook');
                $invoiceId = checkCbInvoiceID((int) $charge->invoice_id, 'efi_pix');
                checkCbTransID($e2eId . ':reconcile');
                addInvoicePayment($invoiceId, $e2eId, \EfiWhmcs\Support\Money::toReais((int) round(((float) $recebido['valor']) * 100)), 0, 'efi_pix');

                $metadata = json_decode((string) $charge->metadata, true) ?: [];
                $metadata['e2e_id'] = $e2eId;
                EfiWhmcs\Ledger\ChargeRepository::replacePixCharge(
                    $charge->id,
                    $charge->efi_charge_id,
                    $charge->efi_loc_id,
                    'CONCLUIDA',
                    (int) $charge->amount_cents,
                    json_encode($metadata) ?: '{}'
                );
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_pix', $gatewayParams, 'Reconciliação diária falhou para txid ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

function efi_reconcile_pix_automatico($charges): void
{
    $gatewayParams = efi_gateway_variables(PixAutomaticService::GATEWAY);
    if ($gatewayParams === null) {
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
    $csrfToken = AdminCsrf::token();
    $cardParams = efi_gateway_variables('efi_cartao');

    if ($cardParams !== null) {
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
                    body: "invoiceid=' . $invoiceId . '&csrf=' . rawurlencode($csrfToken) . '"
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

    $pixParams = efi_gateway_variables('efi_pix');
    $paymentMethod = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('paymentmethod');

    if ($pixParams !== null && $paymentMethod === 'efi_pix') {
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
                    body: "invoiceid=' . $invoiceId . '&csrf=' . rawurlencode($csrfToken) . '"
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
