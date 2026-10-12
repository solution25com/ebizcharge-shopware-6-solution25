<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Webhook;

use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\Exception\VerificationException;
use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\GetTransactionDetailsRequestBuilder;
use EbizChargeShopware\Provider\Request\SearchReceivedPaymentsRequestBuilder;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\Checkout\OrderTransactionLoader;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

final class WebhookPaymentVerifier
{
    public function __construct(
        private readonly OrderTransactionLoader $orderTransactionLoader,
        private readonly PluginConfigProvider $configProvider,
        private readonly ProviderClientInterface $providerClient,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly GetTransactionDetailsRequestBuilder $getTransactionDetailsRequestBuilder,
        private readonly SearchReceivedPaymentsRequestBuilder $searchReceivedPaymentsRequestBuilder,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * EBizCharge webhooks are not signed here; treat them only as a signal and
     * accept payment state after server-side provider verification succeeds.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $record
     */
    public function verifyApprovedPayment(array $payload, array $record, Context $context): ?ProviderOperationResult
    {
        $orderTransactionId = $record['order_transaction_id'] ?? null;
        if (!\is_string($orderTransactionId) || $orderTransactionId === '') {
            return null;
        }

        try {
            $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);
            $config = $this->configProvider->get($orderData->salesChannelId);
            $providerReference = $this->firstString($payload, ['RefNum', 'refNum', 'refnum']);
            $expectedLookupKey = $this->recordLookupKey($record) ?? $orderData->orderTransactionId;

            if ($providerReference !== null) {
                if ($this->providerReferenceBelongsToAnotherTransaction($providerReference, $orderTransactionId, $context)) {
                    $this->logger->warning('EBizCharge webhook ignored because provider reference is already stored on another transaction.', [
                        'orderTransactionId' => $orderTransactionId,
                        'providerReference' => $providerReference,
                    ]);

                    return null;
                }

                $response = $this->providerClient->send(
                    ProviderOperation::GET_TRANSACTION_DETAILS,
                    $this->getTransactionDetailsRequestBuilder->build($providerReference),
                    $config
                );

                $result = $this->responseNormalizer->normalizeVerifiedPayment(
                    $response['body'],
                    $config->processingCommand(),
                    $orderData,
                    $providerReference,
                    $config->enforceAvsCheck(),
                    $expectedLookupKey
                );
            } else {
                $response = $this->providerClient->send(
                    ProviderOperation::SEARCH_RECEIVED_PAYMENTS,
                    $this->searchReceivedPaymentsRequestBuilder->build($expectedLookupKey, $orderData, $config),
                    $config
                );

                $result = $this->responseNormalizer->normalizeVerifiedPayment(
                    $response['body'],
                    $config->processingCommand(),
                    $orderData,
                    null,
                    $config->enforceAvsCheck(),
                    $expectedLookupKey
                );
            }
        } catch (ProviderCommunicationException | VerificationException $exception) {
            $this->logger->warning('EBizCharge webhook ignored because provider verification failed.', [
                'orderTransactionId' => $orderTransactionId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (\Throwable $exception) {
            $this->logger->error('EBizCharge webhook provider verification errored.', [
                'orderTransactionId' => $orderTransactionId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($result->outcome !== ProviderOperationResult::OUTCOME_APPROVED) {
            $this->logger->info('EBizCharge webhook ignored because provider result was not approved.', [
                'orderTransactionId' => $orderTransactionId,
                'outcome' => $result->outcome,
            ]);

            return null;
        }

        return $result;
    }

    private function providerReferenceBelongsToAnotherTransaction(
        string $providerReference,
        string $orderTransactionId,
        Context $context
    ): bool {
        $existing = $this->transactionRecordStore->findByProviderReference($providerReference, $context);
        if ($existing === null) {
            return false;
        }

        $existingOrderTransactionId = $existing['order_transaction_id'] ?? null;

        return !\is_string($existingOrderTransactionId) || !hash_equals($orderTransactionId, $existingOrderTransactionId);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordLookupKey(array $record): ?string
    {
        return $this->recordString($record, 'lookup_key');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     */
    private function firstString(array $data, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $this->recordString($data, $field);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordString(array $data, string $field): ?string
    {
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) !== strtolower($field) || !\is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
