<?php

namespace EfiWhmcs\Support;

/**
 * Cifra/decifra o payment_token reutilizável da Efí antes de ele ser entregue ao WHMCS
 * como `gatewayid` (armazenamento de método de pagamento tokenizado).
 *
 * Defesa em profundidade: o WHMCS já trata `password`/perfil de pagamento como dado sensível,
 * mas aqui aplicamos uma segunda camada, com uma chave que só existe na configuração do
 * gateway (campo `password`, portanto já protegido pelo próprio mecanismo de configuração do
 * WHMCS) — nunca gravada em nenhuma tabela própria do módulo.
 *
 * Formato do texto cifrado (sempre string ASCII-safe, seguro para uma coluna de gatewayid):
 *   base64("v1:" . nonce(12 bytes) . tag(16 bytes) . ciphertext)
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION_PREFIX = 'v1:';

    /**
     * Deriva uma chave de 32 bytes a partir do segredo configurado pelo administrador,
     * independentemente do tamanho/formato que ele tenha digitado.
     */
    public static function deriveKey(string $configuredSecret): string
    {
        if (trim($configuredSecret) === '') {
            throw new \InvalidArgumentException(
                'card_encryption_key não configurada. Preencha o campo "Chave de Cifragem de Cartão" ' .
                'nas configurações do gateway Efí - Cartão de Crédito antes de salvar cartões.'
            );
        }

        return hash('sha256', $configuredSecret, true);
    }

    public static function encrypt(string $plaintext, string $configuredSecret): string
    {
        $key = self::deriveKey($configuredSecret);
        $nonce = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Falha ao cifrar payment_token do cartão.');
        }

        return base64_encode(self::VERSION_PREFIX . $nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded, string $configuredSecret): string
    {
        $key = self::deriveKey($configuredSecret);
        $raw = base64_decode($encoded, true);

        if ($raw === false || strncmp($raw, self::VERSION_PREFIX, strlen(self::VERSION_PREFIX)) !== 0) {
            throw new \RuntimeException('Token de cartão armazenado em formato inválido/corrompido.');
        }

        $raw = substr($raw, strlen(self::VERSION_PREFIX));
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Não foi possível decifrar o token de cartão armazenado. A chave de cifragem foi ' .
                'alterada após o cartão ser salvo? Nesse caso o cliente precisa recadastrar o cartão.'
            );
        }

        return $plaintext;
    }

    /**
     * Gera um segredo aleatório forte, para o admin usar como valor inicial do campo de
     * configuração (sugerido na descrição do campo, nunca gerado/gravado automaticamente
     * pelo módulo).
     */
    public static function suggestSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
