<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Service\Configuration;

use EbizChargeShopware\Exception\ConfigurationException;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\ValueObject\ConnectionTestResult;
use EbizChargeShopware\ValueObject\PluginConfig;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class ConnectionHealthRegistryTest extends TestCase
{
    public function testRequiresSuccessfulMatchingConnectionTest(): void
    {
        $systemConfigService = new InMemorySystemConfigService();
        $registry = new ConnectionHealthRegistry($systemConfigService);
        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');

        $registry->markResult(new ConnectionTestResult(
            true,
            'Connection test passed.',
            'sandbox',
            'https://example.test',
            $config->credentialFingerprint(),
            '2026-04-11T00:00:00+00:00',
            null,
            200,
            10.0
        ), $config);

        self::assertTrue($registry->hasSuccessfulTest($config));
        $registry->requireSuccessfulTest($config);
    }

    public function testThrowsWhenNoSuccessfulConnectionTestExists(): void
    {
        $this->expectException(ConfigurationException::class);

        $registry = new ConnectionHealthRegistry(new InMemorySystemConfigService());
        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');

        $registry->requireSuccessfulTest($config);
    }

    public function testPasswordOnlyCredentialChangesInvalidatePriorSuccess(): void
    {
        $systemConfigService = new InMemorySystemConfigService();
        $registry = new ConnectionHealthRegistry($systemConfigService);
        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');

        $registry->markResult(new ConnectionTestResult(
            true,
            'Connection test passed.',
            'sandbox',
            'https://example.test',
            $config->credentialFingerprint(),
            '2026-04-11T00:00:00+00:00',
            null,
            200,
            10.0
        ), $config);

        self::assertFalse($registry->hasSuccessfulTest(
            new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'changed', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}')
        ));
    }
}

final class InMemorySystemConfigService extends SystemConfigService
{
    /**
     * @var array<string, array<mixed>|bool|float|int|string|null>
     */
    private array $values = [];

    public function __construct()
    {
    }

    public function get(string $key, ?string $salesChannelId = null): array|bool|float|int|string|null
    {
        return $this->values[$this->storageKey($key, $salesChannelId)] ?? null;
    }

    public function set(string $key, $value, ?string $salesChannelId = null): void
    {
        $this->values[$this->storageKey($key, $salesChannelId)] = $value;
    }

    public function delete(string $key, ?string $salesChannel = null): void
    {
        unset($this->values[$this->storageKey($key, $salesChannel)]);
    }

    private function storageKey(string $key, ?string $salesChannelId): string
    {
        return $key . '|' . (string) $salesChannelId;
    }
}
