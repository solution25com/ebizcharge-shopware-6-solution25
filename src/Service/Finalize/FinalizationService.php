<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Finalize;

use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\Exception\VerificationException;
use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\GetTransactionDetailsRequestBuilder;
use EbizChargeShopware\Provider\Request\SearchReceivedPaymentsRequestBuilder;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\StateSync\TransactionStateSyncService;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\FinalizationOutcome;
use EbizChargeShopware\ValueObject\PluginConfig;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\HttpFoundation\Request;

final class FinalizationService
{
    public function __construct(
        private readonly BrowserReturnParser $browserReturnParser,
        private readonly ProviderClientInterface $providerClient,
        private readonly SearchReceivedPaymentsRequestBuilder $searchReceivedPaymentsRequestBuilder,
        private readonly GetTransactionDetailsRequestBuilder $getTransactionDetailsRequestBuilder,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly TransactionStateSyncService $transactionStateSyncService,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function finalize(
        Request $request,
        CheckoutOrderData $orderData,
        PluginConfig $config,
        Context $context,
        string $formType = 'Webform'
    ): FinalizationOutcome {
        $browserOutcome = $this->browserReturnParser->parse($request);

        return match ($browserOutcome) {
            BrowserReturnOutcome::APPROVED,
            BrowserReturnOutcome::DECLINED,
            BrowserReturnOutcome::CANCELLED,
            BrowserReturnOutcome::ERROR,
            BrowserReturnOutcome::UNKNOWN => $this->handleBrowserReturn($request, $browserOutcome, $orderData, $config, $context, $formType),
        };
    }

    private function handleBrowserReturn(
        Request $request,
        BrowserReturnOutcome $browserOutcome,
        CheckoutOrderData $orderData,
        PluginConfig $config,
        Context $context,
        string $formType = 'Webform'
    ): FinalizationOutcome {
        try {
            $verificationData = $this->lookupVerifiedResult($request, $orderData, $config, $context, $formType);

            /** @var ProviderOperationResult $result */
            $result = $verificationData['result'];

            return $this->handleImmediate(
                $orderData,
                $result,
                $config,
                $context,
                $result->outcome !== ProviderOperationResult::OUTCOME_APPROVED
                    && $result->outcome !== ProviderOperationResult::OUTCOME_CANCELLED,
                $result->outcome === ProviderOperationResult::OUTCOME_CANCELLED,
                $verificationData['paymentInternalId']
            );
        } catch (VerificationException | ProviderCommunicationException $exception) {
            $this->logger->warning('EBizCharge finalization requires follow-up verification.', [
                'orderTransactionId' => $orderData->orderTransactionId,
                'browserOutcome' => $browserOutcome->value,
                'message' => $exception->getMessage(),
            ]);

            return $this->handleImmediate(
                $orderData,
                ProviderOperationResult::pending(
                    $config->processingCommand(),
                    'Payment result is pending provider verification.',
                    true,
                    'verification_pending'
                ),
                $config,
                $context,
                true,
                false,
                null
            );
        }
    }

    /**
     * @return array{result: ProviderOperationResult, paymentInternalId: ?string}
     */
    private function lookupVerifiedResult(
        Request $request,
        CheckoutOrderData $orderData,
        PluginConfig $config,
        Context $context,
        string $formType = 'Webform'
    ): array {
        $referenceNumber = $this->browserReturnParser->referenceNumber($request);

        if ($referenceNumber !== null) {
            $response = $this->providerClient->send(
                ProviderOperation::GET_TRANSACTION_DETAILS,
                $this->getTransactionDetailsRequestBuilder->build($referenceNumber),
                $config
            );

            $this->storeVerificationResponse(
                $orderData->orderTransactionId,
                $response,
                $context
            );

            return [
                'result' => $this->responseNormalizer->normalizeVerifiedPayment(
                    $response['body'],
                    $config->processingCommand(),
                    $orderData,
                    $referenceNumber,
                    $config->enforceAvsCheck()
                ),
                'paymentInternalId' => null,
            ];
        }

        $response = $this->providerClient->send(
            ProviderOperation::SEARCH_RECEIVED_PAYMENTS,
            $this->searchReceivedPaymentsRequestBuilder->build($orderData->orderTransactionId, $orderData, $config, $formType),
            $config
        );

        $this->storeVerificationResponse(
            $orderData->orderTransactionId,
            $response,
            $context
        );

        $searchResult = $response['body']['searchEbizWebFormReceivedPaymentsResponse']['SearchEbizWebFormReceivedPaymentsResult'][0] ?? [];
        $referenceNumber = is_array($searchResult) ? trim((string) ($searchResult['RefNum'] ?? $searchResult['refNum'] ?? '')) : '';
        $paymentInternalId = $searchResult['PaymentInternalId'] ?? $searchResult['paymentInternalId'] ?? null;

        if ($referenceNumber !== '') {
            $detailsResponse = $this->providerClient->send(
                ProviderOperation::GET_TRANSACTION_DETAILS,
                $this->getTransactionDetailsRequestBuilder->build($referenceNumber),
                $config
            );

            return [
                'result' => $this->responseNormalizer->normalizeVerifiedPayment(
                    $detailsResponse['body'],
                    $config->processingCommand(),
                    $orderData,
                    $referenceNumber,
                    $config->enforceAvsCheck()
                ),
                'paymentInternalId' => $paymentInternalId,
            ];
        }

        return [
            'result' => $this->responseNormalizer->normalizeVerifiedPayment(
                $response['body'],
                $config->processingCommand(),
                $orderData,
                null,
                $config->enforceAvsCheck()
            ),
            'paymentInternalId' => $paymentInternalId,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function storeVerificationResponse(
        string $orderTransactionId,
        array $response,
        Context $context
    ): void {
        $criteria = new Criteria([$orderTransactionId]);

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        $customFields = $transaction?->getCustomFields() ?? [];
        $customFields['ebizcharge_verification_response'] = $this->storeResponse($response['body'] ?? []);

        $this->orderTransactionRepository->update([
            [
                'id' => $orderTransactionId,
                'customFields' => $customFields,
            ],
        ], $context);
    }

    private function handleImmediate(
        CheckoutOrderData $orderData,
        ProviderOperationResult $result,
        PluginConfig $config,
        Context $context,
        bool $throwInterrupted,
        bool $throwCustomerCancelled,
        ?string $paymentInternalId
    ): FinalizationOutcome {
        $targetState = $this->transactionStateSyncService->apply($orderData->orderTransactionId, $result, $context);
        $this->markWebFormPaymentAsApplied($result, $config, $paymentInternalId);

        $this->transactionRecordStore->upsert($orderData->orderTransactionId, [
            'order_id' => $orderData->orderId,
            'order_number' => $orderData->orderNumber,
            'provider_ref_num' => $result->providerReference,
            'provider_auth_code' => $result->authorizationCode,
            'normalized_state' => $targetState,
            'mode' => $result->operationMode,
            'provider_payment_type' => $result->providerPaymentType,
            'provider_payment_method' => $result->providerPaymentMethod,
            'last_support_message' => $result->supportMessage,
            'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], $context);

        return new FinalizationOutcome($result, $targetState, $throwInterrupted, $throwCustomerCancelled);
    }

    private function markWebFormPaymentAsApplied(
        ProviderOperationResult $result,
        PluginConfig $config,
        ?string $paymentInternalId
    ): void {
        if ($result->outcome !== ProviderOperationResult::OUTCOME_APPROVED) {
            return;
        }

        if ($paymentInternalId === null || $paymentInternalId === '') {
            return;
        }

        try {
            $this->providerClient->send(
                ProviderOperation::MARK_WEBFORM_PAYMENT_APPLIED,
                ['paymentInternalId' => $paymentInternalId],
                $config
            );
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'EBizCharge web-form payment could not be marked as applied after Shopware state sync.',
                [
                    'providerReference' => $result->providerReference,
                    'paymentInternalId' => $paymentInternalId,
                    'message' => $exception->getMessage(),
                ]
            );
        }
    }

    private function storeResponse(array $body): array
    {
        if (isset($body['searchEbizWebFormReceivedPaymentsResponse'])) {
            $payment = $body['searchEbizWebFormReceivedPaymentsResponse']['SearchEbizWebFormReceivedPaymentsResult'][0] ?? [];

            return [
                'transactionId' => $payment['paymentInternalId'] ?? null,
                'referenceNumber' => $payment['refNum'] ?? null,
                'invoiceNumber' => $payment['invoiceNumber'] ?? null,
                'amount' => $payment['paidAmount'] ?? null,
                'type' => $payment['paymentType'] ?? null,
                'cardLast4' => $payment['last4'] ?? null,
                'cardType' => $payment['paymentMethod'] ?? null,
            ];
        } else {
            $payment = $body['getTransactionDetailsResponse']['getTransactionDetailsResult'] ?? [];
            $response = $payment['response'] ?? [];
            $card = $payment['creditCardData'] ?? [];
            $cardNumber = (string) ($card['cardNumber'] ?? '');
            $last4 = $cardNumber !== '' ? substr(preg_replace('/\D+/', '', $cardNumber) ?? '', -4) ?: null : null;

            return [
                'transactionId' => $payment['transactionId'] ?? null,
                'referenceNumber' => $response['refNum'] ?? null,
                'invoiceNumber' => $payment['details']['invoice'] ?? null,
                'amount' => $response['authAmount'] ?? null,
                'status' => $response['status'] ?? null,
                'type' => $payment['transactionType'] ?? null,
                'cardLast4' => $last4,
                'cardType' => $card['cardType'] ?? null,
                'avsResultCode' => $response['avsResultCode'] ?? null,
                'avsResult' => $response['avsResult'] ?? null,
            ];
        }
    }
}
