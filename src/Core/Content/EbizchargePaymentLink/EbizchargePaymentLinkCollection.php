<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargePaymentLink;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<EbizchargePaymentLinkEntity>
 */
class EbizchargePaymentLinkCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return EbizchargePaymentLinkEntity::class;
    }
}
