<?php

declare(strict_types=1);

namespace EbizChargeShopware\Storage\Dal;

use EbizChargeShopware\Core\Content\EbizchargePaymentTransaction\EbizchargePaymentTransactionEntity;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;

final class DalTransactionRecordStore implements TransactionRecordStoreInterface
{
    private const FIELD_MAP = [
        'order_id' => 'orderId',
        'order_number' => 'orderNumber',
        'lookup_key' => 'lookupKey',
        'mode' => 'mode',
        'normalized_state' => 'normalizedState',
        'provider_ref_num' => 'providerRefNum',
        'provider_auth_code' => 'providerAuthCode',
        'provider_payment_type' => 'providerPaymentType',
        'provider_payment_method' => 'providerPaymentMethod',
        'amount_total' => 'amountTotal',
        'currency_iso' => 'currencyIso',
        'last_support_message' => 'lastSupportMessage',
        'last_sync_at' => 'lastSyncAt',
    ];

    public function __construct(private readonly EntityRepository $repository)
    {
    }

    public function upsert(string $orderTransactionId, array $record, Context $context): void
    {
        $payload = [
            'orderTransactionId' => $orderTransactionId,
            'updatedAt' => new \DateTimeImmutable(),
        ];

        foreach (self::FIELD_MAP as $storageName => $propertyName) {
            if (array_key_exists($storageName, $record)) {
                $payload[$propertyName] = $this->normalizeValue($propertyName, $record[$storageName]);
            }
        }

        $this->repository->upsert([$payload], $context);
    }

    public function find(string $orderTransactionId, Context $context): ?array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId))
            ->setLimit(1);

        return $this->format(DalSearchResultHelper::first($this->repository->search($criteria, $context)));
    }

    public function findByOrderIdentity(string $value, Context $context): ?array
    {
        $criteria = (new Criteria())
            ->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('orderNumber', $value),
                new EqualsFilter('orderId', $value),
            ]))
            ->setLimit(1);

        return $this->format(DalSearchResultHelper::first($this->repository->search($criteria, $context)));
    }

    private function format(mixed $entity): ?array
    {
        if (!$entity instanceof EbizchargePaymentTransactionEntity) {
            return null;
        }

        return [
            'order_transaction_id' => $entity->getOrderTransactionId(),
            'order_id' => $entity->getOrderId(),
            'order_number' => $entity->getOrderNumber(),
            'lookup_key' => $entity->getLookupKey(),
            'mode' => $entity->getMode(),
            'normalized_state' => $entity->getNormalizedState(),
            'provider_ref_num' => $entity->getProviderRefNum(),
            'provider_auth_code' => $entity->getProviderAuthCode(),
            'provider_payment_type' => $entity->getProviderPaymentType(),
            'provider_payment_method' => $entity->getProviderPaymentMethod(),
            'amount_total' => $entity->getAmountTotal(),
            'currency_iso' => $entity->getCurrencyIso(),
            'last_support_message' => $entity->getLastSupportMessage(),
            'last_sync_at' => $entity->getLastSyncAt()?->format('Y-m-d H:i:s.v'),
        ];
    }

    private function normalizeValue(string $propertyName, mixed $value): mixed
    {
        if ($propertyName !== 'lastSyncAt' || $value === null || $value instanceof \DateTimeInterface) {
            return $value;
        }

        return new \DateTimeImmutable((string) $value);
    }
}
