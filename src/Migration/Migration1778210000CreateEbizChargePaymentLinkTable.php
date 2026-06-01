<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1778210000CreateEbizChargePaymentLinkTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1778210000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `ebizcharge_payment_link` (
                `order_transaction_id` BINARY(16) NOT NULL,
                `order_id`             BINARY(16) NOT NULL,
                `link`                 LONGTEXT   NOT NULL,
                `created_at`           DATETIME(3) NOT NULL,
                `updated_at`           DATETIME(3) DEFAULT NULL,
                PRIMARY KEY (`order_transaction_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
