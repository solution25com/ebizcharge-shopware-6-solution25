<?php

declare(strict_types=1);

namespace EbizChargeShopware;

use Doctrine\DBAL\Connection;
use EbizChargeShopware\Installer\PaymentMethodInstaller;
use EbizChargeShopware\Provider\Client\RestProviderClient;
use EbizChargeShopware\Provider\Client\SymfonyHttpProviderTransport;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\SecurityTokenPayloadFactory;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\ValueObject\PluginConfig;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\System\SystemConfig\SystemConfigEntity;
use Symfony\Component\HttpClient\HttpClient;

class EbizChargeShopware extends Plugin
{
    public const CREDIT_CARD_TECHNICAL_NAME = 'ebizcharge_credit_card';
    public const ACH_TECHNICAL_NAME = 'ebizcharge_ach';
    public const PAY_BY_LINK_TECHNICAL_NAME = 'ebizcharge_pay_by_link';

    public function install(InstallContext $installContext): void
    {
        $installer = $this->paymentMethodInstaller();
        $pluginId  = $this->pluginId($installContext->getContext());
        $context   = $installContext->getContext();

        $installer->ensurePaymentMethod($pluginId, $context, false);
        $installer->ensurePayByLinkPaymentMethod($pluginId, $context);

        parent::install($installContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        $installer = $this->paymentMethodInstaller();
        $pluginId  = $this->pluginId($updateContext->getContext());
        $context   = $updateContext->getContext();

        $installer->ensurePaymentMethod($pluginId, $context, false);
        $installer->ensurePayByLinkPaymentMethod($pluginId, $context);

        parent::update($updateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->paymentMethodInstaller()->setPaymentMethodActive(false, $deactivateContext->getContext());

        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $context = $uninstallContext->getContext();
        $keepUserData = $uninstallContext->keepUserData();

        $this->paymentMethodInstaller()->setPaymentMethodActive(false, $context);

        if (!$keepUserData) {
            $this->deleteOutstandingHostedRequests($context);
            $this->dropPluginTables();
            $this->deletePluginConfiguration($context);
        }

        parent::uninstall($uninstallContext);
    }

    private function deleteOutstandingHostedRequests(Context $context): void
    {
        $logger = $this->logger();

        $requests = $this->outstandingHostedRequests();
        if ($requests === []) {
            return;
        }

        $providerClient = new RestProviderClient(
            new SymfonyHttpProviderTransport(HttpClient::create()),
            new SecurityTokenPayloadFactory(),
            $logger
        );
        $failures = [];

        foreach ($requests as $request) {
            try {
                $providerClient->send(
                    ProviderOperation::DELETE_WEBFORM_PAYMENT,
                    ['paymentInternalId' => $request['paymentInternalId']],
                    $this->pluginConfigForCleanup($request['salesChannelId'], $context)
                );
            } catch (\Throwable $exception) {
                $failures[] = $request['paymentInternalId'];
                $logger->error('Could not delete EBizCharge hosted webform request during uninstall.', $request + [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException(sprintf(
                'Could not delete %d outstanding EBizCharge hosted webform request(s). Plugin data was kept.',
                \count($failures)
            ));
        }
    }

    private function pluginConfigForCleanup(?string $salesChannelId, Context $context): PluginConfig
    {
        $values = $this->configValuesForCleanup($salesChannelId, $context);
        $environment = (string) ($values[PluginConfigProvider::DOMAIN . 'environmentMode'] ?? 'sandbox');
        $prefix = $environment === 'production' ? 'production' : 'sandbox';

        return new PluginConfig(
            $environment,
            (string) ($values[PluginConfigProvider::DOMAIN . $prefix . 'BaseUrl'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . $prefix . 'SecurityId'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . $prefix . 'UserId'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . $prefix . 'Password'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . $prefix . 'SubscriptionKey'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . 'shipFromZip'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . 'processingCommand'] ?? 'Sale'),
            (int) ($values[PluginConfigProvider::DOMAIN . 'verificationLookbackDays'] ?? 7),
            (int) ($values[PluginConfigProvider::DOMAIN . 'connectionTimeoutSeconds'] ?? 20),
            (int) ($values[PluginConfigProvider::DOMAIN . 'retryCount'] ?? 1),
            (string) ($values[PluginConfigProvider::DOMAIN . 'descriptionTemplate'] ?? 'Order {{ orderNumber }}'),
            (bool) ($values[PluginConfigProvider::DOMAIN . 'enforceAvsCheck'] ?? false),
            (string) ($values[PluginConfigProvider::DOMAIN . 'webhookBasicUsername'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . 'webhookBasicPassword'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . 'webhookSignatureKey'] ?? ''),
            (string) ($values[PluginConfigProvider::DOMAIN . 'paymentFlow'] ?? PluginConfig::FLOW_REDIRECT)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function configValuesForCleanup(?string $salesChannelId, Context $context): array
    {
        if (!$this->container->has('system_config.repository')) {
            return [];
        }

        /** @var EntityRepository $repository */
        $repository = $this->container->get('system_config.repository');
        $criteria = (new Criteria())
            ->addFilter(new PrefixFilter('configurationKey', PluginConfigProvider::DOMAIN));

        $result = $repository->search($criteria, $context);
        $values = [];

        foreach ($result as $entity) {
            if (!$entity instanceof SystemConfigEntity) {
                continue;
            }

            if ($entity->getSalesChannelId() !== null) {
                continue;
            }

            $values[$entity->getConfigurationKey()] = $entity->getConfigurationValue();
        }

        if ($salesChannelId === null) {
            return $values;
        }

        foreach ($result as $entity) {
            if (!$entity instanceof SystemConfigEntity) {
                continue;
            }

            if ($entity->getSalesChannelId() !== $salesChannelId) {
                continue;
            }

            $values[$entity->getConfigurationKey()] = $entity->getConfigurationValue();
        }

        return $values;
    }

    private function dropPluginTables(): void
    {
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        foreach (
            [
                'ebizcharge_payment_link',
                'ebizcharge_payment_transaction',
                'ebizcharge_vaulted_customer',
                'ebizcharge_saved_payment_method',
                'ebizcharge_customer_vault',
            ] as $table
        ) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    private function deletePluginConfiguration(Context $context): void
    {
        if (!$this->container->has('system_config.repository')) {
            return;
        }

        /** @var EntityRepository $repository */
        $repository = $this->container->get('system_config.repository');
        $criteria = (new Criteria())
            ->addFilter(new PrefixFilter('configurationKey', 'EbizChargeShopware.config.'));

        $ids = $repository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }

        $repository->delete(array_map(static fn (string $id): array => ['id' => $id], $ids), $context);
    }

    /**
     * @return list<array{
     *     paymentInternalId: string,
     *     orderTransactionId: ?string,
     *     orderNumber: ?string,
     *     salesChannelId: ?string
     * }>
     */
    private function outstandingHostedRequests(): array
    {
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        if (!$this->transactionTableHasActivePaymentInternalId($connection)) {
            $this->logger()->info(
                'Skipping EBizCharge hosted webform cleanup; transaction table or active request column does not exist.'
            );

            return [];
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT active_payment_internal_id AS paymentInternalId,
                order_transaction_id AS orderTransactionId,
                order_number AS orderNumber,
                sales_channel_id AS salesChannelId
             FROM `ebizcharge_payment_transaction`
             WHERE active_payment_internal_id IS NOT NULL
                AND active_payment_internal_id <> ""'
        );

        $requests = [];

        foreach ($rows as $row) {
            $paymentInternalId = $row['paymentInternalId'] ?? null;
            if (!\is_scalar($paymentInternalId) || trim((string) $paymentInternalId) === '') {
                continue;
            }

            $orderTransactionId = $row['orderTransactionId'] ?? null;
            $orderNumber = $row['orderNumber'] ?? null;
            $salesChannelId = $row['salesChannelId'] ?? null;

            $requests[] = [
                'paymentInternalId' => trim((string) $paymentInternalId),
                'orderTransactionId' => \is_scalar($orderTransactionId) && trim((string) $orderTransactionId) !== ''
                    ? trim((string) $orderTransactionId)
                    : null,
                'orderNumber' => \is_scalar($orderNumber) && trim((string) $orderNumber) !== ''
                    ? trim((string) $orderNumber)
                    : null,
                'salesChannelId' => \is_scalar($salesChannelId) && trim((string) $salesChannelId) !== ''
                    ? trim((string) $salesChannelId)
                    : null,
            ];
        }

        return $requests;
    }

    private function transactionTableHasActivePaymentInternalId(Connection $connection): bool
    {
        try {
            $schemaManager = $connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['ebizcharge_payment_transaction'])) {
                return false;
            }

            return isset($schemaManager->listTableColumns('ebizcharge_payment_transaction')['active_payment_internal_id']);
        } catch (\Throwable $exception) {
            $this->logger()->error('Could not inspect EBizCharge transaction table before uninstall cleanup.', [
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(
                'Could not inspect EBizCharge transaction table before uninstall cleanup. Plugin data was kept.',
                0,
                $exception
            );
        }
    }

    private function logger(): LoggerInterface
    {
        if (!$this->container->has('EbizChargeShopware.logger')) {
            $logger = new Logger('EbizCharge');
            $logger->pushHandler(new RotatingFileHandler(
                $this->container->getParameter('kernel.logs_dir') . '/EbizChargeShopware-' .
                $this->container->getParameter('kernel.environment') . '.log'
            ));

            return $logger;
        }

        /** @var LoggerInterface $logger */
        $logger = $this->container->get('EbizChargeShopware.logger');

        return $logger;
    }

    private function paymentMethodInstaller(): PaymentMethodInstaller
    {
        if ($this->container->has(PaymentMethodInstaller::class)) {
            /** @var PaymentMethodInstaller $installer */
            $installer = $this->container->get(PaymentMethodInstaller::class);

            return $installer;
        }

        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        return new PaymentMethodInstaller($paymentMethodRepository);
    }

    private function pluginId(Context $context): string
    {
        /** @var PluginIdProvider $provider */
        $provider = $this->container->get(PluginIdProvider::class);

        return $provider->getPluginIdByBaseClass(self::class, $context);
    }
}
