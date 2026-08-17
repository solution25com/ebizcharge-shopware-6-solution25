<?php

declare(strict_types=1);

namespace EbizChargeShopware\Controller\Storefront;

use EbizChargeShopware\Service\Checkout\OrderTransactionLoader;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Finalize\FinalizationService;
use EbizChargeShopware\Service\PaymentLinkService;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\ValueObject\ProviderOperationResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class PaymentLinkReturnController
{
    public function __construct(
        private readonly OrderTransactionLoader $orderTransactionLoader,
        private readonly PluginConfigProvider $configProvider,
        private readonly FinalizationService $finalizationService,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly LoggerInterface $logger,
        private readonly Environment $twig,
        private readonly RouterInterface $router
    ) {
    }

    #[Route(
        path: '/ebizcharge-payment-link-return',
        name: 'frontend.ebizcharge.payment-link.return',
        methods: ['GET']
    )]
    public function return(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $orderTransactionId = trim($request->query->getString('transactionId'));

        if ($orderTransactionId === '') {
            return new RedirectResponse($this->router->generate('frontend.ebizcharge.payment-link.fail'));
        }

        try {
            $context   = $salesChannelContext->getContext();
            if (!$this->paymentLinkService->isPayByLinkTransaction($orderTransactionId, $context)) {
                return new RedirectResponse($this->router->generate('frontend.ebizcharge.payment-link.fail'));
            }

            $orderData = $this->orderTransactionLoader->load($orderTransactionId, $context);
            $config    = $this->configProvider->get($orderData->salesChannelId);
            $outcome   = $this->finalizationService->finalize($request, $orderData, $config, $context, ProviderContract::PAY_LINK_ONLY_FORM_TYPE);

            if ($outcome->result->outcome === ProviderOperationResult::OUTCOME_APPROVED) {
                return new RedirectResponse($this->router->generate('frontend.ebizcharge.payment-link.success'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('EBizCharge payment-link return error.', [
                'transactionId' => $orderTransactionId,
                'error'         => $e->getMessage(),
            ]);
        }

        return new RedirectResponse($this->router->generate('frontend.ebizcharge.payment-link.fail'));
    }

    #[Route(
        path: '/ebizcharge-payment-link-success',
        name: 'frontend.ebizcharge.payment-link.success',
        methods: ['GET']
    )]
    public function success(): Response
    {
        return new Response(
            $this->twig->render('@EbizChargeShopware/storefront/page/payment-link-success.html.twig')
        );
    }

    #[Route(
        path: '/ebizcharge-payment-link-fail',
        name: 'frontend.ebizcharge.payment-link.fail',
        methods: ['GET']
    )]
    public function fail(): Response
    {
        return new Response(
            $this->twig->render('@EbizChargeShopware/storefront/page/payment-link-fail.html.twig')
        );
    }
}
