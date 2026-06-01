<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\LineItemData;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final class OrderTransactionLoader
{
    public function __construct(private readonly EntityRepository $orderTransactionRepository)
    {
    }

    public function load(string $orderTransactionId, Context $context): CheckoutOrderData
    {
        $criteria = (new Criteria([$orderTransactionId]))
            ->addAssociation('order.currency')
            ->addAssociation('order.orderCustomer')
            ->addAssociation('order.billingAddress.country')
            ->addAssociation('order.billingAddress.countryState')
            ->addAssociation('order.deliveries.shippingOrderAddress.country')
            ->addAssociation('order.deliveries.shippingOrderAddress.countryState')
            ->addAssociation('order.lineItems')
            ->addAssociation('stateMachineState');

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($orderTransaction === null || $orderTransaction->getOrder() === null) {
            throw PaymentException::invalidTransaction($orderTransactionId);
        }

        $order = $orderTransaction->getOrder();
        $transactionAmount = $orderTransaction->getAmount()->getTotalPrice();
        $orderCustomer = $order->getOrderCustomer();
        $billingAddress = $order->getBillingAddress();

        if ($orderCustomer === null || $billingAddress === null) {
            throw PaymentException::invalidOrder($order->getId());
        }

        $shippingAddress = null;
        $deliveries = $order->getDeliveries();
        if ($deliveries !== null && $deliveries->count() > 0) {
            $firstDelivery = $deliveries->first();
            if ($firstDelivery !== null && $firstDelivery->getShippingOrderAddress() !== null) {
                $address = $firstDelivery->getShippingOrderAddress();
                $shippingAddress = new AddressData(
                    $address->getFirstName(),
                    $address->getLastName(),
                    $address->getCompany(),
                    $address->getStreet(),
                    null,
                    $address->getCity(),
                    $address->getCountryState()?->getShortCode(),
                    $address->getZipcode(),
                    $address->getCountry()?->getIso()
                );
            }
        }

        $lineItems = [];
        $orderLineItems = $order->getLineItems();
        if ($orderLineItems !== null) {
            foreach ($orderLineItems as $lineItem) {
                $lineItems[] = new LineItemData(
                    $lineItem->getIdentifier(),
                    $lineItem->getLabel(),
                    $lineItem->getLabel(),
                    0.0,
                    'EA',
                    $lineItem->getUnitPrice(),
                    (float) $lineItem->getQuantity(),
                    true,
                    0.0
                );
            }
        }

        $customerFullName = trim(sprintf('%s %s', $orderCustomer->getFirstName(), $orderCustomer->getLastName()));
        if ($customerFullName === '') {
            $customerFullName = trim(sprintf('%s %s', $billingAddress->getFirstName(), $billingAddress->getLastName()));
        }

        return new CheckoutOrderData(
            $order->getId(),
            $orderTransactionId,
            (string) $order->getOrderNumber(),
            (string) $order->getSalesChannelId(),
            $orderCustomer->getCustomerId() === null,
            $orderCustomer->getCustomerId(),
            $orderCustomer->getCustomerNumber(),
            $orderCustomer->getEmail(),
            $customerFullName,
            $this->normalizeOrderDate($order->getCreatedAt()),
            $order->getCurrency()?->getIsoCode() ?? 'USD',
            $transactionAmount,
            $order->getAmountTotal(),
            0.0,
            $order->getShippingCosts()->getTotalPrice(),
            0.0,
            0.0,
            new AddressData(
                $billingAddress->getFirstName(),
                $billingAddress->getLastName(),
                $billingAddress->getCompany(),
                $billingAddress->getStreet(),
                null,
                $billingAddress->getCity(),
                $billingAddress->getCountryState()?->getShortCode(),
                $billingAddress->getZipcode(),
                $billingAddress->getCountry()?->getIso()
            ),
            $shippingAddress,
            $lineItems
        );
    }

    private function normalizeOrderDate(\DateTimeInterface|null $orderDate): \DateTimeImmutable
    {
        if ($orderDate === null) {
            return new \DateTimeImmutable('now');
        }

        if ($orderDate instanceof \DateTimeImmutable) {
            return $orderDate;
        }

        return new \DateTimeImmutable($orderDate->format(\DateTimeInterface::ATOM));
    }
}
