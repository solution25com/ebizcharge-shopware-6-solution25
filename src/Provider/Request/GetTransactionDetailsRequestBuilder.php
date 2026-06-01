<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Request;

final class GetTransactionDetailsRequestBuilder
{
    /**
     * @return array<string, string>
     */
    public function build(string $referenceNumber): array
    {
        return [
            'transactionRefNum' => $referenceNumber,
        ];
    }
}
