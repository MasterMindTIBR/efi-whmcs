<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Support\WebhookIpAllowlist;
use PHPUnit\Framework\TestCase;

final class WebhookIpAllowlistTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testNormalizesIpv4MappedIpv6BeforeComparison(): void
    {
        self::assertTrue(WebhookIpAllowlist::allows('::ffff:34.193.116.226', [
            'skip_mtls_webhook' => 'on', 'efi_webhook_allowed_ips' => '34.193.116.226',
        ]));
    }

    public function testTrustedProxyUsesTheFirstForwardedAddress(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '34.193.116.226, 10.0.0.3';
        self::assertTrue(WebhookIpAllowlist::allows('10.0.0.2', [
            'skip_mtls_webhook' => 'on',
            'efi_webhook_allowed_ips' => '34.193.116.226',
            'efi_webhook_trusted_proxies' => '10.0.0.2',
        ]));
    }

    public function testEmptyTrustedProxyListUsesRemoteAddrDirectly(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '34.193.116.226';
        self::assertFalse(WebhookIpAllowlist::allows('10.0.0.2', [
            'skip_mtls_webhook' => 'on', 'efi_webhook_allowed_ips' => '34.193.116.226',
            'efi_webhook_trusted_proxies' => '',
        ]));
    }

    public function testRejectedOriginHasAForbiddenResponseIndicator(): void
    {
        $allowed = WebhookIpAllowlist::allows('10.0.0.2', [
            'skip_mtls_webhook' => 'on', 'efi_webhook_allowed_ips' => '34.193.116.226',
        ]);

        self::assertFalse($allowed);
        self::assertSame(403, WebhookIpAllowlist::responseStatus($allowed));
    }
}
