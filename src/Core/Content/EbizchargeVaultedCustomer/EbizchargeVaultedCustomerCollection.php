<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargeVaultedCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<EbizchargeVaultedCustomerEntity>
 */
class EbizchargeVaultedCustomerCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return EbizchargeVaultedCustomerEntity::class;
    }
}
