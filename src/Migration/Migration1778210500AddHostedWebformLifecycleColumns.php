<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1778210500AddHostedWebformLifecycleColumns extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1778210500;
    }

    public function update(Connection $connection): void
    {
        $columns = array_column($connection->fetchAllAssociative('SHOW COLUMNS FROM `ebizcharge_payment_transaction`'), 'Field');

        if (!\in_array('sales_channel_id', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `ebizcharge_payment_transaction`
                    ADD COLUMN `sales_channel_id` VARCHAR(64) DEFAULT NULL AFTER `order_id`;'
            );
        }

        if (!\in_array('active_payment_internal_id', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `ebizcharge_payment_transaction`
                    ADD COLUMN `active_payment_internal_id` VARCHAR(128) DEFAULT NULL AFTER `provider_payment_method`;'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
