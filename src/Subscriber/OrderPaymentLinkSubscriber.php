<?php

declare(strict_types=1);

namespace EbizChargeShopware\Subscriber;

use EbizChargeShopware\Service\PaymentLinkService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderPaymentLinkSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PaymentLinkService $paymentLinkService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_TRANSACTION_WRITTEN_EVENT => 'onOrderTransactionWritten',
        ];
    }

    public function onOrderTransactionWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $ids     = $event->getIds();

        foreach ($event->getWriteResults() as $index => $writeResult) {
            $payload = $writeResult->getPayload();

            // Only handle transaction creates (must have orderId in payload)
            if (!isset($payload['orderId'])) {
                continue;
            }

            $orderTransactionId = $ids[$index] ?? $ids[0] ?? null;
            if (!is_string($orderTransactionId) || $orderTransactionId === '') {
                continue;
            }

            // Skip if a payment link already exists for this transaction
            if ($this->paymentLinkService->findByOrderTransactionId($orderTransactionId, $context) !== null) {
                continue;
            }

            try {
                if (!$this->paymentLinkService->isPayByLinkTransaction($orderTransactionId, $context)) {
                    continue;
                }

                $this->paymentLinkService->createAndSend($orderTransactionId, $context);
            } catch (\Throwable $e) {
                $this->logger->error('Could not create EBizCharge payment link.', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderId'            => $payload['orderId'],
                    'error'              => $e->getMessage(),
                ]);
            }
        }
    }
}
