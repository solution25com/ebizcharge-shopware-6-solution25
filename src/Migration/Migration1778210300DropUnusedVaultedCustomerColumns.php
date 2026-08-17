<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1778210300DropUnusedVaultedCustomerColumns extends MigrationStep
{
    private const TABLE_NAME = 'ebizcharge_vaulted_customer';

    public function getCreationTimestamp(): int
    {
        return 1778210300;
    }

    public function update(Connection $connection): void
    {
        $columnsToDrop = [
            'customer_token',
            'default_payment_method_id',
            'saved_cards_json',
        ];

        $dropStatements = [];

        foreach ($columnsToDrop as $columnName) {
            if ($this->hasColumn($connection, $columnName)) {
                $dropStatements[] = sprintf('DROP COLUMN `%s`', $columnName);
            }
        }

        if ($dropStatements === []) {
            return;
        }

        $connection->executeStatement(
            sprintf(
                'ALTER TABLE `%s` %s',
                self::TABLE_NAME,
                implode(', ', $dropStatements)
            )
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function hasColumn(Connection $connection, string $columnName): bool
    {
        return (bool) $connection->fetchOne(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :tableName
               AND COLUMN_NAME = :columnName',
            [
                'tableName' => self::TABLE_NAME,
                'columnName' => $columnName,
            ]
        );
    }
}
