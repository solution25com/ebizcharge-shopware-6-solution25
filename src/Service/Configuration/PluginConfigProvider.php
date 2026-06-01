<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Configuration;

use EbizChargeShopware\ValueObject\PluginConfig;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class PluginConfigProvider
{
    public const DOMAIN = 'EbizChargeShopware.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function get(?string $salesChannelId = null): PluginConfig
    {
        $environment = (string) ($this->systemConfigService->get(self::DOMAIN . 'environmentMode', $salesChannelId) ?? 'sandbox');
        $prefix = $environment === 'production' ? 'production' : 'sandbox';

        return new PluginConfig(
            $environment,
            (string) $this->systemConfigService->get(self::DOMAIN . $prefix . 'BaseUrl', $salesChannelId),
            (string) $this->systemConfigService->get(self::DOMAIN . $prefix . 'SecurityId', $salesChannelId),
            (string) $this->systemConfigService->get(self::DOMAIN . $prefix . 'UserId', $salesChannelId),
            (string) $this->systemConfigService->get(self::DOMAIN . $prefix . 'Password', $salesChannelId),
            (string) $this->systemConfigService->get(self::DOMAIN . $prefix . 'SubscriptionKey', $salesChannelId),
            (string) $this->systemConfigService->get(self::DOMAIN . 'shipFromZip', $salesChannelId),
            (string) ($this->systemConfigService->get(self::DOMAIN . 'processingCommand', $salesChannelId) ?? 'Sale'),
            (int) ($this->systemConfigService->get(self::DOMAIN . 'verificationLookbackDays', $salesChannelId) ?? 7),
            (int) ($this->systemConfigService->get(self::DOMAIN . 'connectionTimeoutSeconds', $salesChannelId) ?? 20),
            (int) ($this->systemConfigService->get(self::DOMAIN . 'retryCount', $salesChannelId) ?? 1),
            (string) ($this->systemConfigService->get(self::DOMAIN . 'descriptionTemplate', $salesChannelId) ?? 'Order {{ orderNumber }}'),
            (bool) ($this->systemConfigService->get(self::DOMAIN . 'enforceAvsCheck', $salesChannelId) ?? false),
            (string) ($this->systemConfigService->get(self::DOMAIN . 'webhookBasicUsername', $salesChannelId) ?? ''),
            (string) ($this->systemConfigService->get(self::DOMAIN . 'webhookBasicPassword', $salesChannelId) ?? ''),
            (string) ($this->systemConfigService->get(self::DOMAIN . 'webhookSignatureKey', $salesChannelId) ?? '')
        );
    }
}
