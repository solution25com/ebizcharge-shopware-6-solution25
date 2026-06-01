<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Service\StateSync;

use EbizChargeShopware\Service\StateSync\StateTransitionPolicy;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;

final class StateTransitionPolicyTest extends TestCase
{
    public function testDoesNotDowngradePaidToFailed(): void
    {
        $policy = new StateTransitionPolicy();

        self::assertFalse(
            $policy->shouldApply(OrderTransactionStates::STATE_PAID, OrderTransactionStates::STATE_FAILED)
        );
    }

    public function testAllowsOpenToInProgress(): void
    {
        $policy = new StateTransitionPolicy();

        self::assertTrue(
            $policy->shouldApply(OrderTransactionStates::STATE_OPEN, OrderTransactionStates::STATE_IN_PROGRESS)
        );
    }
}
