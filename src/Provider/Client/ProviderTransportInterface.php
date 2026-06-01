<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Client;

interface ProviderTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     *
     * @return array{statusCode:int, body:array<string, mixed>, rawBody:string}
     */
    public function send(string $url, array $headers, array $payload, int $timeoutSeconds): array;
}
