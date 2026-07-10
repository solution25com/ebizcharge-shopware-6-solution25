<?php

declare(strict_types=1);

namespace EbizChargeShopware\Controller\Api;

use EbizChargeShopware\Service\PaymentLinkService;
use EbizChargeShopware\Storage\TransactionRecordStoreInterface;
use Psr\Log\LoggerInterface;
use EbizChargeShopware\Service\Configuration\PluginConfigProvider;
use EbizChargeShopware\Service\Connection\ConnectionTestService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class AdminDiagnosticsController
{
    public function __construct(
        private readonly PluginConfigProvider $pluginConfigProvider,
        private readonly ConnectionTestService $connectionTestService,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly TransactionRecordStoreInterface $transactionRecordStore,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/api/_action/ebizcharge/test-connection',
        name: 'api.action.ebizcharge.test-connection',
        methods: ['POST'],
        defaults: ['_acl' => ['system_config:read', 'system_config:update']]
    )]
    public function testConnection(Request $request): JsonResponse
    {
        $salesChannelId = $request->request->getString('salesChannelId', '');
        $config = $this->pluginConfigProvider->get($salesChannelId !== '' ? $salesChannelId : null);
        $result = $this->connectionTestService->test($config, $salesChannelId !== '' ? $salesChannelId : null);

        return new JsonResponse($result->toArray());
    }

    #[Route(
        path: '/api/_action/ebizcharge/payment-link/re-send',
        name: 'api.action.ebizcharge.payment-link.re-send',
        methods: ['POST'],
        defaults: ['_acl' => ['order:read', 'order:update']]
    )]
    public function resendPaymentLink(Request $request, Context $context): JsonResponse
    {
        $orderTransactionId = $request->request->getString('orderTransactionId');

        if ($orderTransactionId === '') {
            return new JsonResponse(['success' => false, 'message' => 'Missing orderTransactionId.'], 400);
        }

        try {
            $this->paymentLinkService->resend($orderTransactionId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('EBizCharge payment link re-send failed.', [
                'orderTransactionId' => $orderTransactionId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Payment link could not be sent. Check system logs for details.',
            ], 500);
        }

        return new JsonResponse(['success' => true, 'message' => 'Payment link sent.']);
    }

    #[Route(
        path: '/api/_action/ebizcharge/payment-transaction',
        name: 'api.action.ebizcharge.payment-transaction',
        methods: ['POST'],
        defaults: ['_acl' => ['order:read']]
    )]
    public function paymentTransaction(Request $request, Context $context): JsonResponse
    {
        $orderTransactionId = $request->request->getString('orderTransactionId');

        if ($orderTransactionId === '') {
            return new JsonResponse(['success' => false, 'message' => 'Missing orderTransactionId.'], 400);
        }

        $record = $this->transactionRecordStore->find($orderTransactionId, $context);

        return new JsonResponse([
            'success' => true,
            'providerRefNum' => $record['provider_ref_num'] ?? null,
            'providerAuthCode' => $record['provider_auth_code'] ?? null,
        ]);
    }
}
