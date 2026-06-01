<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1778210200MakePaymentTransactionUpdatedAtNullable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1778210200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'ALTER TABLE `ebizcharge_payment_transaction`
                MODIFY COLUMN `updated_at` DATETIME(3) DEFAULT NULL;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
