<?php

declare(strict_types=1);

namespace EbizChargeShopware\Controller\Storefront;

use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class WebhookController
{
    public function __construct(
        private readonly PluginConfigProvider $pluginConfigProvider,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/ebizcharge/webhook',
        name: 'frontend.ebizcharge.webhook',
        methods: ['POST']
    )]
    public function webhook(Request $request, Context $context): JsonResponse
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true);

        $salesChannelId = $request->attributes->get('sw-sales-channel-id');

        $config = $this->pluginConfigProvider->get($salesChannelId);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        if (!$this->matchesWebhookBasicAuth($request, $config)) {
            return new JsonResponse(['error' => 'Invalid webhook basic auth'], 401);
        }

        if (!$this->matchesWebhookSignature($request, $payload, $config)) {
            return new JsonResponse(['error' => 'Invalid webhook signature'], 401);
        }

        $eventType = strtolower((string) ($data['event_type'] ?? $data['eventType'] ?? ''));
        $eventBody = is_array($data['event_body'] ?? null) ? $data['event_body'] : [];
        $eventObject = is_array($eventBody['object'] ?? null) ? $eventBody['object'] : [];

        $targetState = $this->targetStateForEvent($eventType);

        if ($targetState === null) {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => $eventType,
            ]);
        }

        $record = $this->findMatchingTransactionRecord($eventObject, $context);
        if ($record === null) {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => $eventType,
            ]);
        }

        $orderTransactionId = $record['order_transaction_id'] ?? null;
        if (!is_string($orderTransactionId) || $orderTransactionId === '') {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => $eventType,
            ]);
        }

        $providerReference = $eventObject['refnum'] ?? null;
        $providerReference = is_scalar($providerReference) ? trim((string) $providerReference) : null;

        $this->transactionRecordStore->upsert($orderTransactionId, [
            'provider_ref_num' => $providerReference,
            'normalized_state' => $targetState,
            'last_support_message' => sprintf('Webhook event %s synced transaction.', $eventType),
            'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], $context);

        $this->applyWebhookState($orderTransactionId, $targetState, $context);

        return new JsonResponse([
            'status' => 'ok',
            'eventType' => $eventType,
        ]);
    }

    private function targetStateForEvent(string $eventType): ?string
    {
        return match ($eventType) {
            'transaction.sale.captured' => OrderTransactionStates::STATE_PAID,
            'transaction.sale.voided' => OrderTransactionStates::STATE_CANCELLED,
            'transaction.sale.refunded' => OrderTransactionStates::STATE_REFUNDED,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $eventObject
     *
     * @return array<string, mixed>|null
     */
    private function findMatchingTransactionRecord(array $eventObject, Context $context): ?array
    {
        $fields = ['orderid', 'ponum', 'invoice'];

        foreach ($fields as $field) {
            $identifier = trim((string) ($eventObject[$field] ?? ''));

            if ($identifier === '') {
                continue;
            }

            if ($record = $this->transactionRecordStore->findByOrderIdentity($identifier, $context)) {
                return $record;
            }
        }

        return null;
    }

    private function applyWebhookState(string $orderTransactionId, string $targetState, Context $context): string
    {
        try {
            match ($targetState) {
                OrderTransactionStates::STATE_PAID => $this->orderTransactionStateHandler->paid($orderTransactionId, $context),
                OrderTransactionStates::STATE_CANCELLED => $this->orderTransactionStateHandler->cancel($orderTransactionId, $context),
                OrderTransactionStates::STATE_REFUNDED => $this->orderTransactionStateHandler->refund($orderTransactionId, $context),
                default => null,
            };
        } catch (IllegalTransitionException $exception) {
            $this->logger->warning('Webhook transaction state transition was rejected by Shopware.', [
                'orderTransactionId' => $orderTransactionId,
                'targetState' => $targetState,
                'message' => $exception->getMessage(),
            ]);
        }

        return $targetState;
    }

    private function matchesWebhookSignature(Request $request, string $payload, PluginConfig $config): bool
    {
        $signatureHeader = $request->headers->get('X-Signature');
        $secret = $config->webhookSignatureKey();

        if (!is_string($signatureHeader) || trim($signatureHeader) === '' || trim($secret) === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signatureHeader);
    }

    private function matchesWebhookBasicAuth(Request $request, PluginConfig $config): bool
    {
        $expectedUsername = trim($config->webhookBasicUsername());
        $expectedPassword = trim($config->webhookBasicPassword());

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        $actualUsername = (string) $request->server->get('PHP_AUTH_USER', $request->getUser() ?? '');
        $actualPassword = (string) $request->server->get('PHP_AUTH_PW', $request->getPassword() ?? '');

        return hash_equals($expectedUsername, $actualUsername) && hash_equals($expectedPassword, $actualPassword);
    }
}
