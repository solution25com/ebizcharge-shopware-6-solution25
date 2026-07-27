<?php

declare(strict_types=1);

namespace EbizChargeShopware\Checkout\Payment\Handler;

use EbizChargeShopware\Provider\ProviderContract;

final class AchPaymentHandler extends CreditCardPaymentHandler
{
    protected function payByType(): string
    {
        return ProviderContract::PAY_BY_TYPE_ACH;
    }
}
