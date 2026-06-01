<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Request;

use EbizChargeShopware\Provider\ProviderContract;

final class ReturnUrlBuilder
{
    public function withOutcome(string $baseReturnUrl, string $outcome): string
    {
        $separator = str_contains($baseReturnUrl, '?') ? '&' : '?';

        return $baseReturnUrl . $separator . http_build_query([ProviderContract::BROWSER_RESULT_QUERY_PARAM => $outcome]);
    }
}
