<?php

namespace EfiWhmcs\Support;

/**
 * Wrapper fino sobre `logTransaction()` do WHMCS: respeita o nível de log configurado
 * (nenhum/erro/completo) e redige campos sensíveis antes de gravar.
 */
final class GatewayLog
{
    private const REDACT_KEYS = [
        'client_secret', 'clientsecret', 'cvv', 'cccvv', 'cardnum', 'payment_token',
        'gatewayid', 'card_encryption_key', 'pix_cert_password', 'webhook_secret',
    ];

    public static function error(string $moduleName, array $gatewayParams, string $summary, $data = ''): void
    {
        if (EfiClientFactory::debugLevel($gatewayParams) === 'none') {
            return;
        }

        self::write($moduleName, $summary, $data);
    }

    public static function debug(string $moduleName, array $gatewayParams, string $summary, $data = ''): void
    {
        if (EfiClientFactory::debugLevel($gatewayParams) !== 'full') {
            return;
        }

        self::write($moduleName, $summary, $data);
    }

    private static function write(string $moduleName, string $summary, $data): void
    {
        if (!function_exists('logTransaction')) {
            return;
        }

        logTransaction($moduleName, self::redact($data), $summary);
    }

    private static function redact($data)
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $out[$key] = is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)
                    ? '***redacted***'
                    : self::redact($value);
            }

            return $out;
        }

        return $data;
    }
}
