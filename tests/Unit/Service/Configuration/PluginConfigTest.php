<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Service\Configuration;

use EbizChargeShopware\Exception\ConfigurationException;
use EbizChargeShopware\ValueObject\PluginConfig;
use PHPUnit\Framework\TestCase;

final class PluginConfigTest extends TestCase
{
    public function testHasCompleteCredentialsReturnsTrueForValidConfiguration(): void
    {
        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');

        self::assertTrue($config->hasCompleteCredentials());
        self::assertSame(1, $config->retryCount());
        self::assertNotSame('', $config->credentialFingerprint());
    }

    public function testAssertCompleteThrowsForMissingConfiguration(): void
    {
        $this->expectException(ConfigurationException::class);

        $config = new PluginConfig('sandbox', '', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');
        $config->assertComplete();
    }

    public function testCredentialFingerprintChangesWhenPasswordChanges(): void
    {
        $base = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');
        $changed = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'changed', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');

        self::assertNotSame($base->credentialFingerprint(), $changed->credentialFingerprint());
    }
}
