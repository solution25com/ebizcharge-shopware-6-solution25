<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\StateSync;

use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;

final class TransactionStateResolver
{
    public function resolve(ProviderOperationResult $result): string
    {
        return match ($result->outcome) {
            ProviderOperationResult::OUTCOME_APPROVED => strtoupper($result->operationMode) === 'AUTHONLY'
                ? OrderTransactionStates::STATE_AUTHORIZED
                : OrderTransactionStates::STATE_PAID,
            ProviderOperationResult::OUTCOME_DECLINED => OrderTransactionStates::STATE_FAILED,
            ProviderOperationResult::OUTCOME_CANCELLED => OrderTransactionStates::STATE_CANCELLED,
            default => OrderTransactionStates::STATE_IN_PROGRESS,
        };
    }
}
