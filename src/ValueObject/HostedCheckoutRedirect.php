<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class HostedCheckoutRedirect
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $lookupKey,
        public readonly string $mode
    ) {
    }
}
