<?php

declare(strict_types=1);

namespace EbizChargeShopware\Controller\Storefront;

use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionHealthRegistry;
use EbizChargeShopware\Service\EbizChargeCustomerVaultService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class EbizChargeSavedCardsController extends StorefrontController
{
    public function __construct(
        private readonly EbizChargeCustomerVaultService $customerVaultService,
        private readonly PluginConfigProvider $configProvider,
        private readonly ConnectionHealthRegistry $connectionHealthRegistry
    ) {
    }

    #[Route(
        path: '/account/ebizcharge/saved-cards',
        name: 'frontend.account.ebizcharge.saved-cards.index',
        methods: ['GET'],
        defaults: ['_loginRequired' => true]
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        if (!$this->isConnectionReady($context)) {
            $this->addFlash('info', $this->trans('ebizcharge.account.configurationUnavailable'));

            return $this->redirectUnavailable();
        }

        $customerVault = $this->customerVaultService->ensureVault($context);
        $savedPaymentMethods = $this->customerVaultService->getSavedPaymentMethodsForDisplay(
            $customerVault,
            $context->getContext()
        );
        $addCardUrl = null;
        if ((string) $request->query->get('addCard', '') === '1') {
            try {
                $addCardUrl = $this->customerVaultService->getAccountAddPaymentMethodHostedUrl($context);
            } catch (\Throwable) {
                $this->addFlash('danger', $this->trans('ebizcharge.account.addStartFailed'));
            }
        }

        return $this->renderStorefront('@EbizChargeShopware/storefront/page/account/ebizcharge-saved-cards.html.twig', [
            'savedPaymentMethods' => $savedPaymentMethods,
            'addCardUrl' => $addCardUrl,
            'ebizchargeSavedCardsAvailable' => true,
        ]);
    }

    #[Route(
        path: '/account/ebizcharge/saved-cards/delete',
        name: 'frontend.account.ebizcharge.saved-cards.delete',
        methods: ['POST'],
        defaults: ['_loginRequired' => true]
    )]
    public function delete(Request $request, SalesChannelContext $context): Response
    {
        $this->requireCustomer($context);

        if (!$this->isConnectionReady($context)) {
            $this->addFlash('danger', $this->trans('ebizcharge.account.configurationUnavailable'));

            return $this->redirectUnavailable();
        }

        $methodId = (string) $request->request->get('savedMethodId', '');
        if ($methodId === '') {
            $this->addFlash('danger', $this->trans('ebizcharge.account.deleteMissing'));

            return $this->redirectSaved();
        }

        $customerVault = $this->customerVaultService->ensureVault($context);
        try {
            $this->customerVaultService->deleteSavedMethod($customerVault, $methodId, $context->getContext());
            $this->addFlash('success', $this->trans('ebizcharge.account.deleteSuccess'));
        } catch (\Throwable) {
            $this->addFlash('danger', $this->trans('ebizcharge.account.deleteFailed'));
        }

        return $this->redirectSaved();
    }

    #[Route(
        path: '/account/ebizcharge/saved-cards/default',
        name: 'frontend.account.ebizcharge.saved-cards.default',
        methods: ['POST'],
        defaults: ['_loginRequired' => true]
    )]
    public function setDefault(Request $request, SalesChannelContext $context): Response
    {
        $this->requireCustomer($context);

        if (!$this->isConnectionReady($context)) {
            $this->addFlash('danger', $this->trans('ebizcharge.account.configurationUnavailable'));

            return $this->redirectUnavailable();
        }

        $methodId = (string) $request->request->get('savedMethodId', '');
        if ($methodId === '') {
            $this->addFlash('danger', $this->trans('ebizcharge.account.defaultMissing'));

            return $this->redirectSaved();
        }

        $customerVault = $this->customerVaultService->ensureVault($context);
        try {
            $this->customerVaultService->setDefaultSavedMethod($customerVault, $methodId, $context->getContext());
            $this->addFlash('success', $this->trans('ebizcharge.account.defaultSuccess'));
        } catch (\Throwable) {
            $this->addFlash('danger', $this->trans('ebizcharge.account.defaultFailed'));
        }

        return $this->redirectSaved();
    }

    #[Route(
        path: '/account/ebizcharge/saved-cards/add',
        name: 'frontend.account.ebizcharge.saved-cards.add',
        methods: ['GET'],
        defaults: ['_loginRequired' => true]
    )]
    public function addCard(SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        if (!$this->isConnectionReady($context)) {
            return $this->redirectUnavailable();
        }

        return $this->redirectToRoute('frontend.account.ebizcharge.saved-cards.index', ['addCard' => '1']);
    }

    private function redirectSaved(): Response
    {
        return $this->redirectToRoute('frontend.account.ebizcharge.saved-cards.index');
    }

    private function redirectUnavailable(): Response
    {
        return $this->redirectToRoute('frontend.account.home.page');
    }

    private function requireCustomer(SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function isConnectionReady(SalesChannelContext $context): bool
    {
        $config = $this->configProvider->get($context->getSalesChannelId());

        return $config->hasCompleteCredentials()
            && $this->connectionHealthRegistry->hasSuccessfulTest($config, $context->getSalesChannelId());
    }
}
