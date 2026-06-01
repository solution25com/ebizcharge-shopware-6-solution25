<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargeVaultedCustomer;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

final class EbizchargeVaultedCustomerDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'ebizcharge_vaulted_customer';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return EbizchargeVaultedCustomerEntity::class;
    }

    public function getCollectionClass(): string
    {
        return EbizchargeVaultedCustomerCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new Required()),
            (new StringField('merchant_customer_id', 'merchantCustomerId'))->addFlags(new Required()),
            (new StringField('customer_internal_id', 'customerInternalId'))->addFlags(new Required()),
            new StringField('gateway_token', 'ebizCustomerToken'),
            new LongTextField('saved_cards_json', 'savedCardsJson'),
            new StringField('default_method_id', 'defaultMethodId'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
