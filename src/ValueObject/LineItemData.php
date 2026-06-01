<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class LineItemData
{
    public function __construct(
        public readonly string $sku,
        public readonly string $productName,
        public readonly string $description,
        public readonly float $discountAmount,
        public readonly string $unitOfMeasure,
        public readonly float $unitPrice,
        public readonly float $qty,
        public readonly bool $taxable,
        public readonly float $taxAmount
    ) {
    }

    /**
     * @return array<string, float|bool|string>
     */
    public function toProviderArray(): array
    {
        return [
            'sKU' => $this->sku,
            'productName' => $this->productName,
            'description' => $this->description,
            'discountAmount' => $this->discountAmount,
            'unitOfMeasure' => $this->unitOfMeasure,
            'unitPrice' => $this->unitPrice,
            'qty' => $this->qty,
            'taxable' => $this->taxable,
            'taxAmount' => $this->taxAmount,
        ];
    }
}
