<?php

declare(strict_types=1);

namespace EbizChargeShopware\Installer;

use EbizChargeShopware\Checkout\Payment\Handler\AchPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\CreditCardPaymentHandler;
use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use EbizChargeShopware\EbizChargeShopware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

final class PaymentMethodInstaller
{
    public function __construct(private readonly EntityRepository $paymentMethodRepository)
    {
    }

    public function ensurePaymentMethods(string $pluginId, Context $context, bool $active = false): void
    {
        $this->upsertPaymentMethod(
            CreditCardPaymentHandler::class,
            EbizChargeShopware::CREDIT_CARD_TECHNICAL_NAME,
            'EBizCharge Credit Card',
            "Complete your credit card payment through EBizCharge's secure checkout.",
            $pluginId,
            $context,
            $active
        );
        $this->upsertPaymentMethod(
            AchPaymentHandler::class,
            EbizChargeShopware::ACH_TECHNICAL_NAME,
            'EBizCharge ACH',
            "Complete your ACH payment through EBizCharge's secure checkout.",
            $pluginId,
            $context,
            $active
        );
    }

    public function ensurePaymentMethod(string $pluginId, Context $context, bool $active = false): void
    {
        $this->ensurePaymentMethods($pluginId, $context, $active);
    }

    private function upsertPaymentMethod(
        string $handlerIdentifier,
        string $technicalName,
        string $name,
        string $description,
        string $pluginId,
        Context $context,
        bool $active
    ): void {
        $id = $this->paymentMethodId($technicalName, $context);

        $payload = [
            'handlerIdentifier' => $handlerIdentifier,
            'name' => $name,
            'description' => $description,
            'afterOrderEnabled' => true,
            'pluginId' => $pluginId,
            'technicalName' => $technicalName,
            'active' => $active,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            unset($payload['active']);
            $this->paymentMethodRepository->update([$payload], $context);

            return;
        }

        $this->paymentMethodRepository->create([$payload], $context);
    }

    public function ensurePayByLinkPaymentMethod(string $pluginId, Context $context): void
    {
        $id = $this->paymentMethodId(EbizChargeShopware::PAY_BY_LINK_TECHNICAL_NAME, $context);

        $payload = [
            'handlerIdentifier' => PayByLinkPaymentHandler::class,
            'name'              => 'EBizCharge Pay by Link',
            'description'       => 'Admin sends a hosted EBizCharge payment link to the customer by email.',
            'afterOrderEnabled' => false,
            'pluginId'          => $pluginId,
            'technicalName'     => EbizChargeShopware::PAY_BY_LINK_TECHNICAL_NAME,
            'active'            => false,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            unset($payload['active']);
            $this->paymentMethodRepository->update([$payload], $context);

            return;
        }

        $this->paymentMethodRepository->create([$payload], $context);
    }

    public function setPaymentMethodActive(bool $active, Context $context): void
    {
        $paymentMethodUpdates = [];

        $creditCardPaymentMethodId = $this->paymentMethodId(EbizChargeShopware::CREDIT_CARD_TECHNICAL_NAME, $context);
        if ($creditCardPaymentMethodId !== null) {
            $paymentMethodUpdates[] = [
                'id' => $creditCardPaymentMethodId,
                'active' => $active,
            ];
        }

        $achPaymentMethodId = $this->paymentMethodId(EbizChargeShopware::ACH_TECHNICAL_NAME, $context);
        if ($achPaymentMethodId !== null) {
            $paymentMethodUpdates[] = [
                'id' => $achPaymentMethodId,
                'active' => $active,
            ];
        }

        $payByLinkPaymentMethodId = $this->paymentMethodId(EbizChargeShopware::PAY_BY_LINK_TECHNICAL_NAME, $context);
        if ($payByLinkPaymentMethodId !== null) {
            $paymentMethodUpdates[] = [
                'id' => $payByLinkPaymentMethodId,
                'active' => $active,
            ];
        }

        if ($paymentMethodUpdates !== []) {
            $this->paymentMethodRepository->update($paymentMethodUpdates, $context);
        }
    }

    private function paymentMethodId(string $technicalName, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', $technicalName))
            ->setLimit(1);

        return $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
    }
}
