<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Request;

use EbizChargeShopware\ValueObject\PluginConfig;

final class SecurityTokenPayloadFactory
{
    /**
     * @return array{securityId:string,userId:string,password:string}
     */
    public function create(PluginConfig $config): array
    {
        return [
            'securityId' => $config->securityId(),
            'userId' => $config->userId(),
            'password' => $config->password(),
        ];
    }
}
