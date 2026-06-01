<?php

declare(strict_types=1);

namespace EbizChargeShopware\Subscriber;

use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class StorefrontPaymentMethodSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'removePayByLinkFromCheckout',
        ];
    }

    public function removePayByLinkFromCheckout(CheckoutConfirmPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $page->setPaymentMethods(
            $page->getPaymentMethods()->filter(
                static fn (PaymentMethodEntity $m): bool => $m->getHandlerIdentifier() !== PayByLinkPaymentHandler::class
            )
        );
    }
}
