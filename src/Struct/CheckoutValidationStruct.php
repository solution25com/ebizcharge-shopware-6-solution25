<?php

declare(strict_types=1);

namespace EbizChargeShopware\Struct;

use Shopware\Core\Framework\Struct\Struct;

class CheckoutValidationStruct extends Struct
{
    public function __construct(
        private readonly string $salesChannelId,
        private readonly string $processingCommand
    ) {
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function getProcessingCommand(): string
    {
        return $this->processingCommand;
    }
}
