<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Client;

use EbizChargeShopware\Provider\ProviderOperation;
use EbizChargeShopware\ValueObject\PluginConfig;

interface ProviderClientInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{statusCode:int, body:array<string, mixed>, rawBody:string}
     */
    public function send(ProviderOperation $operation, array $payload, PluginConfig $config): array;
}
