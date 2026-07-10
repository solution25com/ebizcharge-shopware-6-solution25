<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\LineItemData;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
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

                $stateCode = $address->getCountryState()?->getShortCode();

                if ($stateCode !== null) {
                    $parts = explode('-', $stateCode, 2);
                    $stateCode = $parts[1] ?? $parts[0];
                }

                $shippingAddress = new AddressData(
                    $address->getFirstName(),
                    $address->getLastName(),
                    $this->companyName($address->getCompany(), $address->getFirstName(), $address->getLastName()),
                    $address->getStreet(),
                    null,
                    $address->getCity(),
                    $this->requiredStateCode($stateCode),
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
                    ($lineItem->getPayload()['productNumber'] ?? null) ?: $lineItem->getIdentifier(),
                    $lineItem->getLabel(),
                    $lineItem->getLabel(),
                    $this->lineItemDiscountAmount($lineItem),
                    $this->lineItemUnitOfMeasure($lineItem),
                    $lineItem->getUnitPrice(),
                    (float) $lineItem->getQuantity(),
                    $this->lineItemTaxAmount($lineItem) > 0.0,
                    $this->lineItemTaxAmount($lineItem)
                );
            }
        }

        $customerFullName = trim(sprintf('%s %s', $orderCustomer->getFirstName(), $orderCustomer->getLastName()));
        if ($customerFullName === '') {
            $customerFullName = trim(sprintf('%s %s', $billingAddress->getFirstName(), $billingAddress->getLastName()));
        }

        $billingStateCode = $billingAddress->getCountryState()?->getShortCode();

        if ($billingStateCode !== null) {
            $parts = explode('-', $billingStateCode, 2);
            $billingStateCode = $parts[1] ?? $parts[0];
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
            $this->orderTaxAmount($order),
            $order->getShippingCosts()->getTotalPrice(),
            0.0,
            0.0,
            new AddressData(
                $billingAddress->getFirstName(),
                $billingAddress->getLastName(),
                $this->companyName($billingAddress->getCompany(), $billingAddress->getFirstName(), $billingAddress->getLastName()),
                $billingAddress->getStreet(),
                null,
                $billingAddress->getCity(),
                $this->requiredStateCode($billingStateCode),
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

    private function orderTaxAmount(OrderEntity $order): float
    {
        $taxes = $order->getPrice()->getCalculatedTaxes();

        $amount = 0.0;
        foreach ($taxes as $tax) {
            $amount += $tax->getTax();
        }

        return round($amount, 2);
    }

    private function lineItemDiscountAmount(OrderLineItemEntity $lineItem): float
    {
        $payload = $lineItem->getPayload() ?? [];
        foreach (['discountAmount', 'discount', 'lineItemDiscount'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_numeric($value)) {
                return round(abs((float) $value), 2);
            }
        }

        $listPrice = $lineItem->getPrice()?->getListPrice();
        if ($listPrice === null) {
            return $lineItem->getTotalPrice() < 0.0 ? round(abs($lineItem->getTotalPrice()), 2) : 0.0;
        }

        $grossDiscount = ($listPrice->getPrice() - $lineItem->getUnitPrice()) * max(1.0, (float) $lineItem->getQuantity());

        return round(max(0.0, $grossDiscount), 2);
    }

    private function lineItemTaxAmount(OrderLineItemEntity $lineItem): float
    {
        $taxes = $lineItem->getPrice()?->getCalculatedTaxes();
        if ($taxes === null) {
            return 0.0;
        }

        $amount = 0.0;
        foreach ($taxes as $tax) {
            $amount += $tax->getTax();
        }

        return round($amount, 2);
    }

    private function lineItemUnitOfMeasure(OrderLineItemEntity $lineItem): string
    {
        $payload = $lineItem->getPayload() ?? [];
        $customFields = $lineItem->getCustomFields() ?? [];

        foreach ([$payload, $customFields] as $source) {
            foreach (['unitOfMeasure', 'unit', 'unitName', 'purchaseUnit', 'packUnit'] as $key) {
                $value = $source[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return 'EA';
    }

    public function companyName(?string $companyName, ?string $firstName, ?string $lastName): string
    {
        $companyName = trim((string) $companyName);
        if ($companyName !== '') {
            return $companyName;
        }

        $fullName = trim(sprintf('%s %s', $firstName, $lastName));

        return $fullName !== '' ? $fullName : 'n/a';
    }

    public function requiredStateCode(?string $stateCode): string
    {
        $stateCode = trim((string) $stateCode);

        return $stateCode !== '' ? $stateCode : 'NA';
    }
}
