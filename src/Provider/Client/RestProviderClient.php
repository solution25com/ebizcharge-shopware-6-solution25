<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Client;

use EbizChargeShopware\Exception\ProviderCommunicationException;
use EbizChargeShopware\Provider\ProviderContract;
use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\Provider\Request\SecurityTokenPayloadFactory;
use EbizChargeShopware\ValueObject\PluginConfig;
use Psr\Log\LoggerInterface;

final class RestProviderClient implements ProviderClientInterface
{
    public function __construct(
        private readonly ProviderTransportInterface $transport,
        private readonly SecurityTokenPayloadFactory $securityTokenPayloadFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array
    {
        $config->assertComplete();

        $requestBody = [
            $operation->bodyRoot() => array_merge(
                ['securityToken' => $this->securityTokenPayloadFactory->create($config)],
                $payload
            ),
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            ProviderContract::SUBSCRIPTION_KEY_HEADER => $config->subscriptionKey(),
        ];

        $url = $config->baseUrl() . '/' . $operation->value;
        $maxAttempts = 1 + $config->retryCount();

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            $this->logger->info('Dispatching eBizCharge request', [
                'operation' => $operation->value,
                'environment' => $config->environmentMode(),
                'url' => $url,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
            ]);

            try {
                $response = $this->transport->send($url, $headers, $requestBody, $config->connectionTimeoutSeconds());

                if ($response['statusCode'] >= 500 && $attempt < $maxAttempts) {
                    $this->logger->warning('Retrying eBizCharge request after provider 5xx response.', [
                        'operation' => $operation->value,
                        'statusCode' => $response['statusCode'],
                        'attempt' => $attempt,
                    ]);

                    continue;
                }

                if ($response['statusCode'] >= 400) {
                    throw new ProviderCommunicationException(
                        sprintf('eBizCharge %s request failed: HTTP %d returned by provider.', $operation->value, $response['statusCode']),
                        $response['statusCode']
                    );
                }

                return $response;
            } catch (ProviderCommunicationException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                $this->logger->warning('Retrying eBizCharge request after transport failure.', [
                    'operation' => $operation->value,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        throw ProviderCommunicationException::requestFailed($operation->value, 'Unknown provider transport failure.');
    }
}
