<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class CheckoutOrderData
{
    /**
     * @param list<LineItemData> $lineItems
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $orderTransactionId,
        public readonly string $orderNumber,
        public readonly string $salesChannelId,
        public readonly bool $guest,
        public readonly ?string $customerId,
        public readonly ?string $customerNumber,
        public readonly ?string $customerEmail,
        public readonly string $customerFullName,
        public readonly \DateTimeImmutable $orderDate,
        public readonly string $currencyIso,
        public readonly float $amountDue,
        public readonly float $totalAmount,
        public readonly float $taxAmount,
        public readonly float $shippingAmount,
        public readonly float $dutyAmount,
        public readonly float $tipAmount,
        public readonly AddressData $billingAddress,
        public readonly ?AddressData $shippingAddress,
        public readonly array $lineItems
    ) {
    }
}
