<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service;

use EbizChargeShopware\Core\Content\EbizchargeVaultedCustomer\EbizchargeVaultedCustomerEntity;
use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\PluginConfig;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class EbizChargeCustomerVaultService
{
    public function __construct(
        private readonly EntityRepository $vaultedCustomerRepository,
        private readonly EntityRepository $customerRepository,
        private readonly ProviderClientInterface $providerClient,
        private readonly PluginConfigProvider $configProvider,
        private readonly ResponseNormalizer $responseNormalizer
    ) {
    }

    public function ensureVault(SalesChannelContext $context): EbizchargeVaultedCustomerEntity
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            throw new \RuntimeException('Vault requires a registered customer.');
        }

        return $this->ensureVaultForCustomer($customer, $context->getSalesChannelId(), $context->getContext());
    }

    public function ensureVaultForCustomer(
        CustomerEntity $customer,
        string $salesChannelId,
        Context $context
    ): EbizchargeVaultedCustomerEntity {
        if ($customer->getGuest()) {
            throw new \RuntimeException('Vault requires a registered customer.');
        }

        $customerVault = $this->findVault($customer->getId(), $salesChannelId, $context);
        if ($customerVault !== null && $customerVault->getEbizCustomerToken() !== null && $customerVault->getEbizCustomerToken() !== '') {
            return $customerVault;
        }

        $config = $this->configProvider->get($salesChannelId);
        $config->assertComplete();

        $customerWithBilling = $this->loadCustomerWithBilling($customer->getId(), $context);
        $merchantCustomerId = $customer->getId();
        $customerInternalId = $customerVault?->getCustomerInternalId() ?? '';
        if ($customerInternalId === '') {
            $customerInternalId = $this->getOrCreateCustomerInternalId($config, $customerWithBilling);
        }

        $ebizCustomerToken = $this->fetchEbizCustomerToken($config, $merchantCustomerId, $customerInternalId);

        if ($customerVault !== null) {
            $this->vaultedCustomerRepository->update([
                [
                    'id' => $customerVault->getId(),
                    'customerInternalId' => $customerInternalId,
                    'ebizCustomerToken' => $ebizCustomerToken,
                ],
            ], $context);
            $customerVault->setCustomerInternalId($customerInternalId);
            $customerVault->setEbizCustomerToken($ebizCustomerToken);

            return $customerVault;
        }

        $customerVaultId = Uuid::randomHex();
        $this->vaultedCustomerRepository->create([
            [
                'id' => $customerVaultId,
                'customerId' => $customer->getId(),
                'salesChannelId' => $salesChannelId,
                'merchantCustomerId' => $merchantCustomerId,
                'customerInternalId' => $customerInternalId,
                'ebizCustomerToken' => $ebizCustomerToken,
            ],
        ], $context);

        $newCustomerVault = $this->findVault($customer->getId(), $salesChannelId, $context);
        if ($newCustomerVault === null) {
            throw new \RuntimeException('Vault persist failed.');
        }

        return $newCustomerVault;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCardsForDisplay(EbizchargeVaultedCustomerEntity $customerVault, Context $context): array
    {
        return $this->formatCardsForDisplay($this->fetchCards($customerVault, $context));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCards(EbizchargeVaultedCustomerEntity $customerVault, Context $context): array
    {
        $customerToken = (string) $customerVault->getEbizCustomerToken();
        if ($customerToken === '') {
            return [];
        }

        $config = $this->configProvider->get($customerVault->getSalesChannelId());
        $config->assertComplete();

        $response = $this->providerClient->send(
            ProviderOperation::GET_CUSTOMER_PAYMENT_METHOD_PROFILES,
            ['customerToken' => $customerToken],
            $config
        );

        $profiles = $this->responseNormalizer->collectPaymentMethodProfiles($response['body']);
        $cards = [];
        $defaultId = $customerVault->getDefaultMethodId();
        $defaultFound = false;
        foreach ($profiles as $profile) {
            $rawMethodId = $profile['MethodID']
                ?? $profile['MethodId']
                ?? $profile['PaymentMethodID']
                ?? $profile['PaymentMethodId']
                ?? $profile['paymentMethodId']
                ?? $profile['methodID']
                ?? $profile['methodId']
                ?? null;

            if (!is_scalar($rawMethodId)) {
                continue;
            }

            $methodId = trim((string) $rawMethodId);
            if ($methodId === '') {
                continue;
            }

            $masked = $profile['CardNumber'] ?? $profile['cardNumber'] ?? '';
            $brand = $profile['CardType'] ?? $profile['cardType'] ?? '';
            $expiry = $profile['CardExpiration'] ?? $profile['cardExpiration'] ?? '';
            $name = $profile['MethodName'] ?? $profile['methodName'] ?? '';
            $isDefault = $defaultId !== null && hash_equals($defaultId, $methodId);
            $defaultFound = $defaultFound || $isDefault;

            $cards[] = [
                'methodId' => $methodId,
                'masked' => is_scalar($masked) ? trim((string) $masked) : '',
                'brand' => is_scalar($brand) ? trim((string) $brand) : '',
                'expiry' => is_scalar($expiry) ? trim((string) $expiry) : '',
                'name' => is_scalar($name) ? trim((string) $name) : '',
                'isDefault' => $isDefault,
            ];
        }

        if ($cards !== [] && ($defaultId === null || !$defaultFound)) {
            $cards[0]['isDefault'] = true;
            $defaultId = (string) $cards[0]['methodId'];
        }

        if ($cards === []) {
            $defaultId = null;
        }

        if ($defaultId !== $customerVault->getDefaultMethodId()) {
            $this->vaultedCustomerRepository->update([
                [
                    'id' => $customerVault->getId(),
                    'defaultMethodId' => $defaultId,
                ],
            ], $context);
            $customerVault->setDefaultMethodId($defaultId);
        }

        return $cards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatCardsForDisplay(array $cards): array
    {
        $formattedCards = [];
        foreach ($cards as $card) {
            $formattedCards[] = [
                'id' => (string) ($card['methodId'] ?? ''),
                'methodId' => (string) ($card['methodId'] ?? ''),
                'ebizMethodId' => (string) ($card['methodId'] ?? ''),
                'cardMasked' => (string) ($card['masked'] ?? ''),
                'cardBrand' => (string) ($card['brand'] ?? ''),
                'cardExpiry' => (string) ($card['expiry'] ?? ''),
                'methodName' => (string) ($card['name'] ?? ''),
                'isDefault' => !empty($card['isDefault']),
            ];
        }

        return $formattedCards;
    }

    public function deleteSavedMethod(
        EbizchargeVaultedCustomerEntity $customerVault,
        string $methodId,
        Context $context
    ): void {
        $config = $this->configProvider->get($customerVault->getSalesChannelId());
        $config->assertComplete();
        $customerToken = (string) $customerVault->getEbizCustomerToken();
        if ($customerToken === '') {
            throw new \RuntimeException('Missing gateway token.');
        }

        $this->providerClient->send(
            ProviderOperation::DELETE_CUSTOMER_PAYMENT_METHOD_PROFILE,
            ['customerToken' => $customerToken, 'paymentMethodId' => $methodId],
            $config
        );

        if ($customerVault->getDefaultMethodId() === $methodId) {
            $this->vaultedCustomerRepository->update([
                ['id' => $customerVault->getId(), 'defaultMethodId' => null],
            ], $context);
            $customerVault->setDefaultMethodId(null);
        }
    }

    public function setDefaultSavedMethod(
        EbizchargeVaultedCustomerEntity $customerVault,
        string $methodId,
        Context $context
    ): void {
        $config = $this->configProvider->get($customerVault->getSalesChannelId());
        $config->assertComplete();
        $customerToken = (string) $customerVault->getEbizCustomerToken();
        if ($customerToken === '') {
            throw new \RuntimeException('Missing gateway token.');
        }

        $this->providerClient->send(
            ProviderOperation::SET_DEFAULT_CUSTOMER_PAYMENT_METHOD_PROFILE,
            ['customerToken' => $customerToken, 'paymentMethodId' => $methodId],
            $config
        );

        $this->vaultedCustomerRepository->update([
            ['id' => $customerVault->getId(), 'defaultMethodId' => $methodId],
        ], $context);
        $customerVault->setDefaultMethodId($methodId);
    }

    public function getAccountAddCardHostedUrl(SalesChannelContext $context): string
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            throw new \RuntimeException('Customer required.');
        }

        $this->ensureVault($context);
        $config = $this->configProvider->get($context->getSalesChannelId());
        $config->assertComplete();
        $payload = $this->createHostedAddCardPayload($context);
        $response = $this->providerClient->send(ProviderOperation::GET_WEBFORM_URL, $payload, $config);

        return $this->responseNormalizer->extractHostedRedirectUrl($response['body']);
    }

    private function createHostedAddCardPayload(SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            throw new \RuntimeException('Customer required.');
        }

        $billing = $customer->getDefaultBillingAddress();
        $billingData = new AddressData(
            $billing?->getFirstName() ?? $customer->getFirstName(),
            $billing?->getLastName() ?? $customer->getLastName(),
            $billing?->getCompany(),
            $billing?->getStreet() ?? 'n/a',
            null,
            $billing?->getCity() ?? 'n/a',
            $this->normalizeStateCode($billing?->getCountryState()?->getShortCode()),
            $billing?->getZipcode() ?? '00000',
            $billing?->getCountry()?->getIso() ?? 'US'
        );

        $fromName = $context->getSalesChannel()->getName() ?? 'Shop';

        return [
            'ePaymentForm' => [
                'formType' => ProviderContract::WEBFORM_PM_REQUEST_FORM,
                'fromEmail' => (string) $customer->getEmail(),
                'fromName' => $fromName,
                'emailAddress' => (string) $customer->getEmail(),
                'replyToEmailAddress' => (string) $customer->getEmail(),
                'replyToDisplayName' => $fromName,
                'emailTemplateID' => 'WebFormEmail',
                'sendEmailToCustomer' => false,
                'customerId' => $customer->getId(),
                'custFullName' => trim(sprintf('%s %s', $customer->getFirstName(), $customer->getLastName())),
                'billingAddress' => $billingData->toProviderArray(),
                'payByType' => ProviderContract::PAY_BY_TYPE_CREDIT_CARD,
            ],
        ];
    }

    private function findVault(string $customerId, string $salesChannelId, Context $context): ?EbizchargeVaultedCustomerEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->setLimit(1);

        $entity = $this->vaultedCustomerRepository->search($criteria, $context)->first();

        return $entity instanceof EbizchargeVaultedCustomerEntity ? $entity : null;
    }

    private function loadCustomerWithBilling(string $customerId, Context $context): CustomerEntity
    {
        $criteria = (new Criteria([$customerId]))->addAssociation('defaultBillingAddress.country');
        $customer = $this->customerRepository->search($criteria, $context)->first();
        if (!$customer instanceof CustomerEntity) {
            throw new \RuntimeException('Customer not found.');
        }

        return $customer;
    }

    private function getOrCreateCustomerInternalId(PluginConfig $config, CustomerEntity $customer): string
    {
        $merchantCustomerId = $customer->getId();
        $search = $this->providerClient->send(
            ProviderOperation::SEARCH_CUSTOMERS,
            [
                'customerInternalId' => '',
                'customerId' => $merchantCustomerId,
                'start' => 0,
                'limit' => 10,
                'sort' => '',
            ],
            $config
        );

        foreach ($this->responseNormalizer->extractSearchCustomers($search['body']) as $row) {
            $customerInternalId = $row['CustomerInternalId'] ?? $row['customerInternalId'] ?? null;
            if (is_scalar($customerInternalId) && trim((string) $customerInternalId) !== '') {
                return trim((string) $customerInternalId);
            }
        }

        $billing = $this->billingFromCustomer($customer);
        $add = $this->providerClient->send(
            ProviderOperation::ADD_CUSTOMER,
            [
                'customer' => [
                    'customerId' => $merchantCustomerId,
                    'firstName' => (string) $customer->getFirstName(),
                    'lastName' => (string) $customer->getLastName(),
                    'email' => (string) $customer->getEmail(),
                    'billingAddress' => $billing->toProviderArray(),
                ],
            ],
            $config
        );

        $customerInternalId = $this->responseNormalizer->findCustomerInternalId($add['body']);
        if ($customerInternalId === null || $customerInternalId === '') {
            throw ProviderCommunicationException::requestFailed('AddCustomer', 'Missing CustomerInternalId.');
        }

        return $customerInternalId;
    }

    private function billingFromCustomer(CustomerEntity $customer): AddressData
    {
        $billing = $customer->getDefaultBillingAddress();
        if ($billing === null) {
            return new AddressData(
                (string) $customer->getFirstName(),
                (string) $customer->getLastName(),
                null,
                'n/a',
                null,
                'n/a',
                null,
                '00000',
                'US'
            );
        }

        return new AddressData(
            $billing->getFirstName(),
            $billing->getLastName(),
            $billing->getCompany(),
            $billing->getStreet(),
            null,
            $billing->getCity(),
            $this->normalizeStateCode($billing->getCountryState()?->getShortCode()),
            (string) $billing->getZipcode(),
            $billing->getCountry()?->getIso()
        );
    }

    private function fetchEbizCustomerToken(PluginConfig $config, string $merchantCustomerId, string $customerInternalId): string
    {
        $response = $this->providerClient->send(
            ProviderOperation::GET_CUSTOMER_TOKEN,
            [
                'CustomerId' => $merchantCustomerId,
                'customerInternalId' => $customerInternalId,
            ],
            $config
        );

        $token = $this->responseNormalizer->extractGetCustomerToken($response['body']);
        if ($token === null || $token === '') {
            throw ProviderCommunicationException::requestFailed('GetCustomerToken', 'Empty token.');
        }

        return $token;
    }

    private function normalizeStateCode(?string $stateCode): ?string
    {
        if ($stateCode === null || $stateCode === '') {
            return null;
        }

        $parts = explode('-', $stateCode, 2);

        return $parts[1] ?? $parts[0];
    }
}
