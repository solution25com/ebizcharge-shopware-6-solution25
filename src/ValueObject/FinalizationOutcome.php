<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class FinalizationOutcome
{
    public function __construct(
        public readonly ProviderOperationResult $result,
        public readonly string $targetShopwareState,
        public readonly bool $throwInterrupted,
        public readonly bool $throwCustomerCancelled
    ) {
    }
}
