<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargePaymentTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<EbizchargePaymentTransactionEntity>
 */
class EbizchargePaymentTransactionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return EbizchargePaymentTransactionEntity::class;
    }
}
