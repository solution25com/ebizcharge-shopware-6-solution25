<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargePaymentTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class EbizchargePaymentTransactionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ebizcharge_payment_transaction';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return EbizchargePaymentTransactionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return EbizchargePaymentTransactionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new StringField('order_transaction_id', 'orderTransactionId'))->addFlags(new PrimaryKey(), new Required()),
            new StringField('order_id', 'orderId'),
            new StringField('order_number', 'orderNumber'),
            new StringField('lookup_key', 'lookupKey'),
            new StringField('mode', 'mode'),
            new StringField('normalized_state', 'normalizedState'),
            new StringField('provider_ref_num', 'providerRefNum'),
            new StringField('provider_auth_code', 'providerAuthCode'),
            new StringField('provider_payment_type', 'providerPaymentType'),
            new StringField('provider_payment_method', 'providerPaymentMethod'),
            new FloatField('amount_total', 'amountTotal'),
            new StringField('currency_iso', 'currencyIso'),
            new LongTextField('last_support_message', 'lastSupportMessage'),
            new DateTimeField('last_sync_at', 'lastSyncAt'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
