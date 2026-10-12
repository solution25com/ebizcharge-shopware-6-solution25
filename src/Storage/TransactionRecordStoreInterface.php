<?php

declare(strict_types=1);

namespace EbizChargeShopware\Storage;

use Shopware\Core\Framework\Context;

interface TransactionRecordStoreInterface
{
    /**
     * @param array<string, mixed> $record
     */
    public function upsert(string $orderTransactionId, array $record, Context $context): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $orderTransactionId, Context $context): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByLookupKey(string $lookupKey, Context $context): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderIdentity(string $value, Context $context): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByPaymentInternalId(string $paymentInternalId, Context $context): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByProviderReference(string $providerReference, Context $context): ?array;
}
