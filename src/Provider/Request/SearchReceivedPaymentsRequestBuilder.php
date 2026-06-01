<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Request;

use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\PluginConfig;

final class SearchReceivedPaymentsRequestBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $lookupKey, CheckoutOrderData $orderData, PluginConfig $config, string $formType = 'Webform'): array
    {
        $from = $orderData->orderDate->modify(sprintf('-%d days', $config->verificationLookbackDays()));
        $to = (new \DateTimeImmutable('now'))->modify('+1 day');

        return [
            'customerId' => '',
            'fromPaymentRequestDateTime' => $from->format('Y-m-d'),
            'toPaymentRequestDateTime' => $to->format('Y-m-d'),
            'filters' => [
                [
                    'fieldName' => 'TransactionLookupKey',
                    'comparisonOperator' => 'eq',
                    'fieldValue' => $lookupKey,
                ],
                [
                    'fieldName' => 'FormType',
                    'comparisonOperator' => 'eq',
                    'fieldValue' => $formType,
                ],
            ],
            'start' => 0,
            'limit' => 25,
            'sort' => '',
        ];
    }
}
