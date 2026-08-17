<?php declare(strict_types=1);

$root = dirname(__DIR__);
$violations = [];

function mustContain(string $file, array $needles, array &$violations): void
{
    if (!is_file($file)) {
        $violations[] = 'Missing required file: ' . $file;

        return;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        $violations[] = 'Could not read file: ' . $file;

        return;
    }

    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            $violations[] = sprintf('%s is missing required marker "%s".', $file, $needle);
        }
    }
}

function mustNotContain(string $file, array $needles, array &$violations): void
{
    if (!is_file($file)) {
        return;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        $violations[] = 'Could not read file: ' . $file;

        return;
    }

    foreach ($needles as $needle) {
        if (str_contains($content, $needle)) {
            $violations[] = sprintf('%s still contains forbidden marker "%s".', $file, $needle);
        }
    }
}

function scanPhpFiles(string $directory): array
{
    $files = [];
    if (!is_dir($directory)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

$sourceFiles = scanPhpFiles($root . '/src');

$requiredSourceMarkers = [
    'AbstractPaymentHandler' => false,
    'GetEbizWebFormURL' => false,
    'GetMerchantTransactionData' => false,
    'GetTransactionDetails' => false,
    'SearchEbizWebFormReceivedPayments' => false,
];

$forbiddenSourcePatterns = [
    'SynchronousPaymentHandlerInterface' => 'legacy 6.6 synchronous interface is not allowed in a 6.7-only plugin',
    'AsynchronousPaymentHandlerInterface' => 'legacy 6.6 asynchronous interface is not allowed in a 6.7-only plugin',
    'PreparedPaymentHandlerInterface' => 'legacy prepared-payment interface is not allowed in a 6.7-only plugin',
    'shopware.payment.method.sync' => 'legacy service tag is not allowed in a 6.7-only plugin',
    'shopware.payment.method.async' => 'legacy service tag is not allowed in a 6.7-only plugin',
    'shopware.payment.method.prepared' => 'legacy service tag is not allowed in a 6.7-only plugin',
    'SoapClient' => 'SOAP clients are explicitly forbidden; the plugin must remain REST-only',
    'ext-soap' => 'ext-soap dependency is explicitly forbidden; the plugin must remain REST-only',
    'wsdl' => 'WSDL references are explicitly forbidden; the plugin must remain REST-only',
    'monolog.logger.' => 'plugin runtime must not depend on a hidden monolog channel in v1.0.2',
    '<prototype ' => 'runtime service discovery must stay explicit in v1.0.2',
    'AuditLogService' => 'audit service was removed from the minimal install-safe slice',
    'AuditStoreInterface' => 'audit store was removed from the minimal install-safe slice',
    'DbalAuditStore' => 'audit DBAL store was removed from the minimal install-safe slice',
    'OrderPaymentSummaryService' => 'order summary surface was removed from the minimal install-safe slice',
    'AdminOrderSummaryController' => 'order summary controller was removed from the minimal install-safe slice',
//    '$cardNumber' => 'raw backend card handling is not allowed',
    'cvv' => 'CVV handling is not allowed',
];

foreach ($sourceFiles as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        $violations[] = 'Could not read ' . $file;
        continue;
    }

    foreach (array_keys($requiredSourceMarkers) as $needle) {
        if (str_contains($content, $needle)) {
            $requiredSourceMarkers[$needle] = true;
        }
    }

    foreach ($forbiddenSourcePatterns as $needle => $reason) {
        if (str_contains($content, $needle)) {
            $violations[] = sprintf('Forbidden pattern "%s" found in %s (%s).', $needle, $file, $reason);
        }
    }
}

foreach ($requiredSourceMarkers as $needle => $seen) {
    if (!$seen) {
        $violations[] = sprintf('Required source marker "%s" was not found anywhere in src/.', $needle);
    }
}

mustContain($root . '/src/Resources/config/services.xml', [
    'services/core.xml',
    'services/controllers.xml',
    'services/commands.xml',
], $violations);

mustContain($root . '/src/Resources/config/services/core.xml', [
    'shopware.payment.method',
    'EbizChargeShopware\\Installer\\PaymentMethodInstaller',
    'public="true"',
    'EbizChargeShopware\\Provider\\Client\\ProviderClientInterface" alias="EbizChargeShopware\\Provider\\Client\\RestProviderClient"',
    'EbizChargeShopware\\Provider\\Client\\ProviderTransportInterface" alias="EbizChargeShopware\\Provider\\Client\\SymfonyHttpProviderTransport"',
    'EbizChargeShopware\\Storage\\TransactionRecordStoreInterface" alias="EbizChargeShopware\\Storage\\Dal\\DalTransactionRecordStore"',
    '<argument type="service" id="logger"/>',
], $violations);
mustNotContain($root . '/src/Resources/config/services/core.xml', [
    'monolog.logger.',
    '<prototype ',
    'resource=',
    'exclude=',
], $violations);

mustContain($root . '/src/Resources/config/services/controllers.xml', [
    'AdminDiagnosticsController',
    'controller.service_arguments',
], $violations);
mustNotContain($root . '/src/Resources/config/services/controllers.xml', [
    'AdminOrderSummaryController',
], $violations);

mustContain($root . '/src/Resources/config/services/commands.xml', [
    'EbizChargeShopware\\Command\\TestConnectionCommand',
    'console.command',
    'ebizcharge:test-connection',
], $violations);

mustContain($root . '/src/Resources/config/config.xml', [
    'environmentMode',
    'sandboxBaseUrl',
    'productionBaseUrl',
    'processingCommand',
    'shipFromZip',
    'retryCount',
    'ebizcharge-api-test',
], $violations);
mustNotContain($root . '/src/Resources/config/config.xml', [
    'debugLogging',
], $violations);

mustContain($root . '/src/Resources/config/routes.php', [
    'RoutingConfigurator',
    'attribute',
    'Controller',
], $violations);

$packageConfigDir = $root . '/src/Resources/config/packages';
if (is_dir($packageConfigDir)) {
    $files = array_values(array_diff(scandir($packageConfigDir) ?: [], ['.', '..']));
    if ($files !== []) {
        $violations[] = 'src/Resources/config/packages must stay empty or absent in v1.0.0.';
    }
}

mustContain($root . '/src/Installer/PaymentMethodInstaller.php', [
    "'afterOrderEnabled' => true",
    'technicalName',
    'handlerIdentifier',
    "'active' => \$active",
], $violations);

mustContain($root . '/src/EbizChargeShopware.php', [
    'has(PaymentMethodInstaller::class)',
    'payment_method.repository',
    'new PaymentMethodInstaller',
    'public function update(UpdateContext $updateContext): void',
    'ensurePaymentMethod(',
    'setPaymentMethodActive(false',
], $violations);
mustNotContain($root . '/src/EbizChargeShopware.php', [
    'setPaymentMethodActive(true',
    '->delete([',
], $violations);

mustContain($root . '/src/Checkout/Payment/Handler/CreditCardPaymentHandler.php', [
    'requireSuccessfulTest',
    'PaymentHandlerType::REFUND',
    'PaymentHandlerType::RECURRING',
    'if ($orderData->amountDue <= 0.0)',
    '): Struct',
    '): ?RedirectResponse',
    "(string) (\$selectedMethod['type'] ?? 'card')",
], $violations);
mustNotContain($root . '/src/Checkout/Payment/Handler/CreditCardPaymentHandler.php', [
    'totalAmount <= 0.0',
], $violations);

mustContain($root . '/src/Service/Checkout/OrderTransactionLoader.php', [
    'getAmount()->getTotalPrice()',
    'normalizeOrderDate',
    'DateTimeInterface::ATOM',
], $violations);

mustContain($root . '/src/Service/Checkout/HostedCheckoutService.php', [
    "'amount_total' => \$orderData->amountDue",
], $violations);

mustContain($root . '/src/Service/EbizChargeApiClient.php', [
    "strtolower(trim(\$paymentMethodType)) === 'ach' ? 'Check' : \$config->processingCommand()",
    "'details' => \$this->buildCustomerTransactionDetails",
    "'command' => \$processingCommand ?? \$config->processingCommand()",
    "'lineItems' => \$this->buildCustomerTransactionLineItems",
], $violations);

mustContain($root . '/src/Service/Finalize/FinalizationService.php', [
    'normalizeVerifiedPayment',
    'lookupVerifiedResult',
    'lookupWebFormPaymentInternalId',
    'markWebFormPaymentAsApplied',
    'verification_pending',
    'GET_TRANSACTION_DETAILS',
    'SEARCH_RECEIVED_PAYMENTS',
    'MARK_WEBFORM_PAYMENT_APPLIED',
], $violations);
mustNotContain($root . '/src/Service/Finalize/FinalizationService.php', [
    'ProviderOperationResult::declined(',
    'ProviderOperationResult::cancelled(',
], $violations);

mustContain($root . '/src/Provider/Response/ResponseNormalizer.php', [
    'getMerchantTransactionDataResponse',
    'getMerchantTransactionDataResult',
    '$orderData->amountDue',
    "str_starts_with(\$collapsedStatus, 'unauth')",
    "['authorized', 'authonly']",
], $violations);
mustNotContain($root . '/src/Provider/Response/ResponseNormalizer.php', [
    "str_contains(\$flat, 'settings')",
    "str_contains(\$status, 'auth')",
], $violations);

mustContain($root . '/src/ValueObject/PluginConfig.php', [
    '$this->password',
], $violations);

mustContain($root . '/src/Service/StateSync/TransactionStateSyncService.php', [
    'IllegalTransitionException',
    'persistedState',
    'instanceof OrderTransactionEntity',
], $violations);
mustNotContain($root . '/src/Service/StateSync/TransactionStateSyncService.php', [
    'catch (\Throwable',
], $violations);

mustContain($root . '/src/Storage/Dal/DalTransactionRecordStore.php', [
    'TransactionRecordStoreInterface',
    'upsert',
    'EntityRepository',
], $violations);

mustContain($root . '/src/Migration/Migration1744318800CreateEbizChargeTables.php', [
    'ebizcharge_payment_transaction',
], $violations);
mustNotContain($root . '/src/Migration/Migration1744318800CreateEbizChargeTables.php', [
    'ebizcharge_payment_audit',
], $violations);

foreach ([
    $root . '/src/Service/Audit/AuditLogService.php',
    $root . '/src/Service/Audit/OrderPaymentSummaryService.php',
    $root . '/src/Storage/AuditStoreInterface.php',
    $root . '/src/Storage/Dbal/DbalAuditStore.php',
    $root . '/src/Controller/Api/AdminOrderSummaryController.php',
    $root . '/src/Resources/app/administration/src/module/extension/sw-order/view/sw-order-detail-general/index.js',
    $root . '/src/Resources/app/administration/src/module/extension/sw-order/view/sw-order-detail-general/sw-order-detail-general.html.twig',
    $root . '/src/Resources/config/packages/monolog.xml',
] as $removedFile) {
    if (file_exists($removedFile)) {
        $violations[] = 'Removed v1.0.0 surface unexpectedly exists: ' . $removedFile;
    }
}

mustContain($root . '/src/Resources/app/administration/src/service/ebizcharge-admin.service.js', [
    'ebizchargeAdminService',
    'test-connection',
], $violations);
mustContain($root . '/src/Resources/app/administration/src/component/ebizcharge-api-test/index.js', [
    'ebizcharge-api-test',
    'ebizchargeAdminService',
], $violations);
mustNotContain($root . '/src/Resources/app/administration/src', [
    'getOrderSummary',
    'monolog.logger.',
], $violations);

mustContain($root . '/README.md', [
    'Version `1.0.5`',
    'REST only',
    'Manual upload in Shopware Admin',
    'ebizcharge:test-connection',
    'password-only credential changes',
], $violations);
mustNotContain($root . '/README.md', [
    'dedicated logger channel `ebizcharge_payment`',
    'order detail page',
], $violations);

mustContain($root . '/CHANGELOG.md', [
    '## [1.0.5]',
    'Fixed saved bank-account checkout so payments complete with the selected saved account.',
    'Fixed hosted checkout and Pay by Link cleanup so completed payments are acknowledged in EBizCharge after Shopware accepts them.',
], $violations);
mustNotContain($root . '/CHANGELOG.md', [
    'Dedicated logger channel `ebizcharge_payment`',
], $violations);

mustContain($root . '/docs/architecture/plugin-architecture.md', [
    'v1.0.2',
    'src/Service/Connection/',
    'src/Storage/Dal/',
], $violations);
mustNotContain($root . '/docs/architecture/plugin-architecture.md', [
    'src/Service/Audit/',
    'ebizcharge_payment',
    'order-detail extension',
], $violations);

mustContain($root . '/docs/review/final-audit.md', [
    'v1.0.0',
    'service-graph',
], $violations);

mustContain($root . '/tools/self-test.php', [
    'saved-customer-transaction-payload-uses-rest-amount-and-ach-command',
    "same('Check', \$achPayload['command']",
    "\$payload['details']['amount']",
    'finalization-service-verifies-approved-return',
    'MARK_WEBFORM_PAYMENT_APPLIED',
    "payment-internal-id",
], $violations);

mustContain($root . '/composer.json', [
    '"version": "1.0.5"',
    '"shopware/core": ">=6.7.0.0 <6.8.0.0"',
    '"shopware/storefront": ">=6.7.0.0 <6.8.0.0"',
    '"shopware/administration": ">=6.7.0.0 <6.8.0.0"',
    '"plugin-icon": "src/Resources/config/plugin.png"',
    '"authors"',
    '"manufacturerLink"',
    '"supportLink"',
], $violations);
mustNotContain($root . '/composer.json', [
    'ext-soap',
], $violations);

if (!is_file($root . '/src/Resources/config/plugin.png')) {
    $violations[] = 'Missing required plugin icon file: src/Resources/config/plugin.png';
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

echo "Guardrail validation passed.\n";
