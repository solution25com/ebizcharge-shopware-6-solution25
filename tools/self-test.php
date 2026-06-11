<?php declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

use EbizChargeShopware\Command\TestConnectionCommand;
use EbizChargeShopware\Controller\Api\AdminDiagnosticsController;
use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\Installer\PaymentMethodInstaller;
use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\Client\ProviderTransportInterface;
use EbizChargeShopware\Provider\Client\RestProviderClient;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\GetEbizWebFormUrlRequestBuilder;
use EbizChargeShopware\Provider\Request\GetTransactionDetailsRequestBuilder;
use EbizChargeShopware\Provider\Request\ReturnUrlBuilder;
use EbizChargeShopware\Provider\Request\SearchReceivedPaymentsRequestBuilder;
use EbizChargeShopware\Provider\Request\SecurityTokenPayloadFactory;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Service\Checkout\HostedCheckoutService;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\Service\Connection\ConnectionTestService;
use EbizChargeShopware\Service\Finalize\BrowserReturnParser;
use EbizChargeShopware\Service\Finalize\FinalizationService;
use EbizChargeShopware\Service\PaymentLinkService;
use EbizChargeShopware\Service\StateSync\StateTransitionPolicy;
use EbizChargeShopware\Service\StateSync\TransactionStateResolver;
use EbizChargeShopware\Service\StateSync\TransactionStateSyncService;
use EbizChargeShopware\Core\Content\EbizchargePaymentTransaction\EbizchargePaymentTransactionEntity;
use EbizChargeShopware\Storage\Dal\DalTransactionRecordStore;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use EbizChargeShopware\ValueObject\AddressData;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\LineItemData;
use EbizChargeShopware\ValueObject\PluginConfig;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

