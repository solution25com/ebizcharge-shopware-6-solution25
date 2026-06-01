<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargePaymentTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

class EbizchargePaymentTransactionEntity extends Entity
{
    protected string $orderTransactionId;

    protected ?string $orderId = null;

    protected ?string $orderNumber = null;

    protected ?string $lookupKey = null;

    protected ?string $mode = null;

    protected ?string $normalizedState = null;

    protected ?string $providerRefNum = null;

    protected ?string $providerAuthCode = null;

    protected ?string $providerPaymentType = null;

    protected ?string $providerPaymentMethod = null;

    protected ?float $amountTotal = null;

    protected ?string $currencyIso = null;

    protected ?string $lastSupportMessage = null;

    protected ?\DateTimeInterface $lastSyncAt = null;

    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    public function setOrderTransactionId(string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getLookupKey(): ?string
    {
        return $this->lookupKey;
    }

    public function setLookupKey(?string $lookupKey): void
    {
        $this->lookupKey = $lookupKey;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): void
    {
        $this->mode = $mode;
    }

    public function getNormalizedState(): ?string
    {
        return $this->normalizedState;
    }

    public function setNormalizedState(?string $normalizedState): void
    {
        $this->normalizedState = $normalizedState;
    }

    public function getProviderRefNum(): ?string
    {
        return $this->providerRefNum;
    }

    public function setProviderRefNum(?string $providerRefNum): void
    {
        $this->providerRefNum = $providerRefNum;
    }

    public function getProviderAuthCode(): ?string
    {
        return $this->providerAuthCode;
    }

    public function setProviderAuthCode(?string $providerAuthCode): void
    {
        $this->providerAuthCode = $providerAuthCode;
    }

    public function getProviderPaymentType(): ?string
    {
        return $this->providerPaymentType;
    }

    public function setProviderPaymentType(?string $providerPaymentType): void
    {
        $this->providerPaymentType = $providerPaymentType;
    }

    public function getProviderPaymentMethod(): ?string
    {
        return $this->providerPaymentMethod;
    }

    public function setProviderPaymentMethod(?string $providerPaymentMethod): void
    {
        $this->providerPaymentMethod = $providerPaymentMethod;
    }

    public function getAmountTotal(): ?float
    {
        return $this->amountTotal;
    }

    public function setAmountTotal(?float $amountTotal): void
    {
        $this->amountTotal = $amountTotal;
    }

    public function getCurrencyIso(): ?string
    {
        return $this->currencyIso;
    }

    public function setCurrencyIso(?string $currencyIso): void
    {
        $this->currencyIso = $currencyIso;
    }

    public function getLastSupportMessage(): ?string
    {
        return $this->lastSupportMessage;
    }

    public function setLastSupportMessage(?string $lastSupportMessage): void
    {
        $this->lastSupportMessage = $lastSupportMessage;
    }

    public function getLastSyncAt(): ?\DateTimeInterface
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeInterface $lastSyncAt): void
    {
        $this->lastSyncAt = $lastSyncAt;
    }
}
