<?php

declare(strict_types=1);

namespace EbizChargeShopware\Controller\Storefront;

use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\Service\Checkout\CartCheckoutDataBuilder;
use EbizChargeShopware\Service\Checkout\EmbeddedCheckoutService;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class EmbeddedCheckoutController extends StorefrontController
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly CartCheckoutDataBuilder $cartCheckoutDataBuilder,
        private readonly EmbeddedCheckoutService $embeddedCheckoutService,
        private readonly CartService $cartService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/ebizcharge/checkout/form-url',
        name: 'frontend.ebizcharge.checkout.form-url',
        methods: ['GET'],
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false]
    )]
    public function formUrl(Request $request, SalesChannelContext $context): JsonResponse
    {
        $config = $this->configProvider->get($context->getSalesChannelId());
        if (!$config->isEmbeddedFlow()) {
            return new JsonResponse(['error' => 'not_enabled'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $config->assertComplete();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'not_configured'], Response::HTTP_BAD_REQUEST);
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->getPrice()->getTotalPrice() <= 0.0) {
            return new JsonResponse(['error' => 'empty_cart'], Response::HTTP_BAD_REQUEST);
        }

        $lookupKey = Uuid::randomHex();

        try {
            $orderData = $this->cartCheckoutDataBuilder->build($cart, $context, $lookupKey);
            $returnBaseUrl = $this->generateUrl(
                'frontend.ebizcharge.checkout.embedded-return',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $url = $this->embeddedCheckoutService->createFormUrl(
                $orderData,
                $config,
                $returnBaseUrl,
                $orderData->guest ? ProviderContract::CHECKOUT_GUEST_FORM_TYPE : ProviderContract::WEBFORM_TYPE,
                $this->payByType($request)
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Could not create embedded EBizCharge webform URL.', [
                'salesChannelId' => $context->getSalesChannelId(),
                'message' => $exception->getMessage(),
            ]);

            return new JsonResponse(['error' => 'form_url_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'url' => $url,
            'lookupKey' => $lookupKey,
            'amount' => round($cart->getPrice()->getTotalPrice(), 2),
            'currency' => $orderData->currencyIso,
        ]);
    }

    #[Route(
        path: '/ebizcharge/checkout/embedded-return',
        name: 'frontend.ebizcharge.checkout.embedded-return',
        methods: ['GET'],
        defaults: ['_httpCache' => false]
    )]
    public function embeddedReturn(Request $request): Response
    {
        $outcome = strtolower(trim((string) $request->query->get(ProviderContract::BROWSER_RESULT_QUERY_PARAM, '')));
        $reference = (string) (
            $request->query->get('refNum')
            ?? $request->query->get('RefNum')
            ?? $request->query->get('transactionRefNum')
            ?? ''
        );

        $response = $this->renderStorefront('@EbizChargeShopware/storefront/page/embedded-return.html.twig', [
            'outcome' => $outcome === '' ? 'unknown' : $outcome,
            'reference' => $reference,
        ]);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    private function payByType(Request $request): string
    {
        return strtolower((string) $request->query->get('paymentType')) === 'ach'
            ? ProviderContract::PAY_BY_TYPE_ACH
            : ProviderContract::PAY_BY_TYPE_CREDIT_CARD;
    }
}
