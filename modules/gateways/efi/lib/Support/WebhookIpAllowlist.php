<?php

namespace EfiWhmcs\Support;

/**
 * Checagem de IP de origem para os webhooks Pix, usada SOMENTE quando `skip_mtls_webhook` está
 * ativo em algum dos gateways do disparo. Com mTLS intacto, o certificado cliente já prova que a
 * chamada veio da Efí -- checar IP além disso é redundante e arrisca quebrar os webhooks de
 * verdade se a Efí rotacionar o IP sem aviso (por isso NÃO é um allowlist fixo/sempre ativo, e o
 * valor é configurável por gateway, com o IP documentado hoje como default).
 *
 * Ver "Lembrete IP atualmente utilizado nas comunicações" em
 * https://dev.efipay.com.br/docs/api-pix/webhooks.
 */
final class WebhookIpAllowlist
{
    public const DEFAULT_IPS = '34.193.116.226';

    /**
     * @param array ...$gatewayParamsList Configs de todos os gateways relevantes para este
     *     disparo de webhook (ex.: efi_pix e efi_pix_automatico, que compartilham o endpoint).
     */
    public static function allows(string $remoteAddr, array ...$gatewayParamsList): bool
    {
        $anyEnforced = false;

        foreach ($gatewayParamsList as $params) {
            if (empty($params['skip_mtls_webhook'])) {
                continue;
            }

            $anyEnforced = true;
            $allowedIps = array_filter(array_map(
                'trim',
                explode(',', (string) ($params['efi_webhook_allowed_ips'] ?? self::DEFAULT_IPS))
            ));

            if (in_array($remoteAddr, $allowedIps, true)) {
                return true;
            }
        }

        return !$anyEnforced;
    }
}
