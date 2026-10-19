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
 *   v1 (leitura apenas, nunca mais gravado): base64("v1:" . nonce(12) . tag(16) . ciphertext),
 *   chave = sha256(secret) sem sal -- mantido só para não invalidar cartões já salvos.
 *   v2 (formato atual, sempre gravado por encrypt()):
 *   base64("v2:" . salt(16) . nonce(12) . tag(16) . ciphertext), chave derivada com
 *   hash_pbkdf2('sha256', secret, salt, 100000, 32, true) -- sal por token e custo
 *   computacional real, em vez de um hash direto e sem sal do segredo configurado.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION_PREFIX_V1 = 'v1:';
    private const VERSION_PREFIX_V2 = 'v2:';
    private const PBKDF2_ITERATIONS = 100000;

    /**
     * Deriva uma chave de 32 bytes a partir do segredo configurado pelo administrador e, no
     * formato v2, de um salt aleatório por token -- independentemente do tamanho/formato que o
     * segredo tenha sido digitado.
     */
    public static function deriveKey(string $configuredSecret, ?string $salt = null): string
    {
        if (trim($configuredSecret) === '') {
            throw new \InvalidArgumentException(
                'card_encryption_key não configurada. Preencha o campo "Chave de Cifragem de Cartão" ' .
                'nas configurações do gateway Efí - Cartão de Crédito antes de salvar cartões.'
            );
        }

        if ($salt === null) {
            // Compatibilidade v1: sem sal, hash direto do segredo -- nunca usado por encrypt().
            return hash('sha256', $configuredSecret, true);
        }

        return hash_pbkdf2('sha256', $configuredSecret, $salt, self::PBKDF2_ITERATIONS, 32, true);
    }

    public static function encrypt(string $plaintext, string $configuredSecret): string
    {
        $salt = random_bytes(16);
        $key = self::deriveKey($configuredSecret, $salt);
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

        return base64_encode(self::VERSION_PREFIX_V2 . $salt . $nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded, string $configuredSecret): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw new \RuntimeException('Token de cartão armazenado em formato inválido/corrompido.');
        }

        if (strncmp($raw, self::VERSION_PREFIX_V2, strlen(self::VERSION_PREFIX_V2)) === 0) {
            $raw = substr($raw, strlen(self::VERSION_PREFIX_V2));
            $salt = substr($raw, 0, 16);
            $nonce = substr($raw, 16, 12);
            $tag = substr($raw, 28, 16);
            $ciphertext = substr($raw, 44);
            $key = self::deriveKey($configuredSecret, $salt);
        } elseif (strncmp($raw, self::VERSION_PREFIX_V1, strlen(self::VERSION_PREFIX_V1)) === 0) {
            $raw = substr($raw, strlen(self::VERSION_PREFIX_V1));
            $nonce = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ciphertext = substr($raw, 28);
            $key = self::deriveKey($configuredSecret);
        } else {
            throw new \RuntimeException('Token de cartão armazenado em formato inválido/corrompido.');
        }

        if (strlen($tag) !== 16) {
            throw new \RuntimeException('Token de cartão armazenado em formato inválido/corrompido (tag truncada).');
        }

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
