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
    public function findByOrderIdentity(string $value, Context $context): ?array;
}
