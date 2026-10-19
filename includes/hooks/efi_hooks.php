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
use EfiWhmcs\Support\PaymentApplier;
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

/**
 * Serviço Pix com as credenciais do gateway DONO da cobrança ativa da fatura: pode ser o
 * efi_pix ou o efi_pix_automatico (Pix avulso de fallback), com o outro inativo.
 *
 * @return array{0: EfiWhmcs\Pix\PixService, 1: string, 2: array}|null
 */
function efi_pix_service_for_invoice(int $invoiceId): ?array
{
    $charge = EfiWhmcs\Ledger\ChargeRepository::findByInvoiceAndRail($invoiceId, EfiWhmcs\Pix\PixService::RAIL);
    if ($charge === null) {
        return null;
    }

    $module = EfiWhmcs\Pix\PixService::moduleOf($charge);
    $params = efi_gateway_variables($module);
    if ($params === null) {
        return null;
    }

    return [new EfiWhmcs\Pix\PixService(EfiClientFactory::make($params, true), $params, $module), $module, $params];
}

/**
 * InvoiceCancelled, InvoicePaid e InvoiceRefunded: uma fatura que sai do ciclo de cobrança por
 * qualquer motivo não pode deixar boleto, Pix, CobR ou cartão em análise em aberto -- é assim
 * que a mesma fatura acaba sendo paga duas vezes (uma por outro meio, outra quando a cobrança
 * Efí esquecida ainda é paga/aprovada).
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

    try {
        $pix = efi_pix_service_for_invoice($invoiceId);
        if ($pix !== null) {
            $pix[0]->cancelInvoiceCharge($invoiceId);
        }
    } catch (\Throwable $e) {
        GatewayLog::error($pix[1] ?? 'efi_pix', $pix[2] ?? [], 'Falha ao cancelar Pix após a fatura sair do ciclo de cobrança', $e->getMessage());
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

    $cardParams = efi_gateway_variables('efi_cartao');
    if ($cardParams !== null) {
        try {
            $api = EfiClientFactory::make($cardParams);
            (new EfiWhmcs\Card\CardService($api, $cardParams))->cancel($invoiceId);
        } catch (\Throwable $e) {
            GatewayLog::error('efi_cartao', $cardParams, 'Falha ao cancelar cobrança de cartão após a fatura sair do ciclo de cobrança', $e->getMessage());
        }
    }
}

add_hook('InvoiceCancelled', 1, function ($vars) {
    efi_cancel_open_charges((int) ($vars['invoiceid'] ?? 0));
});

add_hook('InvoicePaid', 1, function ($vars) {
    efi_cancel_open_charges((int) ($vars['invoiceid'] ?? 0));
});

add_hook('InvoiceRefunded', 1, function ($vars) {
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
            $message = (new BoletoService($api, $boletoParams))->realignDueDate($invoiceId, $invoice->duedate, EfiWhmcs\Support\InvoiceBalance::centsDue($invoiceId));

            if ($message !== null) {
                logTransaction('efi_boleto', ['invoice_id' => $invoiceId, 'new_duedate' => $invoice->duedate], $message);
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_boleto', $boletoParams, 'Falha ao realinhar vencimento/valor no hook UpdateInvoiceTotal', $e->getMessage());
        }
    }

    // Pix ativo (do efi_pix ou do Pix avulso do Pix Automático): revisa pelo SALDO, com as
    // credenciais do gateway dono da cobrança.
    try {
        $pix = efi_pix_service_for_invoice($invoiceId);
        if ($pix === null) {
            return;
        }

        $message = $pix[0]->synchronizeAmount($invoiceId, EfiWhmcs\Support\InvoiceBalance::centsDue($invoiceId));

        if ($message !== null) {
            logTransaction($pix[1], ['invoice_id' => $invoiceId, 'total' => $invoice->total], $message);
        }
    } catch (\Throwable $e) {
        GatewayLog::error($pix[1] ?? 'efi_pix', $pix[2] ?? [], 'Falha ao revisar Pix no hook UpdateInvoiceTotal', $e->getMessage());
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
    $creatingSince = date('Y-m-d H:i:s', strtotime('-10 minutes'));

    // A pre-API reservation can only be retried if the provider identifier was captured.
    // Otherwise the external outcome is unknowable and an operator must resolve it.
    $staleCreating = Capsule::table('mod_efi_charges')->where('status', 'creating')->where('updated_at', '<', $creatingSince)->get();
    foreach ($staleCreating as $charge) {
        if (empty($charge->efi_charge_id)) {
            EfiWhmcs\Ledger\ChargeRepository::updateStatus((int) $charge->id, 'failed-unknown');
            GatewayLog::error('efi_' . $charge->rail, [], 'Reserva de cobrança creating sem identificador Efí após 10 minutos; revisão manual necessária', ['invoice_id' => $charge->invoice_id, 'charge_id' => $charge->id]);
            continue;
        }
        if ($charge->rail === 'boleto') { efi_reconcile_boleto([$charge]); }
        if ($charge->rail === 'pix') { efi_reconcile_pix([$charge]); }
        if ($charge->rail === 'cartao') { efi_reconcile_cartao([$charge]); }
    }

    // A crash after claiming an event leaves it pending. Re-query the provider rather than
    // replaying its payload; the reconciliation functions generate a fresh durable claim.
    $pendingByRail = ['boleto' => [], 'pix' => [], 'cartao' => []];
    foreach (EfiWhmcs\Ledger\EventRepository::pendingOlderThan(10) as $event) {
        $charge = Capsule::table('mod_efi_charges')->where('id', $event->charge_id)->first();
        if ($charge !== null && isset($pendingByRail[$charge->rail])) { $pendingByRail[$charge->rail][$charge->id] = $charge; }
    }
    if ($pendingByRail['boleto'] !== []) { efi_reconcile_boleto(array_values($pendingByRail['boleto'])); }
    if ($pendingByRail['pix'] !== []) { efi_reconcile_pix(array_values($pendingByRail['pix'])); }
    if ($pendingByRail['cartao'] !== []) { efi_reconcile_cartao(array_values($pendingByRail['cartao'])); }

    // A reconciliação fecha o seu próprio evento (`reconcile:*`); o evento ORIGINAL que ficou
    // pending também precisa sair da fila quando a cobrança já está resolvida, senão ele volta
    // todos os dias, para sempre.
    foreach (array_merge(...array_values($pendingByRail)) as $charge) {
        $current = (string) Capsule::table('mod_efi_charges')->where('id', $charge->id)->value('status');
        if (in_array($current, ['paid', 'CONCLUIDA', 'approved', 'settled'], true)) {
            EfiWhmcs\Ledger\EventRepository::closePendingForCharge((int) $charge->id, 'applied');
        } elseif (in_array($current, ['canceled', 'expired', 'superseded', 'refunded', 'partially_refunded', 'error', 'unpaid'], true)
            || str_starts_with($current, 'REMOVIDA')) {
            EfiWhmcs\Ledger\EventRepository::closePendingForCharge((int) $charge->id, 'failed');
        }
    }

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

    // 'approved' entra também: é estado transitório (ainda pode virar 'paid' ou ser revertido
    // pela antifraude) e precisa da mesma rede de segurança se a notificação se perder.
    $staleCards = Capsule::table('mod_efi_charges')
        ->where('rail', 'cartao')
        ->whereIn('status', ['waiting', 'approved'])
        ->whereBetween('created_at', [$tooOldSince, $staleSince])
        ->get();

    if ($staleCards->isNotEmpty()) {
        efi_reconcile_cartao($staleCards);
    }

    $tooOldCharges = Capsule::table('mod_efi_charges')
        ->whereIn('rail', ['boleto', 'pix', 'cartao'])
        ->whereIn('status', ['new', 'waiting', 'unpaid', 'ATIVA', 'approved'])
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
    $staleAutomaticCreating = Capsule::table('mod_efi_pix_auto_charges')
        ->where('status', 'creating')->where('updated_at', '<', $creatingSince)->get();
    foreach ($staleAutomaticCreating as $charge) {
        if (empty($charge->efi_charge_id)) {
            EfiWhmcs\PixAutomatic\Repository::updateCharge((int) $charge->id, ['status' => 'failed-unknown']);
            GatewayLog::error(PixAutomaticService::GATEWAY, [], 'Reserva de CobR creating sem txid após 10 minutos; revisão manual necessária', ['invoice_id' => $charge->invoice_id, 'charge_id' => $charge->id]);
            continue;
        }
        efi_reconcile_pix_automatico([$charge]);
    }
    $pendingAutomatic = EfiWhmcs\PixAutomatic\Repository::pendingChargeEventsOlderThan(10);
    if ($pendingAutomatic->isNotEmpty()) {
        efi_reconcile_pix_automatico($pendingAutomatic);
    }

    // A autorização (não a CobR por fatura) presa em 'creating': o POST pode já ter sido aceito
    // pela Efí sem a gravação local do idRec ter completado (processo morto no meio). Nunca
    // recriar sozinho -- pode já existir uma autorização ativa para aquele contrato/devedor no
    // Bacen; só sinaliza para revisão manual (ver README).
    $staleRecurrences = Capsule::table('mod_efi_pix_auto_recurrences')
        ->where('status', 'creating')->where('updated_at', '<', $creatingSince)->get();
    foreach ($staleRecurrences as $recurrence) {
        GatewayLog::error(PixAutomaticService::GATEWAY, [], 'Autorização Pix Automático presa em creating após 10 minutos; revisão manual necessária (pode já existir idRec na Efí sem registro local)', ['recurrence_id' => $recurrence->id, 'client_id' => $recurrence->client_id]);
    }

    $automaticParams = efi_gateway_variables(PixAutomaticService::GATEWAY);
    if ($automaticParams !== null) {
        efi_reconcile_pix_automatico_authorizations($automaticParams);
    }

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

    $boletoService = new BoletoService($api, $gatewayParams);

    foreach ($charges as $charge) {
        try {
            $detail = $api->detailCharge(['id' => $charge->efi_charge_id]);
            $status = (string) ($detail['data']['status'] ?? $detail['status'] ?? '');

            if ($status === 'paid') {
                logTransaction('efi_boleto', ['invoice_id' => $charge->invoice_id, 'charge_id' => $charge->efi_charge_id], 'Reconciliação diária detectou pagamento não notificado via webhook');
                $eventId = EfiWhmcs\Ledger\EventRepository::tryClaim((int) $charge->id, 'reconcile:' . $charge->efi_charge_id, $charge->status, 'paid', $charge->efi_charge_id);
                if ($eventId !== null) {
                    $amountCents = (int) ($detail['data']['value'] ?? $detail['value'] ?? $charge->amount_cents);
                    PaymentApplier::apply([
                        'invoiceId' => (int) $charge->invoice_id, 'transId' => (string) $charge->efi_charge_id,
                        'amountCents' => $amountCents, 'module' => 'efi_boleto', 'gatewayParams' => $gatewayParams,
                        'eventId' => $eventId, 'chargeId' => (int) $charge->id,
                        'markPaid' => static function () use ($boletoService, $charge, $amountCents): void {
                            // Status antes do desconto (o UpdateInvoice dispara UpdateInvoiceTotal).
                            EfiWhmcs\Ledger\ChargeRepository::updateStatus((int) $charge->id, 'paid');
                            $boletoService->applyDiscountLineItem((int) $charge->invoice_id, $amountCents, $charge);
                        },
                    ]);
                }
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

    $cardService = new EfiWhmcs\Card\CardService($api, $gatewayParams);

    foreach ($charges as $charge) {
        try {
            $detail = $api->detailCharge(['id' => $charge->efi_charge_id]);
            $status = (string) ($detail['data']['status'] ?? $detail['status'] ?? '');

            // Mesma política do checkout/callback: 'approved' já credita; reversão da
            // antifraude ('approved'/'paid' → 'unpaid'/'canceled') desfaz e reabre a fatura.
            if (in_array($status, ['approved', 'paid'], true)) {
                $eventId = EfiWhmcs\Ledger\EventRepository::tryClaim((int) $charge->id, 'reconcile:' . $status . ':' . $charge->efi_charge_id, $charge->status, $status, $charge->efi_charge_id);
                if ($eventId !== null) {
                    logTransaction('efi_cartao', ['invoice_id' => $charge->invoice_id, 'charge_id' => $charge->efi_charge_id, 'status' => $status], 'Reconciliação diária de cobrança de cartão');
                    $amountCents = (int) ($detail['data']['value'] ?? $detail['value'] ?? $charge->amount_cents);
                    PaymentApplier::apply([
                        'invoiceId' => (int) $charge->invoice_id, 'transId' => (string) $charge->efi_charge_id,
                        'amountCents' => $amountCents, 'module' => 'efi_cartao', 'gatewayParams' => $gatewayParams,
                        'chargeId' => (int) $charge->id, 'eventId' => $eventId, 'settledStatus' => $status,
                    ]);
                }
            } elseif ($status !== '' && $status !== $charge->status) {
                EfiWhmcs\Ledger\ChargeRepository::updateStatus($charge->id, $status);
                if (in_array($status, ['unpaid', 'canceled'], true)) {
                    $cardService->revertAppliedPayment($charge, $status);
                }
            }
        } catch (\Throwable $e) {
            GatewayLog::error('efi_cartao', $gatewayParams, 'Reconciliação diária falhou para charge_id ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

function efi_reconcile_pix($charges): void
{
    // Cada cobrança Pix é consultada com as credenciais do gateway que a emitiu (efi_pix ou o
    // Pix avulso do Pix Automático) -- antes só o efi_pix, e com ele inativo o Pix avulso
    // nunca era reconciliado.
    $clients = [];

    foreach ($charges as $charge) {
        $module = EfiWhmcs\Pix\PixService::moduleOf($charge);
        $gatewayParams = efi_gateway_variables($module);
        if ($gatewayParams === null) {
            continue;
        }

        try {
            $api = $clients[$module] ??= EfiClientFactory::make($gatewayParams, true);
            $detail = $api->pixDetailCharge(['txid' => $charge->efi_charge_id]);
            $status = (string) ($detail['status'] ?? '');
            $pixRecebidos = $detail['pix'] ?? [];

            if ($status === 'CONCLUIDA' && $pixRecebidos !== []) {
                $recebido = $pixRecebidos[0];
                $e2eId = (string) ($recebido['endToEndId'] ?? $charge->efi_charge_id);
                logTransaction($module, ['invoice_id' => $charge->invoice_id, 'txid' => $charge->efi_charge_id], 'Reconciliação diária detectou pagamento Pix não notificado via webhook');
                $metadata = json_decode((string) $charge->metadata, true) ?: [];
                $metadata['e2e_id'] = $e2eId;
                EfiWhmcs\Ledger\ChargeRepository::updateMetadata((int) $charge->id, json_encode($metadata) ?: '{}');
                $charge->metadata = json_encode($metadata) ?: '{}';
                $eventId = EfiWhmcs\Ledger\EventRepository::tryClaim((int) $charge->id, 'reconcile:' . $e2eId, $charge->status, 'CONCLUIDA', $charge->efi_charge_id);
                if ($eventId !== null) {
                    $receivedAmountCents = (int) round(((float) $recebido['valor']) * 100);
                    $pixService = new EfiWhmcs\Pix\PixService($api, $gatewayParams, $module);
                    PaymentApplier::apply([
                        'invoiceId' => (int) $charge->invoice_id, 'transId' => $e2eId, 'amountCents' => $receivedAmountCents,
                        'module' => $module, 'gatewayParams' => $gatewayParams, 'eventId' => $eventId,
                        'chargeId' => (int) $charge->id, 'settledStatus' => 'CONCLUIDA',
                        'markPaid' => static function () use ($pixService, $charge, $receivedAmountCents): void {
                            // Status antes do desconto (o UpdateInvoice dispara UpdateInvoiceTotal).
                            EfiWhmcs\Ledger\ChargeRepository::updateStatus((int) $charge->id, 'CONCLUIDA');
                            $pixService->applyDiscountLineItem((int) $charge->invoice_id, $receivedAmountCents, $charge);
                        },
                    ]);
                }
            }
        } catch (\Throwable $e) {
            GatewayLog::error($module, $gatewayParams, 'Reconciliação diária falhou para txid ' . $charge->efi_charge_id, $e->getMessage());
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
                $eventId = EfiWhmcs\PixAutomatic\Repository::tryClaimEvent('charge', (int) $charge->id, $e2eId, $charge->status, $status);
                if ($eventId === null) { continue; }
                PaymentApplier::apply([
                    'invoiceId' => (int) $charge->invoice_id, 'transId' => $e2eId, 'amountCents' => isset($settled['valor']) ? (int) round(((float) $settled['valor']) * 100) : (int) $charge->amount_cents,
                    'module' => PixAutomaticService::GATEWAY, 'gatewayParams' => $gatewayParams,
                    'markSettled' => static function () use ($charge, $status, $e2eId): void { EfiWhmcs\PixAutomatic\Repository::updateCharge((int) $charge->id, ['status' => $status, 'metadata' => json_encode(['e2e_id' => $e2eId]) ?: '{}']); },
                    'markApplied' => static function () use ($eventId): void { EfiWhmcs\PixAutomatic\Repository::markEventApplied($eventId); },
                    'markFailed' => static function () use ($eventId): void { EfiWhmcs\PixAutomatic\Repository::markEventFailed($eventId); },
                ]);
                logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $charge->invoice_id, 'txid' => $charge->efi_charge_id, 'e2eId' => $e2eId], 'Reconciliação diária detectou cobrança Pix Automático concluída');
            } elseif ($status !== '' && $status !== $charge->status) {
                EfiWhmcs\PixAutomatic\Repository::updateCharge((int) $charge->id, ['status' => $status]);
            }
        } catch (\Throwable $e) {
            GatewayLog::error(PixAutomaticService::GATEWAY, $gatewayParams, 'Reconciliação diária falhou para cobrança Pix Automático ' . $charge->efi_charge_id, $e->getMessage());
        }
    }
}

/**
 * Rede de segurança da AUTORIZAÇÃO do Pix Automático e do Pix inicial: sem ela, se o webhook
 * `/rec` ou o webhook Pix do pagamento inicial se perdessem (proxy, firewall, mTLS), a
 * autorização ficava 'CRIADA' e a fatura inicial em aberto para sempre.
 */
