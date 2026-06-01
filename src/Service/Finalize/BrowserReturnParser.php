<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Finalize;

use EbizChargeShopware\Provider\ProviderContract;
use Symfony\Component\HttpFoundation\Request;

final class BrowserReturnParser
{
    public function parse(Request $request): BrowserReturnOutcome
    {
        $raw = strtolower(trim((string) ($request->query->get(ProviderContract::BROWSER_RESULT_QUERY_PARAM) ?? $request->query->get('result') ?? $request->query->get('status') ?? '')));

        return match ($raw) {
            'approved', 'success', 'paid' => BrowserReturnOutcome::APPROVED,
            'declined', 'failed' => BrowserReturnOutcome::DECLINED,
            'cancelled', 'canceled', 'cancel' => BrowserReturnOutcome::CANCELLED,
            'error' => BrowserReturnOutcome::ERROR,
            default => BrowserReturnOutcome::UNKNOWN,
        };
    }

    public function referenceNumber(Request $request): ?string
    {
        foreach (['refNum', 'refnum', 'RefNum', 'transactionRefNum'] as $key) {
            $value = trim((string) $request->query->get($key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
