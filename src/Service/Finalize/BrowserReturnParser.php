<?php

declare(strict_types=1);

namespace EbizChargeShopware\Service\Finalize;

use EbizChargeShopware\Provider\ProviderContract;
use Symfony\Component\HttpFoundation\Request;

final class BrowserReturnParser
{
    public function parse(Request $request): BrowserReturnOutcome
    {
        $raw = strtolower(trim((string) $this->firstQueryValue($request, [ProviderContract::BROWSER_RESULT_QUERY_PARAM, 'result', 'status', 'TranResult',])));

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
        return $this->firstQueryValue($request, ['refNum', 'refnum', 'RefNum', 'transactionRefNum', 'TranRefNum']);
    }

    /**
     * @param list<string> $keys
     */
    private function firstQueryValue(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) $request->query->get($key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
