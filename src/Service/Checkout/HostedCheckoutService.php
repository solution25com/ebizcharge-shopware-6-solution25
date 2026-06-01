<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\GetEbizWebFormUrlRequestBuilder;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\ValueObject\HostedCheckoutRedirect;
use EbizChargeShopware\ValueObject\PluginConfig;
use Shopware\Core\Framework\Context;

final class HostedCheckoutService
{
    public function __construct(
        private readonly GetEbizWebFormUrlRequestBuilder $requestBuilder,
        private readonly ProviderClientInterface $providerClient,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly TransactionRecordStoreInterface $transactionRecordStore
    ) {
    }

    public function start(CheckoutOrderData $orderData, PluginConfig $config, string $shopwareReturnUrl, Context $context, ?bool $savePaymentMethod = null, ?bool $showSavedPaymentMethods = null, string $formType = ProviderContract::WEBFORM_TYPE): HostedCheckoutRedirect
    {
        $payload = $this->requestBuilder->build($orderData, $config, $shopwareReturnUrl, $savePaymentMethod, $showSavedPaymentMethods, $formType);
        $response = $this->providerClient->send(ProviderOperation::GET_WEBFORM_URL, $payload, $config);
        $redirectUrl = $this->responseNormalizer->extractHostedRedirectUrl($response['body']);

        $this->transactionRecordStore->upsert($orderData->orderTransactionId, [
            'order_id' => $orderData->orderId,
            'order_number' => $orderData->orderNumber,
            'lookup_key' => $orderData->orderTransactionId,
            'mode' => $config->processingCommand(),
            'normalized_state' => 'in_progress',
            'amount_total' => $orderData->amountDue,
            'currency_iso' => $orderData->currencyIso,
            'last_support_message' => 'Hosted checkout URL created.',
            'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], $context);

        return new HostedCheckoutRedirect($redirectUrl, $orderData->orderTransactionId, $config->processingCommand());
    }
}
