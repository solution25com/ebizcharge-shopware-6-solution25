<?php

declare(strict_types=1);

namespace EbizChargeShopware\Subscriber;

use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\AchPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\CreditCardPaymentHandler;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\Service\EbizChargeCustomerVaultService;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class StorefrontPaymentMethodSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EbizChargeCustomerVaultService $customerVaultService,
        private readonly PluginConfigProvider $configProvider,
        private readonly ConnectionHealthRegistry $connectionHealthRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            StorefrontRenderEvent::class => 'addSavedCardsVisibilityFlag',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->removePayByLinkFromCheckout($event);
        $this->addCheckoutExtension($event);
        $this->removeUnvalidatedEbizChargeMethodsFromCheckout($event);
        $this->addSavedCardsExtension($event);
    }

    private function addCheckoutExtension(CheckoutConfirmPageLoadedEvent $event): void
    {
        $config = $this->configProvider->get($event->getSalesChannelContext()->getSalesChannelId());

        $event->getPage()->addExtension('ebizchargeCheckout', new ArrayStruct([
            'flow' => $config->paymentFlow(),
        ]));
    }

    public function addSavedCardsVisibilityFlag(StorefrontRenderEvent $event): void
    {
        $event->setParameter(
            'ebizchargeSavedCardsAvailable',
            $this->isConnectionReady($event->getSalesChannelContext()->getSalesChannelId())
        );
    }

    private function removePayByLinkFromCheckout(CheckoutConfirmPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $page->setPaymentMethods(
            $page->getPaymentMethods()->filter(
                static fn (PaymentMethodEntity $m): bool => $m->getHandlerIdentifier()
                    !== PayByLinkPaymentHandler::class
            )
        );
    }

    private function addSavedCardsExtension(CheckoutConfirmPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        if (!$this->isConnectionReady($event->getSalesChannelContext()->getSalesChannelId())) {
            return;
        }

        $paymentHandler = $context->getPaymentMethod()->getHandlerIdentifier();
        if (!\in_array($paymentHandler, [CreditCardPaymentHandler::class, AchPaymentHandler::class], true)) {
            return;
        }

        $customer = $context->getCustomer();

        if ($customer === null || $customer->getGuest()) {
            return;
        }

        try {
            $customerVault = $this->customerVaultService->findUsableVaultForCustomerId(
                $customer->getId(),
                $context->getSalesChannelId(),
                $context->getContext()
            );

            if ($customerVault === null) {
                return;
            }

            $cards = $this->customerVaultService->getCardsForDisplay($customerVault, $context->getContext());
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not load EBizCharge saved payment methods for checkout.', [
                'customerId' => $customer->getId(),
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        if ($cards === []) {
            return;
        }

        $event->getPage()->addExtension('ebizchargeSavedCards', new ArrayStruct([
            'cards' => $cards,
        ]));
    }

    private function removeUnvalidatedEbizChargeMethodsFromCheckout(CheckoutConfirmPageLoadedEvent $event): void
    {
        if ($this->isConnectionReady($event->getSalesChannelContext()->getSalesChannelId())) {
            return;
        }

        $page = $event->getPage();
        $hiddenAnyEbizChargeMethod = false;
        foreach ($page->getPaymentMethods() as $paymentMethod) {
            if (
                \in_array(
                    $paymentMethod->getHandlerIdentifier(),
                    [
                        CreditCardPaymentHandler::class,
                        AchPaymentHandler::class,
                    ],
                    true
                )
            ) {
                $hiddenAnyEbizChargeMethod = true;
                break;
            }
        }

        $page->setPaymentMethods(
            $page->getPaymentMethods()->filter(
                static fn (PaymentMethodEntity $m): bool => !\in_array($m->getHandlerIdentifier(), [
                    CreditCardPaymentHandler::class,
                    AchPaymentHandler::class,
                ], true)
            )
        );

        if ($hiddenAnyEbizChargeMethod) {
            $page->addExtension('ebizchargeCheckoutConfigurationUnavailable', new ArrayStruct());
        }
    }

    private function isConnectionReady(?string $salesChannelId): bool
    {
        $config = $this->configProvider->get($salesChannelId);

        return $config->hasCompleteCredentials()
            && $this->connectionHealthRegistry->hasSuccessfulTest($config, $salesChannelId);
    }
}
