<?php

declare(strict_types=1);

namespace EbizChargeShopware\Installer;

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

    public function ensurePaymentMethod(string $pluginId, Context $context, bool $active = false): void
    {
        $id = $this->paymentMethodId($context);

        $payload = [
            'handlerIdentifier' => CreditCardPaymentHandler::class,
            'name' => 'eBizCharge Credit Card',
            'description' => 'Hosted eBizCharge REST checkout for new credit-card payments.',
            'afterOrderEnabled' => true,
            'pluginId' => $pluginId,
            'technicalName' => EbizChargeShopware::CREDIT_CARD_TECHNICAL_NAME,
            'active' => $active,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $this->paymentMethodRepository->update([$payload], $context);

            return;
        }

        $this->paymentMethodRepository->create([$payload], $context);
    }

    public function ensurePayByLinkPaymentMethod(string $pluginId, Context $context): void
    {
        $id = $this->payByLinkPaymentMethodId($context);

        $payload = [
            'handlerIdentifier' => PayByLinkPaymentHandler::class,
            'name'              => 'eBizCharge Pay by Link',
            'description'       => 'Admin sends a hosted eBizCharge payment link to the customer by email.',
            'afterOrderEnabled' => false,
            'pluginId'          => $pluginId,
            'technicalName'     => EbizChargeShopware::PAY_BY_LINK_TECHNICAL_NAME,
            'active'            => false,
        ];

        if ($id !== null) {
            $payload['id'] = $id;
            $this->paymentMethodRepository->update([$payload], $context);

            return;
        }

        $this->paymentMethodRepository->create([$payload], $context);
    }

    public function setPaymentMethodActive(bool $active, Context $context): void
    {
        $id = $this->paymentMethodId($context);

        if ($id === null) {
            return;
        }

        $this->paymentMethodRepository->update([
            ['id' => $id, 'active' => $active],
        ], $context);
    }

    private function payByLinkPaymentMethodId(Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', EbizChargeShopware::PAY_BY_LINK_TECHNICAL_NAME))
            ->setLimit(1);

        return $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
    }

    private function paymentMethodId(Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', EbizChargeShopware::CREDIT_CARD_TECHNICAL_NAME))
            ->setLimit(1);

        return $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
    }
}
