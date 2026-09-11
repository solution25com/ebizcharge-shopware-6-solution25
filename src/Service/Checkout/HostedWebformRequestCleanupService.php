<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\ValueObject\PluginConfig;
use Psr\Log\LoggerInterface;

final class HostedWebformRequestCleanupService
{
    public function __construct(
        private readonly ProviderClientInterface $providerClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $logContext
     */
    public function delete(?string $paymentInternalId, PluginConfig $config, array $logContext = []): void
    {
        $paymentInternalId = trim((string) $paymentInternalId);
        if ($paymentInternalId === '') {
            return;
        }

        try {
            $this->providerClient->send(ProviderOperation::DELETE_WEBFORM_PAYMENT, [
                'paymentInternalId' => $paymentInternalId,
            ], $config);

            $this->logger->info('Deleted EBizCharge hosted webform request.', $logContext + [
                'paymentInternalId' => $paymentInternalId,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not delete EBizCharge hosted webform request.', $logContext + [
                'paymentInternalId' => $paymentInternalId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
