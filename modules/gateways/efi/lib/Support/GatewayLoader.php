<?php

namespace EfiWhmcs\Support;

use WHMCS\Database\Capsule;

/**
 * `getGatewayVariables()` do WHMCS lança `WHMCS\Exception\Fatal` com a mensagem
 * "Gateway Module "X" Not Activated" quando o módulo nunca foi salvo em
 * Configurações > Pagamentos > Gateways de Pagamento (nenhuma linha em `tblpaymentgateways`).
 *
 * Os quatro gateways Efí compartilham hooks e callbacks: `InvoiceCancelled`,
 * `UpdateInvoiceTotal` e `AdminInvoicesControlsOutput` olham até dois gateways no mesmo disparo,
 * e o callback de Pix (`callback/efi_pix.php`) sempre consulta `efi_pix` e `efi_pix_automatico`
 * juntos (uma chave Pix só aceita uma URL de webhook). Se QUALQUER um desses gateways nunca foi
 * configurado, a chamada direta derruba o hook/callback inteiro com um fatal error antes mesmo
 * de chegar ao `if (!$params['type'])` que o código já tinha -- esse `if` nunca é alcançado.
 *
 * `variables()` confere a existência da configuração ANTES de chamar a função do WHMCS, então a
 * exceção nunca chega a ser lançada; retorna `null` para "não configurado/inativo" em vez de um
 * array com `type` vazio, para que o chamador só precise de `if ($params !== null)`.
 */
final class GatewayLoader
{
    public static function variables(string $module): ?array
    {
        if (!self::isConfigured($module)) {
            return null;
        }

        if (!function_exists('getGatewayVariables')) {
            if (class_exists('App')) {
                \App::load_function('gateway');
            }
        }

        if (!function_exists('getGatewayVariables')) {
            return null;
        }

        try {
            $params = getGatewayVariables($module);
        } catch (\Throwable $e) {
            return null;
        }

        return !empty($params['type']) ? $params : null;
    }

    private static function isConfigured(string $module): bool
    {
        return Capsule::table('tblpaymentgateways')
            ->where('gateway', $module)
            ->where('setting', 'type')
            ->exists();
    }
}
