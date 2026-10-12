<?php

declare(strict_types=1);

namespace EbizChargeShopware\Controller\Storefront;

use EbizChargeShopware\Service\StateSync\TransactionStateSyncService;
use EbizChargeShopware\Service\Webhook\WebhookPaymentVerifier;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class WebhookController
{
    public function __construct(
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly WebhookPaymentVerifier $webhookPaymentVerifier,
        private readonly TransactionStateSyncService $transactionStateSyncService
    ) {
    }

    #[Route(
        path: '/ebizcharge/webhook',
        name: 'frontend.ebizcharge.webhook',
        methods: ['POST']
    )]
    public function webhook(Request $request, Context $context): JsonResponse
    {
        $payload = (string) $request->getContent();
        $data = json_decode($payload, true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        if (!$this->isSaleWebhook($data)) {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => '',
            ]);
        }

        $record = $this->findMatchingTransactionRecord($data, $context);
        if ($record === null) {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => '',
            ]);
        }

        $orderTransactionId = $record['order_transaction_id'] ?? null;
        if (!is_string($orderTransactionId) || $orderTransactionId === '') {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => '',
            ]);
        }

        $result = $this->webhookPaymentVerifier->verifyApprovedPayment($data, $record, $context);
        $eventType = $this->eventType($result);

        if ($eventType === '') {
            return new JsonResponse([
                'status' => 'ignored',
                'eventType' => $eventType,
            ]);
        }

        $this->transactionStateSyncService->apply($orderTransactionId, $result, $context);

        return new JsonResponse([
            'status' => 'ok',
            'eventType' => $eventType,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isSaleWebhook(array $data): bool
    {
        $paymentType = strtolower((string) ($this->firstString($data, ['PaymentType', 'paymentType']) ?? ''));
        $typeId = strtolower((string) ($this->firstString($data, ['TypeId', 'typeId']) ?? ''));

        if ($paymentType === 'sale' && in_array($typeId, ['webform', 'customerportal', 'recurring'], true)) {
            return true;
        }

        return $paymentType === 'sale' && $this->firstString($data, ['PaymentInternalId', 'paymentInternalId']) !== null;
    }

    private function eventType(?ProviderOperationResult $result): string
    {
        if ($result?->outcome !== ProviderOperationResult::OUTCOME_APPROVED) {
            return '';
        }

        return match ($this->verifiedPaymentMode($result)) {
            'sale', 'capture', 'captured', 'settled', 'settlement' => 'transaction.sale.captured',
            'authonly', 'auth', 'authorize', 'authorized', 'authorization' => 'transaction.sale.authorized',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $eventObject
     *
     * @return array<string, mixed>|null
     */
    private function findMatchingTransactionRecord(array $eventObject, Context $context): ?array
    {
        $lookupKey = $this->firstString($eventObject, [
            'TransactionLookupKey',
            'transactionLookupKey',
            'lookupKey',
        ]);
        if ($lookupKey !== null && ($record = $this->transactionRecordStore->findByLookupKey($lookupKey, $context))) {
            return $record;
        }

        $paymentInternalId = $this->firstString($eventObject, [
            'PaymentInternalId',
            'paymentInternalId',
            'ScheduledPaymentInternalId',
            'scheduledPaymentInternalId',
        ]);
        if ($paymentInternalId !== null && ($record = $this->transactionRecordStore->findByPaymentInternalId($paymentInternalId, $context))) {
            return $record;
        }

        $fields = [
            'orderid',
            'OrderId',
            'ponum',
            'PoNum',
            'invoice',
            'InvoiceNumber',
        ];

        foreach ($fields as $field) {
            $identifier = $this->string($eventObject, $field);

            if ($identifier === null) {
                continue;
            }

            if ($record = $this->transactionRecordStore->findByOrderIdentity($identifier, $context)) {
                return $record;
            }
        }

        return null;
    }

    private function verifiedPaymentMode(ProviderOperationResult $result): string
    {
        return strtolower((string) preg_replace(
            '/[^a-z0-9]+/',
            '',
            $result->providerPaymentType ?? $result->operationMode
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     */
    private function firstString(array $data, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $this->string($data, $field);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $field): ?string
    {
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) !== strtolower($field) || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