final class TestFailure extends RuntimeException
{
}

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new TestFailure($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

function baseConfig(array $override = []): PluginConfig
{
    return new PluginConfig(
        $override['environmentMode'] ?? 'sandbox',
        $override['baseUrl'] ?? 'https://example.test',
        $override['securityId'] ?? 'sid',
        $override['userId'] ?? 'uid',
        $override['password'] ?? 'pwd',
        $override['subscriptionKey'] ?? 'subkey',
        $override['shipFromZip'] ?? '92618',
        $override['processingCommand'] ?? 'Sale',
        $override['verificationLookbackDays'] ?? 7,
        $override['connectionTimeoutSeconds'] ?? 20,
        $override['retryCount'] ?? 1,
        $override['descriptionTemplate'] ?? 'Order {{ orderNumber }}'
    );
}

function baseOrderData(array $override = []): CheckoutOrderData
{
    return new CheckoutOrderData(
        $override['orderId'] ?? 'order-id',
        $override['orderTransactionId'] ?? 'transaction-id',
        $override['orderNumber'] ?? '10001',
        $override['salesChannelId'] ?? 'sales-channel-id',
        $override['guest'] ?? false,
        $override['customerId'] ?? 'customer-id',
        $override['customerNumber'] ?? '1000',
        $override['customerEmail'] ?? 'buyer@example.com',
        $override['customerFullName'] ?? 'Jane Doe',
        $override['orderDate'] ?? new DateTimeImmutable('2026-04-10 00:00:00'),
        $override['currencyIso'] ?? 'USD',
        $override['amountDue'] ?? 50.0,
        $override['totalAmount'] ?? 50.0,
        $override['taxAmount'] ?? 5.0,
        $override['shippingAmount'] ?? 0.0,
        $override['dutyAmount'] ?? 0.0,
        $override['tipAmount'] ?? 0.0,
        new AddressData('Jane', 'Doe', 'ACME', 'Main St 1', null, 'Irvine', 'CA', '92618', 'US'),
        null,
        [new LineItemData('SKU1', 'Product', 'Product', 0.0, 'EA', 50.0, 1.0, true, 5.0)]
    );
}

function makeTransactionRecordStore(): DalTransactionRecordStore
{
    $repository = new class extends EntityRepository {
        /** @var array<string, EbizchargePaymentTransactionEntity> */
        public array $entities = [];

        public function upsert(array $data, object $context): void
        {
            foreach ($data as $row) {
                $id = (string) ($row['orderTransactionId'] ?? '');
                if ($id === '') {
                    continue;
                }

                $entity = $this->entities[$id] ?? new EbizchargePaymentTransactionEntity();
                $entity->setOrderTransactionId($id);

                foreach ($row as $property => $value) {
                    $setter = 'set' . ucfirst((string) $property);
                    if ($property === 'updatedAt' || !method_exists($entity, $setter)) {
                        continue;
                    }

                    $entity->{$setter}($value);
                }

                $this->entities[$id] = $entity;
            }
        }

        public function search(object $criteria, object $context): object
        {
            return new class($this->entities) {
                public function __construct(private array $entities)
                {
                }

                public function first(): mixed
                {
                    return reset($this->entities) ?: null;
                }
            };
        }
    };

    return new DalTransactionRecordStore($repository);
}

function makeStateRepository(?string $currentState): EntityRepository
{
    return new class($currentState) extends EntityRepository {
        public function __construct(private ?string $currentState)
        {
        }

        public function search(object $criteria, object $context): object
        {
            return new class($this->currentState) {
                public function __construct(private ?string $currentState)
                {
                }

                public function first(): mixed
                {
                    if ($this->currentState === null) {
                        return null;
                    }

                    $entity = new OrderTransactionEntity();
                    $entity->setStateMachineState(new class($this->currentState) {
                        public function __construct(private string $state)
                        {
                        }

                        public function getTechnicalName(): string
                        {
                            return $this->state;
                        }
                    });

                    return $entity;
                }
            };
        }
    };
}

$tests = [];

$tests['payment-method-installer-create-update-deactivate'] = static function (): void {
    $repository = new class extends EntityRepository {
        public ?string $existingId = null;
        public array $created = [];
        public array $updated = [];

        public function create(array $data, object $context): void
        {
            $this->created[] = $data;
            $this->existingId = 'payment-method-id';
        }

        public function update(array $data, object $context): void
        {
            $this->updated[] = $data;
        }

        public function searchIds(object $criteria, object $context): object
        {
            return new class($this->existingId) {
                public function __construct(private ?string $existingId)
                {
                }

                public function firstId(): ?string
                {
                    return $this->existingId;
                }
            };
        }
    };

    $installer = new PaymentMethodInstaller($repository);
    $context = new Context();

    $installer->ensurePaymentMethod('plugin-id', $context, false);
    same(false, $repository->created[0][0]['active'], 'Payment method should be created inactive.');
    same(true, $repository->created[0][0]['afterOrderEnabled'], 'Payment method should be after-order enabled for checkout retry.');
    same('ebizcharge_credit_card', $repository->created[0][0]['technicalName'], 'Payment technical name mismatch.');

    $installer->ensurePaymentMethod('plugin-id', $context, true);
    same(true, $repository->updated[0][0]['active'], 'Payment method update should accept active=true.');
    same(true, $repository->updated[0][0]['afterOrderEnabled'], 'Payment method update should keep after-order enabled for checkout retry.');

    $installer->setPaymentMethodActive(false, $context);
    same(false, $repository->updated[1][0]['active'], 'Payment method deactivate should write active=false.');
};

$tests['builder-hosted-card-payload'] = static function (): void {
    $builder = new GetEbizWebFormUrlRequestBuilder(new ReturnUrlBuilder());
    $payload = $builder->build(baseOrderData(), baseConfig(), 'https://shop.test/payment/finalize-transaction');

    same('Webform', $payload['ePaymentForm']['formType'], 'Hosted form type mismatch.');
    same('CC', $payload['ePaymentForm']['payByType'], 'Pay-by type mismatch.');
    same('Sale', $payload['ePaymentForm']['processingCommand'], 'Processing command mismatch.');
    same('transaction-id', $payload['ePaymentForm']['transactionLookupKey'], 'Lookup key mismatch.');
    same('https://shop.test/payment/finalize-transaction', $payload['ePaymentForm']['approvedURL'], 'Approved URL mismatch.');
    ok(!array_key_exists('currency', $payload['ePaymentForm']), 'Currency must not be sent in hosted webform payload.');
    ok(!array_key_exists('allowPartialAuth', $payload['ePaymentForm']), 'allowPartialAuth must not be sent.');
};

$tests['normalizer-extracts-hosted-url-and-payment'] = static function (): void {
    $normalizer = new ResponseNormalizer();
    $url = $normalizer->extractHostedRedirectUrl([
        'getEbizWebFormURLResponse' => [
            'getEbizWebFormURLResult' => [
                'url' => 'https://webforms.ebizcharge.net/EBizSecureForm.aspx?pid=123',
            ],
        ],
    ]);
    same('https://webforms.ebizcharge.net/EBizSecureForm.aspx?pid=123', $url, 'Hosted URL normalization mismatch.');

    $result = $normalizer->normalizeVerifiedPayment([
        'searchEbizWebFormReceivedPaymentsResponse' => [
            'searchEbizWebFormReceivedPaymentsResult' => [[
                'RefNum' => '3177716774',
                'AuthCode' => '505998',
                'PaymentType' => 'AuthOnly',
                'PaymentMethod' => 'Visa',
                'DatePaid' => '2024-05-04T00:36:48',
                'Amount' => 50.0,
                'Currency' => 'USD',
                'TransactionLookupKey' => 'transaction-id',
            ]],
        ],
    ], 'Sale', baseOrderData());

    same('approved', $result->outcome, 'Verified payment outcome mismatch.');
    same('AuthOnly', $result->operationMode, 'Verified payment mode mismatch.');
    same('3177716774', $result->providerReference, 'Verified provider reference mismatch.');
    same('505998', $result->authorizationCode, 'Verified auth code mismatch.');
};

$tests['rest-client-wraps-security-token-and-header'] = static function (): void {
    $transport = new class implements ProviderTransportInterface {
        public array $captured = [];

        public function send(string $url, array $headers, array $payload, int $timeoutSeconds): array
        {
            $this->captured = compact('url', 'headers', 'payload', 'timeoutSeconds');

            return ['statusCode' => 200, 'body' => ['settings' => ['ok' => true]], 'rawBody' => '{}'];
        }
    };

    $client = new RestProviderClient(
        $transport,
        new SecurityTokenPayloadFactory(),
        new NullLogger()
    );

    $client->send(ProviderOperation::CONNECTION_TEST, [], baseConfig());

    same('https://example.test/GetMerchantTransactionData', $transport->captured['url'], 'Provider URL mismatch.');
    same('subkey', $transport->captured['headers']['EBizSubscription-Key'], 'Subscription key header mismatch.');
    same('sid', $transport->captured['payload']['getMerchantTransactionData']['securityToken']['securityId'], 'Security token mismatch.');
};

$tests['connection-test-service-and-health-registry'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            return [
                'statusCode' => 200,
                'body' => [
                    'getMerchantTransactionDataResponse' => [
                        'getMerchantTransactionDataResult' => [
                            'merchantName' => 'Demo Merchant',
                        ],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $systemConfigService = new SystemConfigService();
    $registry = new ConnectionHealthRegistry($systemConfigService);
    $service = new ConnectionTestService(
        $providerClient,
        new ResponseNormalizer(),
        $registry,
        new NullLogger()
    );

    $config = baseConfig();
    $result = $service->test($config);
    ok($result->success, 'Connection test result should be successful.');
    same(200, $result->statusCode, 'Connection test HTTP status mismatch.');
    ok($registry->hasSuccessfulTest($config), 'Connection test result should be persisted for the current configuration fingerprint.');
    ok(!$registry->hasSuccessfulTest(baseConfig(['subscriptionKey' => 'changed'])), 'Fingerprint mismatch should invalidate prior successful test.');
    ok(!$registry->hasSuccessfulTest(baseConfig(['password' => 'changed-password'])), 'Password-only changes must invalidate prior successful tests.');
};

$tests['connection-test-rejects-unrelated-json'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            return ['statusCode' => 200, 'body' => ['ok' => true, 'message' => 'not merchant transaction data'], 'rawBody' => '{}'];
        }
    };

    $service = new ConnectionTestService(
        $providerClient,
        new ResponseNormalizer(),
        new ConnectionHealthRegistry(new SystemConfigService()),
        new NullLogger()
    );

    $result = $service->test(baseConfig());
    ok(!$result->success, 'Connection test must reject unrelated JSON payloads.');
    same('provider_response', $result->failureCategory, 'Unexpected connection-test failure category.');
};

$tests['response-normalizer-classifies-auth-statuses-safely'] = static function (): void {
    $normalizer = new ResponseNormalizer();

    foreach ([
        'unauthorized',
        'unauthenticated',
        'authentication_error',
    ] as $status) {
        $result = $normalizer->normalizeVerifiedPayment([
            'searchEbizWebFormReceivedPaymentsResponse' => [
                'searchEbizWebFormReceivedPaymentsResult' => [[
                    'RefNum' => '3177716774',
                    'PaymentType' => 'Sale',
                    'PaymentMethod' => 'Visa',
                    'Status' => $status,
                    'Amount' => 50.0,
                    'Currency' => 'USD',
                    'TransactionLookupKey' => 'transaction-id',
                ]],
            ],
        ], 'Sale', baseOrderData());

        same(ProviderOperationResult::OUTCOME_DECLINED, $result->outcome, sprintf('Status "%s" must not be treated as approved.', $status));
    }

    foreach ([
        'authorized',
        'authonly',
        'auth_only',
        'auth-only',
    ] as $status) {
        $result = $normalizer->normalizeVerifiedPayment([
            'searchEbizWebFormReceivedPaymentsResponse' => [
                'searchEbizWebFormReceivedPaymentsResult' => [[
                    'RefNum' => '3177716774',
                    'PaymentType' => 'AuthOnly',
                    'PaymentMethod' => 'Visa',
                    'Status' => $status,
                    'Amount' => 50.0,
                    'Currency' => 'USD',
                    'TransactionLookupKey' => 'transaction-id',
                ]],
            ],
        ], 'Sale', baseOrderData());

        same(ProviderOperationResult::OUTCOME_APPROVED, $result->outcome, sprintf('Status "%s" should remain approved.', $status));
    }
};

$tests['hosted-checkout-service-stores-redirect-metadata'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            same(ProviderOperation::GET_WEBFORM_URL, $operation, 'Hosted checkout must call GetEbizWebFormURL.');

            return [
                'statusCode' => 200,
                'body' => [
                    'getEbizWebFormURLResponse' => [
                        'getEbizWebFormURLResult' => [
                            'url' => 'https://webforms.ebizcharge.net/EBizSecureForm.aspx?pid=456',
                        ],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $store = new class implements TransactionRecordStoreInterface {
        public array $records = [];

        public function upsert(string $orderTransactionId, array $record, Context $context): void
        {
            $this->records[$orderTransactionId] = $record;
        }

        public function find(string $orderTransactionId, Context $context): ?array
        {
            return $this->records[$orderTransactionId] ?? null;
        }

        public function findByOrderIdentity(string $value, Context $context): ?array
        {
            return null;
        }
    };

    $service = new HostedCheckoutService(
        new GetEbizWebFormUrlRequestBuilder(new ReturnUrlBuilder()),
        $providerClient,
        new ResponseNormalizer(),
        $store
    );

    $redirect = $service->start(
        baseOrderData(['amountDue' => 40.0, 'totalAmount' => 50.0]),
        baseConfig(),
        'https://shop.test/payment/finalize-transaction',
        new Context()
    );

    same('https://webforms.ebizcharge.net/EBizSecureForm.aspx?pid=456', $redirect->redirectUrl, 'Redirect URL mismatch.');
    same('Sale', $redirect->mode, 'Redirect mode mismatch.');
    same('transaction-id', $store->records['transaction-id']['lookup_key'], 'Lookup key record mismatch.');
    same(40.0, $store->records['transaction-id']['amount_total'], 'Stored amount must use the Shopware transaction amount.');
    same('USD', $store->records['transaction-id']['currency_iso'], 'Currency record mismatch.');
};

$tests['state-resolution-and-transition-policy'] = static function (): void {
    $resolver = new TransactionStateResolver();
    same(OrderTransactionStates::STATE_PAID, $resolver->resolve(ProviderOperationResult::approved('Sale', 'ref-1', 'auth-1', 'ok')), 'Sale should map to paid.');
    same(OrderTransactionStates::STATE_AUTHORIZED, $resolver->resolve(ProviderOperationResult::approved('AuthOnly', 'ref-1', 'auth-1', 'ok')), 'AuthOnly should map to authorized.');
    same(OrderTransactionStates::STATE_FAILED, $resolver->resolve(ProviderOperationResult::declined('Sale', 'declined')), 'Decline should map to failed.');

    $policy = new StateTransitionPolicy();
    ok(!$policy->shouldApply(OrderTransactionStates::STATE_PAID, OrderTransactionStates::STATE_FAILED), 'Paid must not downgrade to failed.');
    ok($policy->shouldApply(OrderTransactionStates::STATE_OPEN, OrderTransactionStates::STATE_IN_PROGRESS), 'Open should transition to in progress.');
};

$tests['state-sync-illegal-transition-persists-actual-shopware-state'] = static function (): void {
    $store = makeTransactionRecordStore();
    $stateHandler = new class extends OrderTransactionStateHandler {
        public function paid(string $id, object $context): void
        {
            throw new IllegalTransitionException('paid transition rejected');
        }
    };

    $service = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    $state = $service->apply(
        'transaction-id',
        ProviderOperationResult::approved('Sale', '3177716774', '505998', 'ok'),
        new Context()
    );

    same(OrderTransactionStates::STATE_OPEN, $state, 'Illegal transitions must persist the current Shopware state.');
    same(OrderTransactionStates::STATE_OPEN, $store->find('transaction-id', new Context())['normalized_state'], 'Stored state must match the actual Shopware state after an illegal transition.');
};

$tests['state-sync-rethrows-unexpected-transition-errors'] = static function (): void {
    $store = makeTransactionRecordStore();
    $stateHandler = new class extends OrderTransactionStateHandler {
        public function paid(string $id, object $context): void
        {
            throw new RuntimeException('unexpected failure');
        }
    };

    $service = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    try {
        $service->apply(
            'transaction-id',
            ProviderOperationResult::approved('Sale', '3177716774', '505998', 'ok'),
            new Context()
        );
    } catch (RuntimeException $exception) {
        same('unexpected failure', $exception->getMessage(), 'Unexpected transition failures must bubble up.');
        ok($store->find('transaction-id', new Context()) === null, 'Unexpected transition failures must not write a misleading local state record.');

        return;
    }

    throw new TestFailure('Unexpected transition failures must be rethrown.');
};

$tests['dal-transaction-record-store-merges-upserts'] = static function (): void {
    $store = makeTransactionRecordStore();

    $store->upsert('transaction-id', [
        'lookup_key' => 'transaction-id',
        'normalized_state' => OrderTransactionStates::STATE_IN_PROGRESS,
        'amount_total' => 40.0,
    ], new Context());
    $firstRow = $store->find('transaction-id', new Context());

    $store->upsert('transaction-id', [
        'provider_ref_num' => '3177716774',
        'normalized_state' => OrderTransactionStates::STATE_PAID,
    ], new Context());
    $secondRow = $store->find('transaction-id', new Context());

    same('transaction-id', $secondRow['lookup_key'], 'Repeated upserts must preserve existing values not present in the later payload.');
    same('3177716774', $secondRow['provider_ref_num'], 'Repeated upserts must merge new values into the existing row.');
    same(OrderTransactionStates::STATE_PAID, $secondRow['normalized_state'], 'Repeated upserts must update the normalized state.');
};

$tests['finalization-service-verifies-approved-return'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            same(ProviderOperation::SEARCH_RECEIVED_PAYMENTS, $operation, 'Approved return without refNum should search received payments.');

            return [
                'statusCode' => 200,
                'body' => [
                    'searchEbizWebFormReceivedPaymentsResponse' => [
                        'searchEbizWebFormReceivedPaymentsResult' => [[
                            'RefNum' => '3177716774',
                            'AuthCode' => '505998',
                            'PaymentType' => 'Sale',
                            'PaymentMethod' => 'Visa',
                            'DatePaid' => '2024-05-04T00:36:48',
                            'Amount' => 50.0,
                            'Currency' => 'USD',
                            'TransactionLookupKey' => 'transaction-id',
                        ]],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $store = makeTransactionRecordStore();
    $stateHandler = new OrderTransactionStateHandler();
    $syncService = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    $service = new FinalizationService(
        new BrowserReturnParser(),
        $providerClient,
        new SearchReceivedPaymentsRequestBuilder(),
        new GetTransactionDetailsRequestBuilder(),
        new ResponseNormalizer(),
        $syncService,
        $store,
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        new NullLogger()
    );

    $outcome = $service->finalize(
        new Request(['ebizchargeResult' => 'approved']),
        baseOrderData(),
        baseConfig(),
        new Context()
    );

    same(ProviderOperationResult::OUTCOME_APPROVED, $outcome->result->outcome, 'Finalize outcome mismatch.');
    same(OrderTransactionStates::STATE_PAID, $outcome->targetShopwareState, 'Finalize target state mismatch.');
    same(['paid', 'transaction-id'], $stateHandler->transitions[0], 'State handler should receive paid transition.');
    same('3177716774', $store->find('transaction-id', new Context())['provider_ref_num'], 'Stored provider reference mismatch.');
};

$tests['finalization-service-uses-ach-return-refnum'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            same(ProviderOperation::GET_TRANSACTION_DETAILS, $operation, 'ACH return with TranRefNum should verify by transaction details.');

            return [
                'statusCode' => 200,
                'body' => [
                    'getTransactionDetailsResponse' => [
                        'getTransactionDetailsResult' => [
                            'RefNum' => '3229484286',
                            'AuthCode' => '000000',
                            'Status' => 'Approved',
                            'AuthAmount' => 46.84,
                            'PaymentType' => 'Sale',
                            'PaymentMethod' => 'ACH',
                            'TransactionLookupKey' => 'transaction-id',
                            'response' => [
                                'RefNum' => '3229484286',
                                'AuthCode' => '000000',
                                'Status' => 'Approved',
                                'AuthAmount' => 46.84,
                            ],
                            'details' => [
                                'amount' => 46.84,
                            ],
                            'transactionType' => 'Sale',
                            'checkData' => [],
                        ],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $store = makeTransactionRecordStore();
    $stateHandler = new OrderTransactionStateHandler();
    $syncService = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    $service = new FinalizationService(
        new BrowserReturnParser(),
        $providerClient,
        new SearchReceivedPaymentsRequestBuilder(),
        new GetTransactionDetailsRequestBuilder(),
        new ResponseNormalizer(),
        $syncService,
        $store,
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        new NullLogger()
    );

    $outcome = $service->finalize(
        new Request([
            'result' => '',
            'TranResult' => 'Approved',
            'TranRefNum' => '3229484286',
            'PayByType' => 'echeck',
        ]),
        baseOrderData(['totalAmount' => 46.84, 'amountDue' => 46.84]),
        baseConfig(),
        new Context()
    );

    same(ProviderOperationResult::OUTCOME_APPROVED, $outcome->result->outcome, 'ACH return should verify as approved.');
    same('3229484286', $store->find('transaction-id', new Context())['provider_ref_num'], 'ACH provider reference mismatch.');
};

$tests['finalization-service-does-not-trust-browser-decline-without-provider-verification'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            throw ProviderCommunicationException::requestFailed($operation->value, 'timeout');
        }
    };

    $store = makeTransactionRecordStore();
    $stateHandler = new OrderTransactionStateHandler();
    $syncService = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    $service = new FinalizationService(
        new BrowserReturnParser(),
        $providerClient,
        new SearchReceivedPaymentsRequestBuilder(),
        new GetTransactionDetailsRequestBuilder(),
        new ResponseNormalizer(),
        $syncService,
        $store,
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        new NullLogger()
    );

    $outcome = $service->finalize(
        new Request(['ebizchargeResult' => 'declined']),
        baseOrderData(),
        baseConfig(),
        new Context()
    );

    same(ProviderOperationResult::OUTCOME_PENDING, $outcome->result->outcome, 'Browser-only decline must not become authoritative.');
    same(OrderTransactionStates::STATE_IN_PROGRESS, $outcome->targetShopwareState, 'Unverified decline should leave the transaction in progress.');
    ok($outcome->throwInterrupted, 'Unverified decline should interrupt finalize.');
    ok(!$outcome->throwCustomerCancelled, 'Unverified decline must not be treated as customer cancellation.');
    same(['process', 'transaction-id'], $stateHandler->transitions[0], 'Pending verification should move the transaction into process state.');
};

$tests['finalization-service-allows-provider-verified-cancel'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            same(ProviderOperation::SEARCH_RECEIVED_PAYMENTS, $operation, 'Cancelled return without refNum should search received payments.');

            return [
                'statusCode' => 200,
                'body' => [
                    'searchEbizWebFormReceivedPaymentsResponse' => [
                        'searchEbizWebFormReceivedPaymentsResult' => [[
                            'RefNum' => '3177716774',
                            'PaymentType' => 'Sale',
                            'PaymentMethod' => 'Visa',
                            'Status' => 'Cancelled',
                            'Amount' => 50.0,
                            'Currency' => 'USD',
                            'TransactionLookupKey' => 'transaction-id',
                        ]],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $store = makeTransactionRecordStore();
    $stateHandler = new OrderTransactionStateHandler();
    $syncService = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    $service = new FinalizationService(
        new BrowserReturnParser(),
        $providerClient,
        new SearchReceivedPaymentsRequestBuilder(),
        new GetTransactionDetailsRequestBuilder(),
        new ResponseNormalizer(),
        $syncService,
        $store,
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        new NullLogger()
    );

    $outcome = $service->finalize(
        new Request(['ebizchargeResult' => 'cancelled']),
        baseOrderData(),
        baseConfig(),
        new Context()
    );

    same(ProviderOperationResult::OUTCOME_CANCELLED, $outcome->result->outcome, 'Verified cancel outcome mismatch.');
    same(OrderTransactionStates::STATE_CANCELLED, $outcome->targetShopwareState, 'Verified cancel state mismatch.');
    ok(!$outcome->throwInterrupted, 'Verified cancel should not use the interrupted path.');
    ok($outcome->throwCustomerCancelled, 'Verified cancel should use the customer-cancelled path.');
    same(['cancel', 'transaction-id'], $stateHandler->transitions[0], 'Verified cancel should transition the order transaction to cancelled.');
};

$tests['finalization-service-rejects-provider-amount-mismatch'] = static function (): void {
    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            return [
                'statusCode' => 200,
                'body' => [
                    'searchEbizWebFormReceivedPaymentsResponse' => [
                        'searchEbizWebFormReceivedPaymentsResult' => [[
                            'RefNum' => '3177716774',
                            'AuthCode' => '505998',
                            'PaymentType' => 'Sale',
                            'PaymentMethod' => 'Visa',
                            'DatePaid' => '2024-05-04T00:36:48',
                            'Amount' => 99.0,
                            'Currency' => 'USD',
                            'TransactionLookupKey' => 'transaction-id',
                        ]],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $store = makeTransactionRecordStore();
    $stateHandler = new OrderTransactionStateHandler();
    $syncService = new TransactionStateSyncService(
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        $stateHandler,
        new TransactionStateResolver(),
        new StateTransitionPolicy(),
        $store,
        new NullLogger()
    );

    $service = new FinalizationService(
        new BrowserReturnParser(),
        $providerClient,
        new SearchReceivedPaymentsRequestBuilder(),
        new GetTransactionDetailsRequestBuilder(),
        new ResponseNormalizer(),
        $syncService,
        $store,
        makeStateRepository(OrderTransactionStates::STATE_OPEN),
        new NullLogger()
    );

    $outcome = $service->finalize(
        new Request(['ebizchargeResult' => 'approved']),
        baseOrderData(),
        baseConfig(),
        new Context()
    );

    same(ProviderOperationResult::OUTCOME_PENDING, $outcome->result->outcome, 'Amount mismatch must not produce a success state.');
    same(OrderTransactionStates::STATE_IN_PROGRESS, $outcome->targetShopwareState, 'Amount mismatch should leave the transaction in progress.');
    ok($outcome->throwInterrupted, 'Amount mismatch should interrupt finalize.');
};

$tests['admin-controller-and-command-instantiate-and-run'] = static function (): void {
    $systemConfig = new SystemConfigService();
    foreach ([
        'EbizChargeShopware.config.environmentMode' => 'sandbox',
        'EbizChargeShopware.config.sandboxBaseUrl' => 'https://example.test',
        'EbizChargeShopware.config.sandboxSecurityId' => 'sid',
        'EbizChargeShopware.config.sandboxUserId' => 'uid',
        'EbizChargeShopware.config.sandboxPassword' => 'pwd',
        'EbizChargeShopware.config.sandboxSubscriptionKey' => 'subkey',
        'EbizChargeShopware.config.shipFromZip' => '92618',
        'EbizChargeShopware.config.processingCommand' => 'Sale',
        'EbizChargeShopware.config.verificationLookbackDays' => 7,
        'EbizChargeShopware.config.connectionTimeoutSeconds' => 20,
        'EbizChargeShopware.config.retryCount' => 1,
        'EbizChargeShopware.config.descriptionTemplate' => 'Order {{ orderNumber }}',
    ] as $key => $value) {
        $systemConfig->set($key, $value);
    }

    $providerClient = new class implements ProviderClientInterface {
        public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
        {
            return [
                'statusCode' => 200,
                'body' => [
                    'getMerchantTransactionDataResponse' => [
                        'getMerchantTransactionDataResult' => [
                            'merchantName' => 'Demo Merchant',
                        ],
                    ],
                ],
                'rawBody' => '{}',
            ];
        }
    };

    $provider = new PluginConfigProvider($systemConfig);
    $connectionService = new ConnectionTestService(
        $providerClient,
        new ResponseNormalizer(),
        new ConnectionHealthRegistry($systemConfig),
        new NullLogger()
    );

    $paymentLinkService = (new ReflectionClass(PaymentLinkService::class))->newInstanceWithoutConstructor();
    $controller = new AdminDiagnosticsController(
        $provider,
        $connectionService,
        $paymentLinkService,
        makeTransactionRecordStore(),
        new NullLogger()
    );
    $response = $controller->testConnection(new Request([], ['salesChannelId' => '']));
    ok(is_array($response->data), 'Admin diagnostics controller should return array data.');
    ok(($response->data['success'] ?? false) === true, 'Admin diagnostics controller should report success.');

    $command = new TestConnectionCommand($provider, $connectionService);
    $execute = new ReflectionMethod($command, 'execute');

    $input = new class implements InputInterface {
        public function getOption(string $name): mixed
        {
            return null;
        }
    };
    $output = new class implements OutputInterface {
    };

    $status = $execute->invoke($command, $input, $output);
    same(Command::SUCCESS, $status, 'CLI connection test command should return success.');
};

$failures = [];
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failures[] = [$name, $e->getMessage()];
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

if ($failures !== []) {
    echo "\n" . count($failures) . " self-test(s) failed.\n";
    exit(1);
}

echo "\nAll self-tests passed.\n";
