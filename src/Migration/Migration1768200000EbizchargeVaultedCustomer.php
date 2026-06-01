<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Replaces the earlier two-table vault with a single NMI-style vaulted customer row + JSON cards.
 */
final class Migration1768200000EbizchargeVaultedCustomer extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1768200000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `ebizcharge_saved_payment_method`');
        $connection->executeStatement('DROP TABLE IF EXISTS `ebizcharge_customer_vault`');

        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `ebizcharge_vaulted_customer` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `merchant_customer_id` VARCHAR(64) NOT NULL,
                `customer_internal_id` VARCHAR(128) NOT NULL,
                `gateway_token` VARCHAR(64) DEFAULT NULL,
                `saved_cards_json` LONGTEXT DEFAULT NULL,
                `default_method_id` VARCHAR(32) DEFAULT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.ebizcharge_vaulted_customer.customer_sc` (`customer_id`, `sales_channel_id`),
                CONSTRAINT `fk.ebizcharge_vaulted_customer.customer` FOREIGN KEY (`customer_id`)
                    REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.ebizcharge_vaulted_customer.sales_channel` FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
