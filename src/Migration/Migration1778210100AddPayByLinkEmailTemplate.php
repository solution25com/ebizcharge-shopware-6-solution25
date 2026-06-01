<?php

declare(strict_types=1);

namespace EbizChargeShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration1778210100AddPayByLinkEmailTemplate extends MigrationStep
{
    private const TECHNICAL_NAME = 'ebizcharge.admin.payment_link';

    public function getCreationTimestamp(): int
    {
        return 1778210100;
    }

    public function update(Connection $connection): void
    {
        $mailTemplateTypeId = $this->ensureMailTemplateType($connection);
        $this->ensureMailTemplate($connection, $mailTemplateTypeId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function ensureMailTemplateType(Connection $connection): string
    {
        $existing = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => self::TECHNICAL_NAME]
        );

        if ($existing !== false) {
            return bin2hex((string) $existing);
        }

        $id = Uuid::randomHex();
        $systemLangId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $connection->insert('mail_template_type', [
            'id'                 => Uuid::fromHexToBytes($id),
            'technical_name'     => self::TECHNICAL_NAME,
            'available_entities' => json_encode(['salesChannel' => 'sales_channel']),
            'created_at'         => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $this->upsertTypeTranslation($connection, $id, $systemLangId, 'eBizCharge Admin Payment Link');

        $enId = $this->languageIdByLocale($connection, 'en-GB');
        if ($enId !== null && $enId !== $systemLangId) {
            $this->upsertTypeTranslation($connection, $id, $enId, 'eBizCharge Admin Payment Link');
        }

        $deId = $this->languageIdByLocale($connection, 'de-DE');
        if ($deId !== null) {
            $this->upsertTypeTranslation($connection, $id, $deId, 'eBizCharge Admin Zahlungslink');
        }

        return $id;
    }

    private function ensureMailTemplate(Connection $connection, string $mailTemplateTypeId): void
    {
        $existing = $connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId',
            ['typeId' => Uuid::fromHexToBytes($mailTemplateTypeId)]
        );

        if ($existing !== false) {
            return;
        }

        $id = Uuid::randomHex();
        $systemLangId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $connection->insert('mail_template', [
            'id'                   => Uuid::fromHexToBytes($id),
            'mail_template_type_id' => Uuid::fromHexToBytes($mailTemplateTypeId),
            'system_default'       => true,
            'created_at'           => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $this->upsertTemplateTranslation($connection, $id, $systemLangId, 'en');

        $enId = $this->languageIdByLocale($connection, 'en-GB');
        if ($enId !== null && $enId !== $systemLangId) {
            $this->upsertTemplateTranslation($connection, $id, $enId, 'en');
        }

        $deId = $this->languageIdByLocale($connection, 'de-DE');
        if ($deId !== null) {
            $this->upsertTemplateTranslation($connection, $id, $deId, 'de');
        }
    }

    private function languageIdByLocale(Connection $connection, string $locale): ?string
    {
        $id = $connection->fetchOne(
            'SELECT l.id FROM language l
             INNER JOIN locale loc ON loc.id = l.locale_id
             WHERE loc.code = :code',
            ['code' => $locale]
        );

        return $id !== false ? $id : null;
    }

    private function upsertTypeTranslation(Connection $connection, string $typeId, string $langId, string $name): void
    {
        $connection->executeStatement(
            'INSERT IGNORE INTO mail_template_type_translation
                (mail_template_type_id, language_id, name, created_at)
             VALUES (:typeId, :langId, :name, :now)',
            [
                'typeId' => Uuid::fromHexToBytes($typeId),
                'langId' => $langId,
                'name'   => $name,
                'now'    => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );
    }

    private function upsertTemplateTranslation(Connection $connection, string $tplId, string $langId, string $lang): void
    {
        $connection->executeStatement(
            'INSERT IGNORE INTO mail_template_translation
                (mail_template_id, language_id, sender_name, subject, content_html, content_plain, created_at)
             VALUES (:tplId, :langId, :sender, :subject, :html, :plain, :now)',
            [
                'tplId'   => Uuid::fromHexToBytes($tplId),
                'langId'  => $langId,
                'sender'  => 'eBizCharge',
                'subject' => $lang === 'de'
                    ? 'Zahlungslink fuer Ihre Bestellung #{{ orderNumber }}'
                    : 'Payment link for your order #{{ orderNumber }}',
                'html'    => $lang === 'de' ? $this->htmlDe() : $this->htmlEn(),
                'plain'   => $lang === 'de' ? $this->plainDe() : $this->plainEn(),
                'now'     => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );
    }

    private function htmlEn(): string
    {
        return '<div style="font-family:arial;font-size:12px;">'
            . '<p>Dear {{ firstName }} {{ lastName }},<br/><br/>'
            . 'Please use the secure link below to pay for order #{{ orderNumber }}.<br/><br/>'
            . '<a href="{{ paymentLink }}">Pay now</a><br/><br/>'
            . 'Thank you.</p></div>';
    }

    private function plainEn(): string
    {
        return "Dear {{ firstName }} {{ lastName }},\n\n"
            . "Please use this link to pay for order #{{ orderNumber }}:\n\n"
            . '{{ paymentLink }}';
    }

    private function htmlDe(): string
    {
        return '<div style="font-family:arial;font-size:12px;">'
            . '<p>Sehr geehrte/r {{ firstName }} {{ lastName }},<br/><br/>'
            . 'Bitte verwenden Sie den sicheren Zahlungslink fuer Bestellung #{{ orderNumber }}.<br/><br/>'
            . '<a href="{{ paymentLink }}">Jetzt bezahlen</a><br/><br/>'
            . 'Vielen Dank.</p></div>';
    }

    private function plainDe(): string
    {
        return "Sehr geehrte/r {{ firstName }} {{ lastName }},\n\n"
            . "Zahlungslink fuer Bestellung #{{ orderNumber }}:\n\n"
            . '{{ paymentLink }}';
    }
}
