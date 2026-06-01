<?php

declare(strict_types=1);

namespace EbizChargeShopware;

use EbizChargeShopware\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class EbizChargeShopware extends Plugin
{
    public const CREDIT_CARD_TECHNICAL_NAME = 'ebizcharge_credit_card';
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
        $this->paymentMethodInstaller()->setPaymentMethodActive(false, $uninstallContext->getContext());

        parent::uninstall($uninstallContext);
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
