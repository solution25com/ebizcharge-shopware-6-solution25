<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Request;

use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\PluginConfig;

final class GetEbizWebFormUrlRequestBuilder
{
    public function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CheckoutOrderData $orderData, PluginConfig $config, string $shopwareReturnUrl, ?bool $savePaymentMethod = null, ?bool $showSavedPaymentMethods = null, string $formType = ProviderContract::WEBFORM_TYPE, string $payByType = ProviderContract::PAY_BY_TYPE_CREDIT_CARD_AND_ACH): array
    {
        $customerId = $orderData->guest
            ? 'guest-' . $orderData->orderId
            : ($orderData->customerId ?: $orderData->customerNumber ?: $orderData->orderId);

        $country = strtoupper(trim($orderData->billingAddress->country ?? 'US')) ?: 'US';
        $companyName = trim((string) $orderData->billingAddress->companyName) ?: (trim($orderData->customerFullName) ?: 'n/a');

        return [
            'ePaymentForm' => [
                'formType' => $formType,
                'processingCommand' => $config->processingCommand(),
                'customerId' => $customerId,
                'custFullName' => $orderData->customerFullName,
                'companyName' => $companyName,
                'poNum' => $orderData->orderNumber,
                'orderId' => $orderData->orderId,
                'invoiceNumber' => $orderData->orderNumber,
                'payByType' => $payByType,
                'date' => $orderData->orderDate->format('Y-m-d'),
                'dueDate' => $orderData->orderDate->format('Y-m-d'),
                'totalAmount' => $this->normalizeAmount($orderData->totalAmount),
                'amountDue' => $this->normalizeAmount($orderData->amountDue),
                'tipAmount' => $this->normalizeAmount($orderData->tipAmount),
                'shippingAmount' => $this->normalizeAmount($orderData->shippingAmount),
                'dutyAmount' => $this->normalizeAmount($orderData->dutyAmount),
                'taxAmount' => $this->normalizeAmount($orderData->taxAmount),
                'description' => $config->descriptionForOrder($orderData->orderNumber),
                'lineItems' => array_map(
                    static fn ($item): array => $item->toProviderArray(),
                    $orderData->lineItems
                ),
                'showSavedPaymentMethods' => $showSavedPaymentMethods ?? false,
                'savePaymentMethod' => $savePaymentMethod ?? false,
                'displayDefaultResultPage' => 0,
                'approvedURL' => $shopwareReturnUrl,
                'declinedURL' => $shopwareReturnUrl,
                'errorURL' => $shopwareReturnUrl,
                'transactionLookupKey' => $orderData->orderTransactionId,
                'billingAddress' => $orderData->billingAddress->toProviderArray(),
                'shippingAddress' => $orderData->shippingAddress?->toProviderArray(),
                'clerk' => 'Shopware 6.7',
                'shipFromZip' => $config->shipFromZip(),
                'currencyCode' => $orderData->currencyIso,
                'countryCode' => $country,
            ],
        ];
    }

    private function normalizeAmount(float $amount): float
    {
        return round($amount, 2);
    }
}
