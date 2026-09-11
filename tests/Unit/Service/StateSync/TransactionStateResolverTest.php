<?php

declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Service\StateSync;

use EbizChargeShopware\Service\StateSync\TransactionStateResolver;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;

final class TransactionStateResolverTest extends TestCase
{
    public function testSaleMapsToPaid(): void
    {
        $resolver = new TransactionStateResolver();
        $result = ProviderOperationResult::approved('Sale', 'ref-1', 'auth-1', 'ok');

        self::assertSame(OrderTransactionStates::STATE_PAID, $resolver->resolve($result));
    }

    public function testAuthOnlyMapsToAuthorized(): void
    {
        $resolver = new TransactionStateResolver();
        $result = ProviderOperationResult::approved('AuthOnly', 'ref-1', 'auth-1', 'ok');

        self::assertSame(OrderTransactionStates::STATE_AUTHORIZED, $resolver->resolve($result));
    }

    public function testDeclineMapsToFailed(): void
    {
        $resolver = new TransactionStateResolver();
        $result = ProviderOperationResult::declined('Sale', 'declined');

        self::assertSame(OrderTransactionStates::STATE_FAILED, $resolver->resolve($result));
    }

    public function testHostedCheckoutRedirectPendingMapsToUnconfirmed(): void
    {
        $resolver = new TransactionStateResolver();
        $result = ProviderOperationResult::pending('Sale', 'Hosted checkout initiated.', true, 'checkout_redirected');

        self::assertSame(OrderTransactionStates::STATE_UNCONFIRMED, $resolver->resolve($result));
    }

    public function testGenericPendingMapsToInProgress(): void
    {
        $resolver = new TransactionStateResolver();
        $result = ProviderOperationResult::pending(
            'Sale',
            'Payment result is pending provider verification.',
            true,
            'verification_pending'
        );

        self::assertSame(OrderTransactionStates::STATE_IN_PROGRESS, $resolver->resolve($result));
    }
}
