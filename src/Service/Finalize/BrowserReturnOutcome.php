<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Finalize;

enum BrowserReturnOutcome: string
{
    case APPROVED = 'approved';
    case DECLINED = 'declined';
    case CANCELLED = 'cancelled';
    case ERROR = 'error';
    case UNKNOWN = 'unknown';
}
