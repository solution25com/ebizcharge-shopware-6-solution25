<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Service\Checkout\OrderTransactionLoader;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

final class EbizChargeApiClient
{
    private const COMMAND_CAPTURE = 'capture';
    private const COMMAND_REFUND = 'refund';
    private const COMMAND_VOID = 'void';
    private const AUTH_EXPIRY_ACTION_REAUTH = 'reauth';

    public function __construct(
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly OrderTransactionLoader $orderTransactionLoader,
        private readonly PluginConfigProvider $configProvider,
        private readonly ProviderClientInterface $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function capture(string $orderTransactionId, Context $context): void
    {
        $record = $this->loadRecord($orderTransactionId, self::COMMAND_CAPTURE, $context);

        if ($record === null) {
            return;
        }

        if (strtoupper((string) ($record['provider_payment_type'] ?? '')) !== 'AUTHONLY') {
            return;
        }

        $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);
        $amount = round((float) ($record['amount_total'] ?? 0.0), 2);
        $config = $this->configProvider->get($orderData->salesChannelId);

        $this->runTransaction($orderTransactionId, [
            'tran' => [
                'command' => self::COMMAND_CAPTURE,
                'refNum' => $record['provider_ref_num'],
                'ifAuthExpired' => self::AUTH_EXPIRY_ACTION_REAUTH,
                'ignoreDuplicate' => false,
                'details' => $this->buildTransactionDetails($orderData, $config, $amount),
                'lineItems' => $this->buildLineItems($orderData),
            ],
        ], self::COMMAND_CAPTURE, $record['provider_ref_num'], $orderData, $context);
    }

    public function void(string $orderTransactionId, Context $context): void
    {
        $record = $this->loadRecord($orderTransactionId, self::COMMAND_VOID, $context);

        if ($record === null) {
            return;
        }

        $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);
        $config = $this->configProvider->get($orderData->salesChannelId);

        $this->runTransaction($orderTransactionId, [
            'tran' => [
                'command' => self::COMMAND_VOID,
                'refNum' => $record['provider_ref_num'],
                'ignoreDuplicate' => false,
                'details' => $this->buildTransactionDetails($orderData, $config, round((float) ($record['amount_total'] ?? 0.0), 2)),
            ],
        ], self::COMMAND_VOID, $record['provider_ref_num'], $orderData, $context);
    }

    public function refund(string $orderTransactionId, Context $context): void
    {
        $record = $this->loadRecord($orderTransactionId, self::COMMAND_REFUND, $context);

        if ($record === null) {
            return;
        }

        $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);
        $amount = round((float) ($record['amount_total'] ?? 0.0), 2);
        $config = $this->configProvider->get($orderData->salesChannelId);

        $this->runTransaction($orderTransactionId, [
            'tran' => [
                'command' => self::COMMAND_REFUND,
                'refNum' => $record['provider_ref_num'],
                'ignoreDuplicate' => false,
                'details' => $this->buildTransactionDetails($orderData, $config, $amount),
                'lineItems' => $this->buildLineItems($orderData),
            ],
        ], self::COMMAND_REFUND, $record['provider_ref_num'], $orderData, $context);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRecord(string $orderTransactionId, string $operation, Context $context): ?array
    {
        $record = $this->transactionRecordStore->find($orderTransactionId, $context);

        if ($record === null) {
            return null;
        }

        $refNum = (string) ($record['provider_ref_num'] ?? '');

        if ($refNum === '') {
            $this->logger->error(sprintf('eBizCharge %s skipped: no reference number in stored record.', $operation), [
                'orderTransactionId' => $orderTransactionId,
            ]);

            return null;
        }

        $record['provider_ref_num'] = $refNum;

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function runTransaction(
        string $orderTransactionId,
        array $payload,
        string $command,
        string $originalRefNum,
        CheckoutOrderData $orderData,
        Context $context
    ): void {
        $config = $this->configProvider->get($orderData->salesChannelId);

        try {
            $response = $this->client->send(ProviderOperation::RUN_TRANSACTION, $payload, $config);

            $result = $response['body']['runTransactionResponse']['runTransactionResult'] ?? [];

            $resultStatus = strtolower(trim((string) ($result['result'] ?? '')));
            $returnedRefNum = (string) ($result['refNum'] ?? $originalRefNum);

            if (str_contains($resultStatus, 'approved') || str_contains($resultStatus, 'success')) {
                $this->logger->info(sprintf('eBizCharge %s succeeded.', $command), [
                    'orderTransactionId' => $orderTransactionId,
                    'refNum' => $returnedRefNum,
                ]);

                $this->transactionRecordStore->upsert($orderTransactionId, [
                    'provider_ref_num' => $returnedRefNum,
                    'last_support_message' => sprintf('%s approved.', ucfirst($command)),
                    'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                ], $context);
            } else {
                $this->logger->error(sprintf('eBizCharge %s returned a non-approval result.', $command), [
                    'orderTransactionId' => $orderTransactionId,
                    'refNum' => $originalRefNum,
                    'result' => $resultStatus,
                ]);

                throw new \RuntimeException(sprintf('eBizCharge %s was not approved: %s', $command, $resultStatus));
            }
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('eBizCharge %s request failed.', $command), [
                'orderTransactionId' => $orderTransactionId,
                'refNum' => $originalRefNum,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('eBizCharge %s request failed: %s', $command, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransactionDetails(CheckoutOrderData $orderData, PluginConfig $config, float $amount): array
    {
        return [
            'amount' => $amount,
            'subtotal' => $amount,
            'tax' => round($orderData->taxAmount, 2),
            'shipping' => round($orderData->shippingAmount, 2),
            'duty' => 0,
            'discount' => 0,
            'tip' => 0,
            'nonTax' => false,
            'shipFromZip' => $config->shipFromZip(),
            'orderID' => $orderData->orderId,
            'invoice' => $orderData->orderNumber,
            'description' => $config->descriptionForOrder($orderData->orderNumber),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(CheckoutOrderData $orderData): array
    {
        $lineItems = [];

        foreach ($orderData->lineItems as $item) {
            $lineItems[] = $item->toProviderArray();
        }

        return $lineItems;
    }
}
