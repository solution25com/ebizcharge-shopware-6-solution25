<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Provider\Client;

use EbizChargeShopware\Provider\Client\ProviderTransportInterface;
use EbizChargeShopware\Provider\Client\RestProviderClient;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\SecurityTokenPayloadFactory;
use EbizChargeShopware\ValueObject\PluginConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RestProviderClientTest extends TestCase
{
    public function testWrapsSecurityTokenAndSubscriptionHeader(): void
    {
        $transport = new class implements ProviderTransportInterface {
            public array $captured = [];

            public function send(string $url, array $headers, array $payload, int $timeoutSeconds): array
            {
                $this->captured = compact('url', 'headers', 'payload', 'timeoutSeconds');

                return ['statusCode' => 200, 'body' => ['settings' => ['ok' => true]], 'rawBody' => '{}'];
            }
        };

        $client = new RestProviderClient(
            $transport,
            new SecurityTokenPayloadFactory(),
            new NullLogger()
        );

        $config = new PluginConfig('sandbox', 'https://example.test', 'sid', 'uid', 'pwd', 'subkey', '92618', 'Sale', 7, 20, 1, 'Order {{ orderNumber }}');
        $client->send(ProviderOperation::CONNECTION_TEST, [], $config);

        self::assertSame('https://example.test/GetMerchantIntegrationSettings', $transport->captured['url']);
        self::assertSame('subkey', $transport->captured['headers']['EBizSubscription-Key']);
        self::assertSame('sid', $transport->captured['payload']['getMerchantIntegrationSettings']['securityToken']['securityId']);
    }
}
