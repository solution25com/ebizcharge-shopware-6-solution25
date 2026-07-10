<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider;

final class ProviderContract
{
    public const SUBSCRIPTION_KEY_HEADER = 'EBizSubscription-Key';
    public const WEBFORM_TYPE = 'Webform';
    public const WEBFORM_PM_REQUEST_FORM = 'PmRequestForm';
    public const EMAIL_FORM_TYPE = 'EmailForm';
    public const PAY_BY_TYPE_CREDIT_CARD = 'CC';
    public const PAY_BY_TYPE_ACH = 'ACH';
    public const PAY_BY_TYPE_CREDIT_CARD_AND_ACH = 'CC,ACH';
    public const BROWSER_RESULT_QUERY_PARAM = 'ebizchargeResult';

    private function __construct()
    {
    }
}
