<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Service\Connection;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\Service\Connection\ConnectionTestService;
use EbizChargeShopware\ValueObject\PluginConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class ConnectionTestServiceTest extends TestCase
{
    public function testReturnsSuccessfulResultWhenProviderReturnsSettingsPayload(): void
    {
        $providerClient = new class implements ProviderClientInterface {
            public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
            {
                return [
                    'statusCode' => 200,
                    'body' => [
                        'getMerchantTransactionDataResponse' => [
                            'getMerchantTransactionDataResult' => [
                                'merchantName' => 'demo',
                            ],
                        ],
                    ],
                    'rawBody' => '{}',
                ];
            }
        };

        $service = new ConnectionTestService(
            $providerClient,
            new ResponseNormalizer(),
            new ConnectionHealthRegistry(new InMemorySystemConfigService()),
            new NullLogger()
        );

        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');
        $result = $service->test($config);

        self::assertTrue($result->success);
        self::assertSame(200, $result->statusCode);
        self::assertSame('sandbox', $result->environment);
    }

    public function testRejectsUnexpectedNonEmptyJson(): void
    {
        $providerClient = new class implements ProviderClientInterface {
            public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
            {
                return ['statusCode' => 200, 'body' => ['ok' => true], 'rawBody' => '{}'];
            }
        };

        $service = new ConnectionTestService(
            $providerClient,
            new ResponseNormalizer(),
            new ConnectionHealthRegistry(new InMemorySystemConfigService()),
            new NullLogger()
        );

        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');
        $result = $service->test($config);

        self::assertFalse($result->success);
        self::assertSame('provider_response', $result->failureCategory);
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
