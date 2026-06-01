<?php

declare(strict_types=1);

namespace EbizChargeShopware\Checkout\Payment\Handler;

use EbizChargeShopware\Service\Checkout\HostedCheckoutService;
use EbizChargeShopware\Service\Checkout\OrderTransactionLoader;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\Service\Finalize\FinalizationService;
use EbizChargeShopware\Service\StateSync\TransactionStateSyncService;
use EbizChargeShopware\Struct\CheckoutValidationStruct;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class CreditCardPaymentHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly PluginConfigProvider $pluginConfigProvider,
        private readonly ConnectionHealthRegistry $connectionHealthRegistry,
        private readonly OrderTransactionLoader $orderTransactionLoader,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly FinalizationService $finalizationService,
        private readonly TransactionStateSyncService $transactionStateSyncService,
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
    ): RedirectResponse {
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

        $savePaymentMethod = false;
        $showSavedPaymentMethods = !$orderData->guest && $orderData->customerId !== null;

        $redirect = $this->hostedCheckoutService->start($orderData, $config, $transaction->getReturnUrl(), $context, $savePaymentMethod, $showSavedPaymentMethods);

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

        $this->logger->info('Redirecting shopper to hosted eBizCharge webform.', [
            'orderTransactionId' => $transaction->getOrderTransactionId(),
            'mode' => $redirect->mode,
        ]);

        return new RedirectResponse($redirect->redirectUrl);
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
