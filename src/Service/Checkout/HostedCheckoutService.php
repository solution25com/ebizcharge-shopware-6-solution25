<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\GetEbizWebFormUrlRequestBuilder;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\ProviderCustomerSyncService;
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
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly HostedWebformRequestCleanupService $requestCleanupService,
        private readonly ProviderCustomerSyncService $providerCustomerSyncService
    ) {
    }

    public function start(
        CheckoutOrderData $orderData,
        PluginConfig $config,
        string $shopwareReturnUrl,
        Context $context,
        ?bool $savePaymentMethod = null,
        ?bool $showSavedPaymentMethods = null,
        string $formType = ProviderContract::WEBFORM_TYPE,
        string $payByType = ProviderContract::PAY_BY_TYPE_CREDIT_CARD_AND_ACH
    ): HostedCheckoutRedirect {
        $this->providerCustomerSyncService->syncFromOrder($orderData, $config);

        $payload = $this->requestBuilder->build(
            $orderData,
            $config,
            $shopwareReturnUrl,
            $savePaymentMethod,
            $showSavedPaymentMethods,
            $formType,
            $payByType
        );
        $previousRecord = $this->transactionRecordStore->find($orderData->orderTransactionId, $context);
        $previousPaymentInternalId = $previousRecord['active_payment_internal_id'] ?? null;
        $response = $this->providerClient->send(ProviderOperation::GET_WEBFORM_URL, $payload, $config);
        $redirectUrl = $this->responseNormalizer->extractHostedRedirectUrl($response['body']);
        $paymentInternalId = $this->paymentInternalIdFromUrl($redirectUrl);

        if (
            $previousPaymentInternalId !== null
            && !hash_equals((string) $previousPaymentInternalId, (string) $paymentInternalId)
        ) {
            $this->requestCleanupService->delete((string) $previousPaymentInternalId, $config, [
                'orderTransactionId' => $orderData->orderTransactionId,
                'replacementPaymentInternalId' => $paymentInternalId,
                'formType' => $formType,
                'reason' => 'hosted_webform_replaced',
            ]);
        }

        $this->transactionRecordStore->upsert($orderData->orderTransactionId, [
            'order_id' => $orderData->orderId,
            'sales_channel_id' => $orderData->salesChannelId,
            'order_number' => $orderData->orderNumber,
            'lookup_key' => $orderData->orderTransactionId,
            'mode' => $config->processingCommand(),
            'normalized_state' => 'in_progress',
            'active_payment_internal_id' => $paymentInternalId,
            'amount_total' => $orderData->amountDue,
            'currency_iso' => $orderData->currencyIso,
            'last_support_message' => 'Hosted checkout URL created.',
            'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], $context);

        return new HostedCheckoutRedirect($redirectUrl, $orderData->orderTransactionId, $config->processingCommand());
    }

    private function paymentInternalIdFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!\is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $pid = $params['pid'] ?? null;
        if (!\is_scalar($pid)) {
            return null;
        }

        $pid = trim((string) $pid);

        return $pid !== '' ? $pid : null;
    }
}
