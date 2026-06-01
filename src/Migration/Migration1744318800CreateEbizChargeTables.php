<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1744318800CreateEbizChargeTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1744318800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `ebizcharge_payment_transaction` (
                `order_transaction_id` CHAR(32) NOT NULL,
                `order_id` CHAR(32) DEFAULT NULL,
                `order_number` VARCHAR(64) DEFAULT NULL,
                `lookup_key` VARCHAR(64) DEFAULT NULL,
                `mode` VARCHAR(32) DEFAULT NULL,
                `normalized_state` VARCHAR(32) DEFAULT NULL,
                `provider_ref_num` VARCHAR(64) DEFAULT NULL,
                `provider_auth_code` VARCHAR(64) DEFAULT NULL,
                `provider_payment_type` VARCHAR(64) DEFAULT NULL,
                `provider_payment_method` VARCHAR(64) DEFAULT NULL,
                `amount_total` DECIMAL(20,4) DEFAULT NULL,
                `currency_iso` VARCHAR(8) DEFAULT NULL,
                `last_support_message` TEXT DEFAULT NULL,
                `last_sync_at` DATETIME(3) DEFAULT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`order_transaction_id`),
                UNIQUE KEY `uniq.ebizcharge_payment_transaction.lookup_key` (`lookup_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
