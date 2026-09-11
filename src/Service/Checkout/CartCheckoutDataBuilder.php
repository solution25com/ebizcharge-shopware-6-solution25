<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Checkout;

use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\LineItemData;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class CartCheckoutDataBuilder
{
    public function __construct(private readonly OrderTransactionLoader $orderTransactionLoader)
    {
    }

    public function build(Cart $cart, SalesChannelContext $context, string $lookupKey): CheckoutOrderData
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            throw PaymentException::asyncProcessInterrupted($lookupKey, 'A customer context is required for embedded checkout.');
        }

        $billing = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
        if ($billing === null) {
            throw PaymentException::asyncProcessInterrupted($lookupKey, 'A billing address is required for embedded checkout.');
        }

        $cartToken = $cart->getToken();
        $reference = 'CART-' . strtoupper(substr($cartToken, 0, 12));
        $customerFullName = trim(sprintf('%s %s', $customer->getFirstName(), $customer->getLastName()));
        if ($customerFullName === '') {
            $customerFullName = trim(sprintf('%s %s', $billing->getFirstName(), $billing->getLastName()));
        }

        return new CheckoutOrderData(
            $cartToken,
            $lookupKey,
            $reference,
            $context->getSalesChannelId(),
            (bool) $customer->getGuest(),
            $customer->getGuest() ? null : $customer->getId(),
            $customer->getCustomerNumber(),
            $customer->getEmail(),
            $customerFullName,
            new \DateTimeImmutable('now'),
            $context->getCurrency()->getIsoCode(),
            $cart->getPrice()->getTotalPrice(),
            $cart->getPrice()->getTotalPrice(),
            $this->cartTaxAmount($cart),
            $this->cartShippingAmount($cart),
            0.0,
            0.0,
            $this->address($billing),
            $this->shippingAddress($context),
            $this->lineItems($cart)
        );
    }

    private function address(CustomerAddressEntity $address): AddressData
    {
        return new AddressData(
            $address->getFirstName(),
            $address->getLastName(),
            $this->orderTransactionLoader->companyName($address->getCompany(), $address->getFirstName(), $address->getLastName()),
            $address->getStreet(),
            null,
            $address->getCity(),
            $this->orderTransactionLoader->requiredStateCode($this->normalizeStateCode($address->getCountryState()?->getShortCode())),
            $address->getZipcode() ?? '00000',
            $address->getCountry()?->getIso() ?? 'US'
        );
    }

    private function shippingAddress(SalesChannelContext $context): ?AddressData
    {
        $shipping = $context->getCustomer()?->getActiveShippingAddress();

        return $shipping === null ? null : $this->address($shipping);
    }

    private function normalizeStateCode(?string $stateCode): ?string
    {
        if ($stateCode === null) {
            return null;
        }

        $parts = explode('-', $stateCode, 2);

        return $parts[1] ?? $parts[0];
    }

    /**
     * @return list<LineItemData>
     */
    private function lineItems(Cart $cart): array
    {
        $lineItems = [];
        foreach ($cart->getLineItems() as $lineItem) {
            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            $taxAmount = $this->lineItemTaxAmount($lineItem);
            $lineItems[] = new LineItemData(
                ($lineItem->getPayload()['productNumber'] ?? null) ?: ($lineItem->getReferencedId() ?? $lineItem->getId()),
                (string) $lineItem->getLabel(),
                (string) $lineItem->getLabel(),
                0.0,
                'EA',
                $price->getUnitPrice(),
                (float) $lineItem->getQuantity(),
                $taxAmount > 0.0,
                $taxAmount
            );
        }

        return $lineItems;
    }

    private function lineItemTaxAmount(LineItem $lineItem): float
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

    private function cartTaxAmount(Cart $cart): float
    {
        $amount = 0.0;
        foreach ($cart->getPrice()->getCalculatedTaxes() as $tax) {
            $amount += $tax->getTax();
        }

        return round($amount, 2);
    }

    private function cartShippingAmount(Cart $cart): float
    {
        $amount = 0.0;
        foreach ($cart->getDeliveries() as $delivery) {
            $amount += $delivery->getShippingCosts()->getTotalPrice();
        }

        return round($amount, 2);
    }
}
