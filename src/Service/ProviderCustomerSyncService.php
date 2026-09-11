<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service;

use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\PluginConfig;

final class ProviderCustomerSyncService
{
    public function __construct(
        private readonly ProviderClientInterface $providerClient,
        private readonly ResponseNormalizer $responseNormalizer
    ) {
    }

    public function syncFromOrder(CheckoutOrderData $orderData, PluginConfig $config): void
    {
        if ($orderData->guest || $orderData->customerId === null || trim($orderData->customerId) === '') {
            return;
        }

        $customerId = $orderData->customerId;
        if ($this->customerExists($customerId, $config)) {
            return;
        }

        $response = $this->providerClient->send(
            ProviderOperation::ADD_CUSTOMER,
            ['customer' => $this->buildCustomerPayload($orderData, $customerId)],
            $config
        );

        $customerInternalId = $this->responseNormalizer->findCustomerInternalId($response['body']);
        if ($customerInternalId === null || $customerInternalId === '') {
            throw ProviderCommunicationException::requestFailed('AddCustomer', 'Missing CustomerInternalId.');
        }
    }

    private function customerExists(string $customerId, PluginConfig $config): bool
    {
        $response = $this->providerClient->send(
            ProviderOperation::SEARCH_CUSTOMERS,
            [
                'customerInternalId' => '',
                'customerId' => $customerId,
                'start' => 0,
                'limit' => 10,
                'sort' => '',
            ],
            $config
        );

        foreach ($this->responseNormalizer->extractSearchCustomers($response['body']) as $customer) {
            if ($this->customerInternalId($customer) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerPayload(CheckoutOrderData $orderData, string $customerId): array
    {
        [$firstName, $lastName] = $this->customerName($orderData);

        return array_filter([
            'customerId' => $customerId,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'companyName' => $orderData->billingAddress->companyName,
            'email' => $orderData->customerEmail,
            'billingAddress' => $this->addressPayload($orderData->billingAddress),
            'shippingAddress' => $orderData->shippingAddress !== null
                ? $this->addressPayload($orderData->shippingAddress)
                : null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function customerName(CheckoutOrderData $orderData): array
    {
        $firstName = trim($orderData->billingAddress->firstName);
        $lastName = trim($orderData->billingAddress->lastName);

        if ($firstName !== '' || $lastName !== '') {
            return [$firstName, $lastName];
        }

        $parts = preg_split('/\s+/', trim($orderData->customerFullName), 2) ?: [];

        return [trim((string) ($parts[0] ?? '')), trim((string) ($parts[1] ?? ''))];
    }

    /**
     * @return array<string, string>
     */
    private function addressPayload(AddressData $address): array
    {
        return array_filter($address->toProviderArray(), static fn ($value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function customerInternalId(array $customer): ?string
    {
        $value = $customer['CustomerInternalId'] ?? $customer['customerInternalId'] ?? null;

        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
