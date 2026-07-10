<?php

declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Provider\Request;

use EbizChargeShopware\Provider\Request\GetEbizWebFormUrlRequestBuilder;
use EbizChargeShopware\Provider\Request\ReturnUrlBuilder;
use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\LineItemData;
use EbizChargeShopware\ValueObject\PluginConfig;
use PHPUnit\Framework\TestCase;

final class GetEbizWebFormUrlRequestBuilderTest extends TestCase
{
    public function testBuildsHostedPaymentMethodPayloadWithoutForbiddenFields(): void
    {
        $builder = new GetEbizWebFormUrlRequestBuilder(new ReturnUrlBuilder());
        $orderData = new CheckoutOrderData(
            'order-id',
            'transaction-id',
            '10001',
            'sales-channel-id',
            false,
            'customer-id',
            '1000',
            'buyer@example.com',
            'Jane Doe',
            new \DateTimeImmutable('2026-04-10 00:00:00'),
            'USD',
            50.0,
            50.0,
            5.0,
            0.0,
            0.0,
            0.0,
            new AddressData('Jane', 'Doe', 'ACME', 'Main St 1', null, 'Irvine', 'CA', '92618', 'US'),
            null,
            [new LineItemData('SKU1', 'Product', 'Product', 0.0, 'EA', 50.0, 1.0, true, 5.0)]
        );
        $config = new PluginConfig(
            'sandbox',
            'https://example.test',
            'sid',
            'uid',
            'pwd',
            'subkey',
            '92618',
            'Sale',
            7,
            20,
            1,
            'Order {{ orderNumber }}'
        );

        $payload = $builder->build($orderData, $config, 'https://shop.test/payment/finalize-transaction');

        self::assertSame('Webform', $payload['ePaymentForm']['formType']);
        self::assertSame('CC,ACH', $payload['ePaymentForm']['payByType']);
        self::assertSame('Sale', $payload['ePaymentForm']['processingCommand']);
        self::assertSame('transaction-id', $payload['ePaymentForm']['transactionLookupKey']);
        self::assertSame('ACME', $payload['ePaymentForm']['companyName']);
        self::assertSame(5.0, $payload['ePaymentForm']['taxAmount']);
        self::assertSame(5.0, $payload['ePaymentForm']['lineItems'][0]['taxAmount']);
        self::assertSame(50.0, $payload['ePaymentForm']['lineItems'][0]['unitPrice']);
        self::assertSame('ACME', $payload['ePaymentForm']['billingAddress']['companyName']);
        self::assertSame('CA', $payload['ePaymentForm']['billingAddress']['state']);
        self::assertStringContainsString('ebizchargeResult=approved', $payload['ePaymentForm']['approvedURL']);
        self::assertArrayNotHasKey('currency', $payload['ePaymentForm']);
        self::assertArrayNotHasKey('allowPartialAuth', $payload['ePaymentForm']);
        self::assertArrayNotHasKey('ifAuthExpired', $payload['ePaymentForm']);
        self::assertArrayNotHasKey('isRecurring', $payload['ePaymentForm']);
    }
}
