<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Support\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoVersioningTest extends TestCase
{
    public function testV2RoundTripsCorrectly(): void
    {
        $encoded = Crypto::encrypt('payment-token-value', 'super-secret');

        self::assertStringStartsWith('djI6', $encoded); // base64("v2:...")
        self::assertSame('payment-token-value', Crypto::decrypt($encoded, 'super-secret'));
    }

    public function testLegacyV1TokenStillDecrypts(): void
    {
        // Monta manualmente um token v1 (sem sal), formato que o módulo gravou antes deste
        // rock -- precisa continuar legível para não obrigar recadastro de cartões salvos.
        $secret = 'super-secret';
        $key = hash('sha256', $secret, true);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt('legacy-token', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        $v1Token = base64_encode('v1:' . $nonce . $tag . $ciphertext);

        self::assertSame('legacy-token', Crypto::decrypt($v1Token, $secret));
    }

    public function testNewTokensAreNeverWrittenInTheLegacyFormat(): void
    {
        $encoded = base64_decode(Crypto::encrypt('x', 'secret'), true);
        self::assertStringStartsWith('v2:', (string) $encoded);
    }

    public function testTruncatedTagIsRejected(): void
    {
        $secret = 'super-secret';
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $secret, $salt, 100000, 32, true);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt('x', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        $truncatedTag = substr($tag, 0, 8); // tag adulterada/truncada
        $corrupted = base64_encode('v2:' . $salt . $nonce . $truncatedTag . $ciphertext);

        $this->expectException(\RuntimeException::class);
        Crypto::decrypt($corrupted, $secret);
    }
}
