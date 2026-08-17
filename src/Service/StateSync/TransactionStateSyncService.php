<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\StateSync;

use EbizChargeShopware\Storage\Dal\DalSearchResultHelper;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

final class TransactionStateSyncService
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly TransactionStateResolver $stateResolver,
        private readonly StateTransitionPolicy $transitionPolicy,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(string $orderTransactionId, ProviderOperationResult $result, Context $context): string
    {
        $targetState = $this->stateResolver->resolve($result);
        $currentState = $this->currentState($orderTransactionId, $context);

        if (!$this->transitionPolicy->shouldApply($currentState, $targetState)) {
            $this->transactionRecordStore->upsert($orderTransactionId, [
                'normalized_state' => $currentState ?? $targetState,
                'last_support_message' => $result->supportMessage,
                'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ], $context);

            return $currentState ?? $targetState;
        }

        try {
            match ($targetState) {
                OrderTransactionStates::STATE_AUTHORIZED => $this->stateHandler->authorize($orderTransactionId, $context),
                OrderTransactionStates::STATE_PAID => $this->stateHandler->paid($orderTransactionId, $context),
                OrderTransactionStates::STATE_FAILED => $this->stateHandler->fail($orderTransactionId, $context),
                OrderTransactionStates::STATE_CANCELLED => $this->stateHandler->cancel($orderTransactionId, $context),
                default => $this->stateHandler->process($orderTransactionId, $context),
            };
        } catch (IllegalTransitionException $exception) {
            $persistedState = $this->currentState($orderTransactionId, $context) ?? $currentState ?? $targetState;

            $this->logger->warning('EBizCharge state transition raised an exception, continuing with audit-safe record update.', [
                'orderTransactionId' => $orderTransactionId,
                'targetState' => $targetState,
                'persistedState' => $persistedState,
                'message' => $exception->getMessage(),
            ]);

            $this->transactionRecordStore->upsert($orderTransactionId, [
                'provider_ref_num' => $result->providerReference,
                'provider_auth_code' => $result->authorizationCode,
                'normalized_state' => $persistedState,
                'provider_payment_type' => $result->providerPaymentType,
                'provider_payment_method' => $result->providerPaymentMethod,
                'last_support_message' => $result->supportMessage,
                'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ], $context);

            return $persistedState;
        }

        $this->transactionRecordStore->upsert($orderTransactionId, [
            'provider_ref_num' => $result->providerReference,
            'provider_auth_code' => $result->authorizationCode,
            'normalized_state' => $targetState,
            'provider_payment_type' => $result->providerPaymentType,
            'provider_payment_method' => $result->providerPaymentMethod,
            'last_support_message' => $result->supportMessage,
            'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], $context);

        return $targetState;
    }

    private function currentState(string $orderTransactionId, Context $context): ?string
    {
        $criteria = (new Criteria([$orderTransactionId]))->addAssociation('stateMachineState');
        $entity = DalSearchResultHelper::first($this->orderTransactionRepository->search($criteria, $context));

        if (!$entity instanceof OrderTransactionEntity || $entity->getStateMachineState() === null) {
            return null;
        }

        return $entity->getStateMachineState()->getTechnicalName();
    }
}
