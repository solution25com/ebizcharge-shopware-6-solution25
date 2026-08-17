<?php

declare(strict_types=1);

namespace EbizChargeShopware\Subscriber;

use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\AchPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\CreditCardPaymentHandler;
use EbizChargeShopware\Service\EbizChargeCustomerVaultService;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class StorefrontPaymentMethodSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EbizChargeCustomerVaultService $customerVaultService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->removePayByLinkFromCheckout($event);
        $this->addSavedCardsExtension($event);
    }

    private function removePayByLinkFromCheckout(CheckoutConfirmPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $page->setPaymentMethods(
            $page->getPaymentMethods()->filter(
                static fn (PaymentMethodEntity $m): bool => $m->getHandlerIdentifier() !== PayByLinkPaymentHandler::class
            )
        );
    }

    private function addSavedCardsExtension(CheckoutConfirmPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $paymentHandler = $context->getPaymentMethod()->getHandlerIdentifier();
        if (!\in_array($paymentHandler, [CreditCardPaymentHandler::class, AchPaymentHandler::class], true)) {
            return;
        }

        $customer = $context->getCustomer();

        if ($customer === null || $customer->getGuest()) {
            return;
        }

        try {
            $customerVault = $this->customerVaultService->findUsableVaultForCustomerId($customer->getId(), $context->getSalesChannelId(), $context->getContext());

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
}
