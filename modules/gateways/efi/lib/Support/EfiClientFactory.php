<?php

namespace EfiWhmcs\Support;

use Efi\EfiPay;

/**
 * Monta uma instância `Efi\EfiPay` a partir dos parâmetros de configuração de um dos módulos
 * de gateway (efi_boleto, efi_pix, efi_cartao). Único ponto que sabe traduzir nossos nomes de
 * campo de configuração para as opções esperadas pelo SDK.
 *
 * Cobranças (boleto/cartão) NÃO exigem certificado mTLS. Pix exige. Ver README do SDK:
 * "Com exceção da API Cobranças, é obrigatório informar... certificate".
 */
final class EfiClientFactory
{
    public static function make(array $gatewayParams, bool $requiresCertificate = false): EfiPay
    {
        $sandbox = self::isSandbox($gatewayParams);

        $options = [
            'clientId' => trim((string) ($gatewayParams['client_id'] ?? '')),
            'clientSecret' => trim((string) ($gatewayParams['client_secret'] ?? '')),
            'sandbox' => $sandbox,
            // O SDK imprime o trace cURL no corpo da resposta quando debug=true.
            // Endpoints AJAX devem sempre devolver JSON válido.
            'debug' => false,
            'cache' => true,
            'timeout' => 30,
        ];

        if ($options['clientId'] === '' || $options['clientSecret'] === '') {
            throw new \RuntimeException(
                'Client ID/Client Secret da Efí não configurados para este gateway.'
            );
        }

        if ($requiresCertificate) {
            $certPath = trim((string) ($gatewayParams['pix_cert_path'] ?? ''));

            if ($certPath === '' || !is_readable($certPath)) {
                throw new \RuntimeException(
                    'Certificado Efí (.p12/.pem) não encontrado ou sem permissão de leitura em: ' .
                    ($certPath !== '' ? $certPath : '(não configurado)')
                );
            }

            $options['certificate'] = $certPath;

            if (!empty($gatewayParams['pix_cert_password'])) {
                $options['pwdCertificate'] = $gatewayParams['pix_cert_password'];
            }
        }

        return new EfiPay($options);
    }

    public static function isSandbox(array $gatewayParams): bool
    {
        return ($gatewayParams['environment'] ?? 'sandbox') === 'sandbox';
    }

    public static function debugLevel(array $gatewayParams): string
    {
        $level = $gatewayParams['debug_level'] ?? 'errors';

        return in_array($level, ['none', 'errors', 'full'], true) ? $level : 'errors';
    }
}
