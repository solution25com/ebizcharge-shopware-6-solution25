<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\StateSync;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;

final class StateTransitionPolicy
{
    public function shouldApply(?string $currentState, string $targetState): bool
    {
        if ($currentState === null || $currentState === '') {
            return true;
        }

        if ($currentState === $targetState) {
            return false;
        }

        if (
            in_array($currentState, [OrderTransactionStates::STATE_PAID, OrderTransactionStates::STATE_AUTHORIZED], true)
            && !in_array($targetState, [OrderTransactionStates::STATE_PAID, OrderTransactionStates::STATE_AUTHORIZED], true)
        ) {
            return false;
        }

        if (
            in_array($currentState, [OrderTransactionStates::STATE_FAILED, OrderTransactionStates::STATE_CANCELLED], true)
            && $targetState === OrderTransactionStates::STATE_IN_PROGRESS
        ) {
            return false;
        }

        return true;
    }
}
