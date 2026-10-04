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
        return new EfiPay(self::buildOptions($gatewayParams, $requiresCertificate));
    }

    /**
     * Igual a `make()` com certificado (Pix sempre exige), mas USADO SOMENTE para a chamada de
     * registro do webhook (`pixConfigWebhook`). A Efí documenta `x-skip-mtls-checking` como um
     * header específico dessa chamada -- nunca deve ir para as demais (cobrança, QR code, etc).
     * Por isso é uma instância `EfiPay` SEPARADA, com o header só nela: o SDK aplica
     * `config['headers']` a TODA chamada feita a partir da mesma instância (ver
     * `Efi\Request::applyCertificateAndHeaders`).
     */
    public static function makeForWebhookRegistration(array $gatewayParams): EfiPay
    {
        $options = self::buildOptions($gatewayParams, true);

        if (!empty($gatewayParams['skip_mtls_webhook'])) {
            $options['headers'] = ['x-skip-mtls-checking' => true];
        }

        return new EfiPay($options);
    }

    private static function buildOptions(array $gatewayParams, bool $requiresCertificate): array
    {
        self::redirectSdkCache();

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

        return $options;
    }

    /**
     * O SDK da Efí grava o cache de access token (criptografado) e o caminho resolvido do
     * certificado em `sys_get_temp_dir() . '/efi_cache'`, sem nenhuma opção nativa de
     * configuração para apontar para outro diretório (ver `Efi\FileCacheRetriever`). Em
     * hospedagem compartilhada isso cai no `/tmp` do sistema operacional, visível/gravável por
     * qualquer outro processo da máquina (outros sites, cron, etc.) -- mesmo com o conteúdo
     * cifrado, é superfície de ataque desnecessária.
     *
     * `putenv('TMPDIR=...')` NÃO é confiável aqui: `sys_get_temp_dir()` cacheia o resultado na
     * primeira chamada de todo o request (`BG(temp_directory)` do PHP) -- se qualquer código
     * carregado pelo `init.php` do WHMCS (Smarty, PHPMailer, etc.) chamar `sys_get_temp_dir()`
     * antes de chegarmos aqui, nosso `putenv` posterior seria ignorado silenciosamente pelo
     * resto do request. Por isso usamos `EFI_SDK_CACHE_DIR`, uma env própria lida via
     * `getenv()` (sem cache de engine) dentro de um patch local em `FileCacheRetriever` --
     * ver o comentário "PATCH LOCAL" naquele arquivo.
     */
    private static function redirectSdkCache(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $dir = __DIR__ . '/../../storage/sdk-cache';

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return; // sem diretório privado disponível; segue com o padrão do SDK
        }

        @chmod($dir, 0700);
        putenv('EFI_SDK_CACHE_DIR=' . (realpath($dir) ?: $dir));
        $done = true;
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
