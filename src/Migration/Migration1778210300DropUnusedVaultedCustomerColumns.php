<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1778210300DropUnusedVaultedCustomerColumns extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1778210300;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'ALTER TABLE `ebizcharge_vaulted_customer`
                DROP COLUMN IF EXISTS `customer_token`,
                DROP COLUMN IF EXISTS `default_payment_method_id`,
                DROP COLUMN IF EXISTS `saved_cards_json`;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
