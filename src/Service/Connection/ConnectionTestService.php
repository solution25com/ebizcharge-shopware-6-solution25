<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Connection;

use EbizChargeShopware\Provider\Client\ProviderClientInterface;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Response\ResponseNormalizer;
use EbizChargeShopware\Exception\ConfigurationException;
use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\ValueObject\ConnectionTestResult;
use EbizChargeShopware\ValueObject\PluginConfig;
use Psr\Log\LoggerInterface;

final class ConnectionTestService
{
    public function __construct(
        private readonly ProviderClientInterface $providerClient,
        private readonly ResponseNormalizer $responseNormalizer,
        private readonly ConnectionHealthRegistry $connectionHealthRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function test(PluginConfig $config, ?string $salesChannelId = null): ConnectionTestResult
    {
        $started = microtime(true);
        $testedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $statusCode = null;
        $failureCategory = null;

        try {
            $config->assertComplete();
            $response = $this->providerClient->send(ProviderOperation::CONNECTION_TEST, [], $config);
            $statusCode = $response['statusCode'];
            $success = $this->responseNormalizer->connectionTestSucceeded($response['body']);
            $failureCategory = $success ? null : 'provider_response';
            $message = $success
                ? 'Connection test passed.'
                : 'Connection test did not return the expected merchant settings payload.';
        } catch (ConfigurationException $exception) {
            $success = false;
            $message = $exception->getMessage();
            $failureCategory = 'configuration';
        } catch (ProviderCommunicationException $exception) {
            $success = false;
            $message = $exception->getMessage();
            $failureCategory = $this->classifyProviderFailure($exception);
            $statusCode = $exception->getCode() > 0 ? $exception->getCode() : null;
        } catch (\Throwable $exception) {
            $success = false;
            $message = 'Unexpected connection-test failure.';
            $failureCategory = 'unexpected';
            $this->logger->error('Unexpected EBizCharge connection-test failure.', [
                'environment' => $config->environmentMode(),
                'message' => $exception->getMessage(),
            ]);
        }

        $durationMs = round((microtime(true) - $started) * 1000, 2);

        $result = new ConnectionTestResult(
            $success,
            $message,
            $config->environmentMode(),
            $config->baseUrl(),
            $config->credentialFingerprint(),
            $testedAt,
            $failureCategory,
            $statusCode,
            $durationMs
        );

        $this->connectionHealthRegistry->markResult($result, $config, $salesChannelId);

        return $result;
    }

    private function classifyProviderFailure(ProviderCommunicationException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'http 401') || str_contains($message, 'http 403')) {
            return 'provider_authentication';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'transport')) {
            return 'network';
        }

        return 'provider_technical';
    }
}
