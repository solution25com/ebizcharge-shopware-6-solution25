<?php

declare(strict_types=1);

namespace EbizChargeShopware\Checkout\Payment\Handler;

use EbizChargeShopware\Service\Checkout\HostedCheckoutService;
use EbizChargeShopware\Service\Checkout\OrderTransactionLoader;
use EbizChargeShopware\Service\EbizChargeApiClient;
use EbizChargeShopware\Service\EbizChargeCustomerVaultService;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\Service\Finalize\FinalizationService;
use EbizChargeShopware\Service\StateSync\TransactionStateSyncService;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\Struct\CheckoutValidationStruct;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\PluginConfig;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class CreditCardPaymentHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly PluginConfigProvider $pluginConfigProvider,
        private readonly ConnectionHealthRegistry $connectionHealthRegistry,
        private readonly OrderTransactionLoader $orderTransactionLoader,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly EbizChargeCustomerVaultService $customerVaultService,
        private readonly EbizChargeApiClient $apiClient,
        private readonly FinalizationService $finalizationService,
        private readonly TransactionStateSyncService $transactionStateSyncService,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return !\in_array($type, [PaymentHandlerType::RECURRING, PaymentHandlerType::REFUND], true);
    }

    public function validate(Cart $cart, RequestDataBag $dataBag, SalesChannelContext $context): Struct
    {
        $config = $this->pluginConfigProvider->get($context->getSalesChannelId());
        $config->assertComplete();
        $this->connectionHealthRegistry->requireSuccessfulTest($config, $context->getSalesChannelId());

        return new CheckoutValidationStruct($context->getSalesChannelId(), $config->processingCommand());
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $orderData = $this->orderTransactionLoader->load($transaction->getOrderTransactionId(), $context);
        $config = $this->pluginConfigProvider->get($orderData->salesChannelId);
        $config->assertComplete();
        $this->connectionHealthRegistry->requireSuccessfulTest($config, $orderData->salesChannelId);

        if ($orderData->amountDue <= 0.0) {
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'The Shopware order transaction amount must be greater than zero.'
            );
        }

        if ($transaction->getReturnUrl() === null) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'Missing Shopware return URL.');
        }

        $savedMethodId = $this->selectedSavedMethodId($request);
        if ($savedMethodId !== null) {
            $savedCardResult = $this->chargeSavedMethod($request, $savedMethodId, $orderData, $config, $context);
            $result = $savedCardResult['operationResult'];
            $this->storeVerificationResponse(
                $transaction->getOrderTransactionId(),
                $savedCardResult['verificationResponse'],
                $context
            );
            $this->transactionStateSyncService->apply($transaction->getOrderTransactionId(), $result, $context);

            if ($result->outcome !== ProviderOperationResult::OUTCOME_APPROVED) {
                throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), $result->supportMessage);
            }

            return null;
        }

        $savePaymentMethod = false;
        $showSavedPaymentMethods = false;

        $redirect = $this->hostedCheckoutService->start($orderData, $config, $transaction->getReturnUrl(), $context, $savePaymentMethod, $showSavedPaymentMethods, ProviderContract::WEBFORM_TYPE, $this->payByType());

        $this->transactionStateSyncService->apply(
            $transaction->getOrderTransactionId(),
            \EbizChargeShopware\ValueObject\ProviderOperationResult::pending(
                $config->processingCommand(),
                'Hosted checkout initiated.',
                true,
                'checkout_redirected'
            ),
            $context
        );

        $this->logger->info('Redirecting shopper to hosted EBizCharge webform.', [
            'orderTransactionId' => $transaction->getOrderTransactionId(),
            'mode' => $redirect->mode,
        ]);

        return new RedirectResponse($redirect->redirectUrl);
    }

    /**
     * @return array{operationResult: ProviderOperationResult, verificationResponse: array<string, mixed>}
     */
    private function chargeSavedMethod(
        Request $request,
        string $savedMethodId,
        CheckoutOrderData $orderData,
        PluginConfig $config,
        Context $context
    ): array {
        if ($orderData->guest || $orderData->customerId === null) {
            throw PaymentException::asyncProcessInterrupted($orderData->orderTransactionId, 'Saved-payment-method checkout requires a registered customer.');
        }

        $customerVault = $this->customerVaultService->findVaultForCustomerId($orderData->customerId, $orderData->salesChannelId, $context);
        if ($customerVault === null || $customerVault->getEbizCustomerToken() === null || $customerVault->getEbizCustomerToken() === '') {
            throw PaymentException::asyncProcessInterrupted($orderData->orderTransactionId, 'No EBizCharge customer vault was found for this customer.');
        }

        $savedMethods = $this->customerVaultService->fetchSavedPaymentMethods($customerVault, $context);
        $selectedMethod = null;
        foreach ($savedMethods as $method) {
            if (hash_equals((string) ($method['methodId'] ?? ''), $savedMethodId)) {
                $selectedMethod = $method;
                break;
            }
        }

        if ($selectedMethod === null) {
            throw PaymentException::asyncProcessInterrupted($orderData->orderTransactionId, 'The selected saved payment method is not available.');
        }

        $this->storeSavedCardCheckoutMetadata($orderData, $config, $context);
        $cardCode = !empty($selectedMethod['requiresCardCode'])
            ? $this->cardCode($request, $orderData->orderTransactionId)
            : null;

        return $this->apiClient->chargeSavedCustomerMethod(
            $orderData,
            $config,
            (string) $customerVault->getEbizCustomerToken(),
            $savedMethodId,
            $cardCode
        );
    }

    private function storeSavedCardCheckoutMetadata(CheckoutOrderData $orderData, PluginConfig $config, Context $context): void
    {
        $this->transactionRecordStore->upsert($orderData->orderTransactionId, [
            'order_id' => $orderData->orderId,
            'order_number' => $orderData->orderNumber,
            'lookup_key' => $orderData->orderTransactionId,
            'mode' => $config->processingCommand(),
            'normalized_state' => 'in_progress',
            'amount_total' => $orderData->amountDue,
            'currency_iso' => $orderData->currencyIso,
            'last_support_message' => 'Saved-payment-method checkout initiated.',
            'last_sync_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], $context);
    }

    /**
     * @param array<string, mixed> $verificationResponse
     */
    private function storeVerificationResponse(string $orderTransactionId, array $verificationResponse, Context $context): void
    {
        $criteria = new Criteria([$orderTransactionId]);

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        $customFields = $transaction?->getCustomFields() ?? [];
        $customFields['ebizcharge_verification_response'] = $verificationResponse;

        $this->orderTransactionRepository->update([
            [
                'id' => $orderTransactionId,
                'customFields' => $customFields,
            ],
        ], $context);
    }

    private function selectedSavedMethodId(Request $request): ?string
    {
        $value = $request->request->get('ebizchargeSavedMethodId');
        if (!\is_scalar($value)) {
            return null;
        }

        $methodId = trim((string) $value);

        return $methodId === '' ? null : $methodId;
    }

    private function cardCode(Request $request, string $orderTransactionId): ?string
    {
        $value = $request->request->get('ebizchargeCardCode');
        if (!\is_scalar($value)) {
            return null;
        }

        $cardCode = trim((string) $value);
        if ($cardCode === '') {
            return null;
        }

        if (!preg_match('/^(?:\d{3,4}|-2|-9)$/', $cardCode)) {
            throw PaymentException::asyncProcessInterrupted($orderTransactionId, 'The EBizCharge card security code is invalid.');
        }

        return $cardCode;
    }

    protected function payByType(): string
    {
        return ProviderContract::PAY_BY_TYPE_CREDIT_CARD;
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $orderData = $this->orderTransactionLoader->load($transaction->getOrderTransactionId(), $context);
        $config = $this->pluginConfigProvider->get($orderData->salesChannelId);
        $outcome = $this->finalizationService->finalize($request, $orderData, $config, $context);

        if ($outcome->throwCustomerCancelled) {
            throw PaymentException::customerCanceled(
                $transaction->getOrderTransactionId(),
                $outcome->result->supportMessage
            );
        }

        if ($outcome->throwInterrupted) {
            throw PaymentException::asyncFinalizeInterrupted(
                $transaction->getOrderTransactionId(),
                $outcome->result->supportMessage
            );
        }
    }
}
