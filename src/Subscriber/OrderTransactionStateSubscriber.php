<?php

declare(strict_types=1);

namespace EbizChargeShopware\Subscriber;

use EbizChargeShopware\Checkout\Payment\Handler\AchPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\CreditCardPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use EbizChargeShopware\Service\EbizChargeApiClient;
use EbizChargeShopware\Storage\Dal\DalSearchResultHelper;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderTransactionStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EbizChargeApiClient $apiClient,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_transaction.state_changed' => 'onStateChanged',
        ];
    }

    public function onStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        $targetState = $event->getNextState()->getTechnicalName();
        if (
            !\in_array($targetState, [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_CANCELLED,
            OrderTransactionStates::STATE_REFUNDED,
            ], true)
        ) {
            return;
        }

        $transactionId = $event->getTransition()->getEntityId();
        if (!$this->isEbizChargeTransaction($transactionId, $event->getContext()) || $this->shouldSkipProviderCall($transactionId, $targetState, $event->getContext())) {
            return;
        }

        if ($targetState === OrderTransactionStates::STATE_PAID) {
            $this->apiClient->capture($transactionId, $event->getContext());

            return;
        }

        if ($targetState === OrderTransactionStates::STATE_CANCELLED) {
            $this->apiClient->void($transactionId, $event->getContext());

            return;
        }

        $this->apiClient->refund($transactionId, $event->getContext());
    }

    private function isEbizChargeTransaction(string $orderTransactionId, Context $context): bool
    {
        $criteria = (new Criteria([$orderTransactionId]))->addAssociation('paymentMethod');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = DalSearchResultHelper::first($this->orderTransactionRepository->search($criteria, $context));

        $handler = $transaction?->getPaymentMethod()?->getHandlerIdentifier();

        return \in_array($handler, [CreditCardPaymentHandler::class, AchPaymentHandler::class, PayByLinkPaymentHandler::class], true);
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
