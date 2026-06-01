<?php

declare(strict_types=1);

namespace EbizChargeShopware\Subscriber;

use EbizChargeShopware\Checkout\Payment\Handler\CreditCardPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use EbizChargeShopware\Service\EbizChargeApiClient;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderTransactionStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EbizChargeApiClient $apiClient,
        private readonly TransactionRecordStoreInterface $transactionRecordStore
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.paid' => 'onPaid',
            'state_enter.order_transaction.state.cancelled' => 'onCancelled',
            'state_enter.order_transaction.state.refunded' => 'onRefunded',
        ];
    }

    public function onPaid(OrderStateMachineStateChangeEvent $event): void
    {
        $transactionId = $this->getEbizChargeTransactionId($event);

        if ($transactionId === null || $this->shouldSkipProviderCall($transactionId, OrderTransactionStates::STATE_PAID, $event->getContext())) {
            return;
        }

        $this->apiClient->capture($transactionId, $event->getContext());
    }

    public function onCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $transactionId = $this->getEbizChargeTransactionId($event);

        if ($transactionId === null || $this->shouldSkipProviderCall($transactionId, OrderTransactionStates::STATE_CANCELLED, $event->getContext())) {
            return;
        }

        $this->apiClient->void($transactionId, $event->getContext());
    }

    public function onRefunded(OrderStateMachineStateChangeEvent $event): void
    {
        $transactionId = $this->getEbizChargeTransactionId($event);

        if ($transactionId === null || $this->shouldSkipProviderCall($transactionId, OrderTransactionStates::STATE_REFUNDED, $event->getContext())) {
            return;
        }

        $this->apiClient->refund($transactionId, $event->getContext());
    }

    private function getEbizChargeTransactionId(OrderStateMachineStateChangeEvent $event): ?string
    {
        $transactions = $event->getOrder()->getTransactions();

        if ($transactions === null) {
            return null;
        }

        $ebizHandlers = [CreditCardPaymentHandler::class, PayByLinkPaymentHandler::class];

        foreach ($transactions as $transaction) {
            if (in_array($transaction->getPaymentMethod()?->getHandlerIdentifier(), $ebizHandlers, true)) {
                return $transaction->getId();
            }
        }

        return null;
    }

    private function shouldSkipProviderCall(string $orderTransactionId, string $targetState, Context $context): bool
    {
        $record = $this->transactionRecordStore->find($orderTransactionId, $context);

        if ($record === null) {
            return false;
        }

        return ($record['normalized_state'] ?? null) === $targetState;
    }
}
