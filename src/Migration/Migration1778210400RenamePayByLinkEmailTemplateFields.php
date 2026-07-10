<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1778210400RenamePayByLinkEmailTemplateFields extends MigrationStep
{
    private const TECHNICAL_NAME = 'ebizcharge.admin.payment_link';

    public function getCreationTimestamp(): int
    {
        return 1778210400;
    }

    public function update(Connection $connection): void
    {
        $this->renameTemplateTypeLabels($connection);
        $this->renameTemplateSender($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function renameTemplateTypeLabels(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE mail_template_type_translation mtt
             INNER JOIN mail_template_type mttype ON mttype.id = mtt.mail_template_type_id
             SET mtt.name = :newName,
                 mtt.updated_at = NOW(3)
             WHERE mttype.technical_name = :technicalName
               AND mtt.name = :oldName',
            [
                'technicalName' => self::TECHNICAL_NAME,
                'oldName' => 'eBizCharge Admin Payment Link',
                'newName' => 'EBizCharge Admin Payment Link',
            ]
        );

        $connection->executeStatement(
            'UPDATE mail_template_type_translation mtt
             INNER JOIN mail_template_type mttype ON mttype.id = mtt.mail_template_type_id
             SET mtt.name = :newName,
                 mtt.updated_at = NOW(3)
             WHERE mttype.technical_name = :technicalName
               AND mtt.name = :oldName',
            [
                'technicalName' => self::TECHNICAL_NAME,
                'oldName' => 'eBizCharge Admin Zahlungslink',
                'newName' => 'EBizCharge Admin Zahlungslink',
            ]
        );
    }

    private function renameTemplateSender(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE mail_template_translation mtrans
             INNER JOIN mail_template mt ON mt.id = mtrans.mail_template_id
             INNER JOIN mail_template_type mttype ON mttype.id = mt.mail_template_type_id
             SET mtrans.sender_name = :newSender,
                 mtrans.updated_at = NOW(3)
             WHERE mttype.technical_name = :technicalName
               AND mtrans.sender_name = :oldSender',
            [
                'technicalName' => self::TECHNICAL_NAME,
                'oldSender' => 'eBizCharge',
                'newSender' => 'EBizCharge',
            ]
        );
    }
}