function efi_reconcile_pix_automatico_authorizations(array $gatewayParams): void
{
    try {
        $api = EfiClientFactory::make($gatewayParams, true);
        $processor = new EfiWhmcs\PixAutomatic\NotificationProcessor($api, $gatewayParams);

        // Sem idRec no corpo, recurrenceTransitions() consulta todas as autorizações pendentes.
        foreach ($processor->recurrenceTransitions('{}') as $recurrence) {
            logTransaction(PixAutomaticService::GATEWAY, ['idRec' => $recurrence->efi_recurrence_id, 'status' => $recurrence->status], 'Reconciliação diária de autorização Pix Automático: ' . $recurrence->status);
        }

        // Pix inicial: autorizações cuja fatura inicial continua em aberto.
        $openInitial = Capsule::table('mod_efi_pix_auto_recurrences as r')
            ->join('tblinvoices as i', 'i.id', '=', 'r.initial_invoice_id')
            ->where('i.status', 'Unpaid')->whereNotNull('r.initial_txid')->whereNotNull('r.efi_recurrence_id')
            ->where('r.updated_at', '<', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->get(['r.initial_txid']);

        foreach ($openInitial as $row) {
            try {
                $detail = $api->pixDetailCharge(['txid' => $row->initial_txid]);
            } catch (\Throwable $e) {
                continue; // ainda não paga / txid desconhecido: nada a reconciliar hoje.
            }
            if (($detail['status'] ?? '') !== 'CONCLUIDA' || empty($detail['pix'][0]['endToEndId'])) {
                continue;
            }

            // Reaproveita exatamente o caminho do webhook (consulta autoritativa + evento).
            $body = json_encode(['pix' => [['txid' => $row->initial_txid, 'endToEndId' => $detail['pix'][0]['endToEndId']]]]) ?: '{}';
            foreach ($processor->initialPaymentTransitions($body) as $transition) {
                PaymentApplier::apply([
                    'invoiceId' => (int) $transition['recurrence']->initial_invoice_id, 'transId' => $transition['e2eId'],
                    'amountCents' => (int) $transition['amountCents'], 'module' => PixAutomaticService::GATEWAY,
                    'gatewayParams' => $gatewayParams,
                    'markApplied' => static function () use ($transition): void { EfiWhmcs\PixAutomatic\Repository::markEventApplied((int) $transition['eventId']); },
                    'markFailed' => static function () use ($transition): void { EfiWhmcs\PixAutomatic\Repository::markEventFailed((int) $transition['eventId']); },
                ]);
                logTransaction(PixAutomaticService::GATEWAY, ['invoice_id' => $transition['recurrence']->initial_invoice_id, 'e2eId' => $transition['e2eId']], 'Reconciliação diária detectou pagamento inicial do Pix Automático');
            }
        }
    } catch (\Throwable $e) {
        GatewayLog::error(PixAutomaticService::GATEWAY, $gatewayParams, 'Reconciliação diária de autorizações Pix Automático falhou', $e->getMessage());
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
        var csrfToken = "' . addslashes(\EfiWhmcs\Support\AdminCsrf::token()) . '";

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
                        body: "module=" + moduleName + "&csrf=" + encodeURIComponent(csrfToken)
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
        addTestButton("efi_pix_automatico");
    })();
    </script>';
});

