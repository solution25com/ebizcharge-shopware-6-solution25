<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Provider\Response;

use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Exception\VerificationException;
use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\LineItemData;
use PHPUnit\Framework\TestCase;

final class ResponseNormalizerTest extends TestCase
{
    public function testExtractsHostedUrlFromNestedPayload(): void
    {
        $normalizer = new ResponseNormalizer();
        $payload = [
            'getEbizWebFormURLResponse' => [
                'getEbizWebFormURLResult' => [
                    'url' => 'https://webforms.ebizcharge.net/EBizSecureForm.aspx?pid=123',
                ],
            ],
        ];

        self::assertSame(
            'https://webforms.ebizcharge.net/EBizSecureForm.aspx?pid=123',
            $normalizer->extractHostedRedirectUrl($payload)
        );
    }

    public function testNormalizesVerifiedPayment(): void
    {
        $normalizer = new ResponseNormalizer();
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
        $payload = [
            'searchEbizWebFormReceivedPaymentsResponse' => [
                'searchEbizWebFormReceivedPaymentsResult' => [
                    [
                        'RefNum' => '3177716774',
                        'AuthCode' => '505998',
                        'PaymentType' => 'AuthOnly',
                        'PaymentMethod' => 'Visa',
                        'DatePaid' => '2024-05-04T00:36:48',
                        'Amount' => 50.0,
                        'Currency' => 'USD',
                        'TransactionLookupKey' => 'transaction-id',
                    ],
                ],
            ],
        ];

        $result = $normalizer->normalizeVerifiedPayment($payload, 'Sale', $orderData);

        self::assertSame('approved', $result->outcome);
        self::assertSame('AuthOnly', $result->operationMode);
        self::assertSame('3177716774', $result->providerReference);
        self::assertSame('505998', $result->authorizationCode);
    }

    public function testConnectionTestDetectionTreatsSettingsPayloadAsSuccess(): void
    {
        $normalizer = new ResponseNormalizer();
        self::assertTrue($normalizer->connectionTestSucceeded([
            'getMerchantTransactionDataResponse' => [
                'getMerchantTransactionDataResult' => [
                    'merchantName' => 'demo',
                ],
            ],
        ]));
        self::assertFalse($normalizer->connectionTestSucceeded([]));
        self::assertFalse($normalizer->connectionTestSucceeded(['ok' => true]));
    }

    public function testRejectsAmountMismatchDuringVerification(): void
    {
        $this->expectException(VerificationException::class);

        $normalizer = new ResponseNormalizer();
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
            150.0,
            5.0,
            0.0,
            0.0,
            0.0,
            new AddressData('Jane', 'Doe', 'ACME', 'Main St 1', null, 'Irvine', 'CA', '92618', 'US'),
            null,
            [new LineItemData('SKU1', 'Product', 'Product', 0.0, 'EA', 50.0, 1.0, true, 5.0)]
        );

        $normalizer->normalizeVerifiedPayment([
            'searchEbizWebFormReceivedPaymentsResponse' => [
                'searchEbizWebFormReceivedPaymentsResult' => [
                    [
                        'RefNum' => '3177716774',
                        'AuthCode' => '505998',
                        'PaymentType' => 'Sale',
                        'PaymentMethod' => 'Visa',
                        'DatePaid' => '2024-05-04T00:36:48',
                        'Amount' => 99.0,
                        'Currency' => 'USD',
                        'TransactionLookupKey' => 'transaction-id',
                    ],
                ],
            ],
        ], 'Sale', $orderData);
    }

    /**
     * @dataProvider unauthorizedStatusProvider
     */
    public function testUnauthorizedLikeStatusesNeverResolveAsApproved(string $status): void
    {
        $normalizer = new ResponseNormalizer();
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

        $result = $normalizer->normalizeVerifiedPayment([
            'searchEbizWebFormReceivedPaymentsResponse' => [
                'searchEbizWebFormReceivedPaymentsResult' => [
                    [
                        'RefNum' => '3177716774',
                        'PaymentType' => 'Sale',
                        'PaymentMethod' => 'Visa',
                        'Status' => $status,
                        'Amount' => 50.0,
                        'Currency' => 'USD',
                        'TransactionLookupKey' => 'transaction-id',
                    ],
                ],
            ],
        ], 'Sale', $orderData);

        self::assertSame('declined', $result->outcome);
    }

    /**
     * @dataProvider approvedAuthStatusProvider
     */
    public function testApprovedAuthStatusesStayApproved(string $status): void
    {
        $normalizer = new ResponseNormalizer();
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

        $result = $normalizer->normalizeVerifiedPayment([
            'searchEbizWebFormReceivedPaymentsResponse' => [
                'searchEbizWebFormReceivedPaymentsResult' => [
                    [
                        'RefNum' => '3177716774',
                        'PaymentType' => 'AuthOnly',
                        'PaymentMethod' => 'Visa',
                        'Status' => $status,
                        'Amount' => 50.0,
                        'Currency' => 'USD',
                        'TransactionLookupKey' => 'transaction-id',
                    ],
                ],
            ],
        ], 'Sale', $orderData);

        self::assertSame('approved', $result->outcome);
    }

    public static function unauthorizedStatusProvider(): array
    {
        return [
            ['unauthorized'],
            ['unauthenticated'],
            ['authentication_error'],
        ];
    }

    public static function approvedAuthStatusProvider(): array
    {
        return [
            ['authorized'],
            ['authonly'],
            ['auth_only'],
            ['auth-only'],
        ];
    }
}
