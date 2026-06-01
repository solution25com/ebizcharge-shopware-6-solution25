<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Client;

use EbizChargeShopware\Exception\ProviderCommunicationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class SymfonyHttpProviderTransport implements ProviderTransportInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function send(string $url, array $headers, array $payload, int $timeoutSeconds): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $timeoutSeconds,
            ]);

            $rawBody = $response->getContent(false);
            $decoded = json_decode($rawBody, true);

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : [],
                'rawBody' => $rawBody,
            ];
        } catch (ExceptionInterface $exception) {
            throw ProviderCommunicationException::requestFailed('transport', $exception->getMessage(), $exception);
        }
    }
}