/**
 * Expõe campos de merge de boleto/Pix para uso manual nos modelos de email (Fatura Criada, Lembretes de
 * Pagamento, etc.) -- ver README para instruções de uso no template.
 */
add_hook('EmailPreSend', 1, function ($vars) {
    $relId = (int) ($vars['relid'] ?? 0);

    if ($relId <= 0 || !isset($vars['messagename'])
        || !Capsule::table('tblinvoices')->where('id', $relId)->exists()) {
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

    if ($pix !== null && $pix->status === 'ATIVA') {
        $metadata = json_decode((string) $pix->metadata, true) ?: [];
        $expiresAt = isset($metadata['expires_at']) ? (int) $metadata['expires_at'] : null;
        if ($expiresAt === null || $expiresAt > time()) {
            $pixParams = efi_gateway_variables('efi_pix') ?? [];
            $systemUrl = rtrim((string) ($vars['systemurl'] ?? $pixParams['systemurl'] ?? ''), '/');
            $merge['efi_pix_qrcode'] = $metadata['qr_image'] ?? '';
            $merge['efi_pix_copiaecola'] = $metadata['copy_paste'] ?? '';
            $merge['efi_pix_link'] = $systemUrl !== '' ? $systemUrl . '/viewinvoice.php?id=' . $relId : '';
        }
    }

    return $merge;
});
