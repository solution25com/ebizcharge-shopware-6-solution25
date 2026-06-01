<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class AddressData
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $companyName,
        public readonly string $address1,
        public readonly ?string $address2,
        public readonly string $city,
        public readonly ?string $state,
        public readonly string $zipCode,
        public readonly ?string $country
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toProviderArray(): array
    {
        return array_filter([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'companyName' => $this->companyName,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'zipCode' => $this->zipCode,
            'country' => $this->country,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
