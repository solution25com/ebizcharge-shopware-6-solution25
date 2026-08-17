<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service;

use EbizChargeShopware\Core\Content\EbizchargePaymentLink\EbizchargePaymentLinkEntity;
use EbizChargeShopware\Checkout\Payment\Handler\PayByLinkPaymentHandler;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\Service\Checkout\HostedCheckoutService;
use EbizChargeShopware\Service\Checkout\OrderTransactionLoader;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Storage\Dal\DalSearchResultHelper;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Symfony\Component\HttpFoundation\RequestStack;

final class PaymentLinkService
{
    public function __construct(
        private readonly EntityRepository $paymentLinkRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionLoader $orderTransactionLoader,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly PluginConfigProvider $configProvider,
        private readonly MailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly RequestStack $requestStack
    ) {
    }

    public function isPayByLinkTransaction(string $orderTransactionId, Context $context): bool
    {
        $criteria = (new Criteria([$orderTransactionId]))->addAssociation('paymentMethod');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = DalSearchResultHelper::first($this->orderTransactionRepository->search($criteria, $context));

        return $transaction?->getPaymentMethod()?->getHandlerIdentifier() === PayByLinkPaymentHandler::class;
    }

    public function findByOrderTransactionId(string $orderTransactionId, Context $context): ?array
    {
        $entity = DalSearchResultHelper::first(
            $this->paymentLinkRepository->search(new Criteria([$orderTransactionId]), $context)
        );

        if (!$entity instanceof EbizchargePaymentLinkEntity) {
            return null;
        }

        return [
            'order_transaction_id' => $entity->getOrderTransactionId(),
            'order_id' => $entity->getOrderId(),
            'link' => $entity->getLink(),
        ];
    }

    public function createAndSend(string $orderTransactionId, Context $context): void
    {
        $link = $this->generateLink($orderTransactionId, $context);
        $this->sendEmail($link, $orderTransactionId, $context);
    }

    public function resend(string $orderTransactionId, Context $context): void
    {
        $link = $this->generateLink($orderTransactionId, $context);
        $this->sendEmail($link, $orderTransactionId, $context);
    }

    private function generateLink(string $orderTransactionId, Context $context): string
    {
        $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);
        $config = $this->configProvider->get($orderData->salesChannelId);

        $request = $this->requestStack->getCurrentRequest();
        $baseUrl = $request ? $request->getSchemeAndHttpHost() : '';

        $returnUrl = $baseUrl . '/ebizcharge-payment-link-return?transactionId=' . urlencode($orderTransactionId);
        $redirect = $this->hostedCheckoutService->start($orderData, $config, $returnUrl, $context, formType: ProviderContract::PAY_LINK_ONLY_FORM_TYPE);

        $this->paymentLinkRepository->upsert([
            [
                'orderTransactionId' => $orderTransactionId,
                'orderId' => $orderData->orderId,
                'link' => $redirect->redirectUrl,
            ],
        ], $context);

        return $redirect->redirectUrl;
    }

    private function sendEmail(string $link, string $orderTransactionId, Context $context): void
    {
        $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'ebizcharge.admin.payment_link'));
        $criteria->addAssociation('translations');
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = DalSearchResultHelper::first($this->mailTemplateRepository->search($criteria, $context));

        if ($mailTemplate === null) {
            throw new \RuntimeException(
                'EBizCharge payment link mail template not found. Run: bin/console database:migrate --all EbizChargeShopware'
            );
        }

        $contentHtml  = $mailTemplate->getContentHtml();
        $contentPlain = $mailTemplate->getContentPlain();
        $subject      = $mailTemplate->getSubject();

        if ($contentHtml === null || $contentPlain === null || $subject === null) {
            throw new \RuntimeException('EBizCharge payment link mail template has no content.');
        }

        [$firstName, $lastName] = $this->splitName($orderData->customerFullName);

        $data = new DataBag();
        $data->set('recipients', [$orderData->customerEmail => $orderData->customerFullName]);
        $data->set('senderName', $mailTemplate->getSenderName() ?? 'EBizCharge');
        $data->set('salesChannelId', $orderData->salesChannelId);
        $data->set('contentHtml', $contentHtml);
        $data->set('contentPlain', $contentPlain);
        $data->set('subject', $subject);

        $this->mailService->send($data->all(), $context, [
            'paymentLink' => $link,
            'orderNumber' => $orderData->orderNumber,
            'firstName'   => $firstName,
            'lastName'    => $lastName,
        ]);
    }

    /** @return array{string, string} */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', $fullName, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
