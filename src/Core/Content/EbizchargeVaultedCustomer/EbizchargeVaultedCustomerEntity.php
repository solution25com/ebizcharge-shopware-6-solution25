<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargeVaultedCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class EbizchargeVaultedCustomerEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;

    protected string $salesChannelId;

    protected string $merchantCustomerId;

    protected string $customerInternalId;

    protected ?string $ebizCustomerToken = null;

    protected ?string $defaultMethodId = null;

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getMerchantCustomerId(): string
    {
        return $this->merchantCustomerId;
    }

    public function setMerchantCustomerId(string $merchantCustomerId): void
    {
        $this->merchantCustomerId = $merchantCustomerId;
    }

    public function getCustomerInternalId(): string
    {
        return $this->customerInternalId;
    }

    public function setCustomerInternalId(string $customerInternalId): void
    {
        $this->customerInternalId = $customerInternalId;
    }

    public function getEbizCustomerToken(): ?string
    {
        return $this->ebizCustomerToken;
    }

    public function setEbizCustomerToken(?string $ebizCustomerToken): void
    {
        $this->ebizCustomerToken = $ebizCustomerToken;
    }

    public function getDefaultMethodId(): ?string
    {
        return $this->defaultMethodId;
    }

    public function setDefaultMethodId(?string $defaultMethodId): void
    {
        $this->defaultMethodId = $defaultMethodId;
    }
}
