<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Connection;

use EbizChargeShopware\Exception\ConfigurationException;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\ValueObject\ConnectionTestResult;
use EbizChargeShopware\ValueObject\PluginConfig;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class ConnectionHealthRegistry
{
    private const LAST_TEST_SUCCESS = PluginConfigProvider::DOMAIN . 'lastConnectionTestSuccess';
    private const LAST_TEST_AT = PluginConfigProvider::DOMAIN . 'lastConnectionTestAt';
    private const LAST_TEST_FINGERPRINT = PluginConfigProvider::DOMAIN . 'lastConnectionTestFingerprint';
    private const LAST_TEST_ENVIRONMENT = PluginConfigProvider::DOMAIN . 'lastConnectionTestEnvironment';
    private const LAST_TEST_ENDPOINT = PluginConfigProvider::DOMAIN . 'lastConnectionTestEndpoint';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function markResult(ConnectionTestResult $result, PluginConfig $config, ?string $salesChannelId = null): void
    {
        $this->systemConfigService->set(self::LAST_TEST_SUCCESS, $result->success, $salesChannelId);
        $this->systemConfigService->set(self::LAST_TEST_AT, $result->testedAt, $salesChannelId);
        $this->systemConfigService->set(self::LAST_TEST_FINGERPRINT, $config->credentialFingerprint(), $salesChannelId);
        $this->systemConfigService->set(self::LAST_TEST_ENVIRONMENT, $config->environmentMode(), $salesChannelId);
        $this->systemConfigService->set(self::LAST_TEST_ENDPOINT, $config->baseUrl(), $salesChannelId);
    }

    public function hasSuccessfulTest(PluginConfig $config, ?string $salesChannelId = null): bool
    {
        $success = (bool) ($this->systemConfigService->get(self::LAST_TEST_SUCCESS, $salesChannelId) ?? false);
        $fingerprint = (string) ($this->systemConfigService->get(self::LAST_TEST_FINGERPRINT, $salesChannelId) ?? '');
        $environment = (string) ($this->systemConfigService->get(self::LAST_TEST_ENVIRONMENT, $salesChannelId) ?? '');

        return $success
            && hash_equals($config->credentialFingerprint(), $fingerprint)
            && hash_equals($config->environmentMode(), $environment);
    }

    public function requireSuccessfulTest(PluginConfig $config, ?string $salesChannelId = null): void
    {
        if ($this->hasSuccessfulTest($config, $salesChannelId)) {
            return;
        }

        throw ConfigurationException::connectionTestRequired();
    }
}
