<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\GetEbizWebFormUrlRequestBuilder;
use EbizChargeShopware\Provider\Request\ReturnUrlBuilder;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\ProviderCustomerSyncService;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\PluginConfig;

final class EmbeddedCheckoutService
{
    public function __construct(
        private readonly GetEbizWebFormUrlRequestBuilder $requestBuilder,
        private readonly ProviderClientInterface $providerClient,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly ReturnUrlBuilder $returnUrlBuilder,
        private readonly ProviderCustomerSyncService $providerCustomerSyncService
    ) {
    }

    public function createFormUrl(
        CheckoutOrderData $orderData,
        PluginConfig $config,
        string $returnBaseUrl,
        string $formType,
        string $payByType = ProviderContract::PAY_BY_TYPE_CREDIT_CARD_AND_ACH
    ): string {
        $this->providerCustomerSyncService->syncFromOrder($orderData, $config);

        $payload = $this->requestBuilder->build(
            $orderData,
            $config,
            $returnBaseUrl,
            false,
            false,
            $formType,
            $payByType,
            $this->returnUrlBuilder->withOutcome($returnBaseUrl, 'approved'),
            $this->returnUrlBuilder->withOutcome($returnBaseUrl, 'declined'),
            $this->returnUrlBuilder->withOutcome($returnBaseUrl, 'error')
        );

        $response = $this->providerClient->send(ProviderOperation::GET_WEBFORM_URL, $payload, $config);

        return $this->responseNormalizer->extractHostedRedirectUrl($response['body']);
    }
}
