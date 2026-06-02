<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider\Response;

use EbizChargeShopware\Exception\VerificationException;
use EbizChargeShopware\ValueObject\CheckoutOrderData;
use EbizChargeShopware\ValueObject\ProviderOperationResult;

final class ResponseNormalizer
{
    /**
     * @param array<string, mixed> $body
     */
    public function extractHostedRedirectUrl(array $body): string
    {
        $url = $this->findFirstUrl($body);

        if ($url === null) {
            throw new VerificationException('The eBizCharge hosted form URL could not be found in the provider response.');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function normalizeVerifiedPayment(
        array $body,
        string $fallbackOperationMode,
        ?CheckoutOrderData $orderData = null,
        ?string $expectedReference = null,
        bool $enforceAvsCheck = false
    ): ProviderOperationResult {
        $paymentNode = $this->findFirstPaymentNode($body);

        if ($paymentNode === null) {
            throw new VerificationException('The provider verification response did not contain a payment record.');
        }

        $paymentType = (string) ($paymentNode['PaymentType'] ?? $paymentNode['paymentType'] ?? $fallbackOperationMode);
        $reference = $this->stringOrNull($paymentNode['RefNum'] ?? $paymentNode['refNum'] ?? $paymentNode['transactionRefNum'] ?? null);
        $authCode = $this->stringOrNull($paymentNode['AuthCode'] ?? $paymentNode['authCode'] ?? null);
        $paymentMethod = $this->stringOrNull($paymentNode['PaymentMethod'] ?? $paymentNode['paymentMethod'] ?? null);
        $status = $this->resolveProviderOutcome($paymentNode);

        if ($reference === null) {
            throw new VerificationException('The provider verification response did not contain a reference number.');
        }

        if ($expectedReference !== null && !hash_equals($expectedReference, $reference)) {
            throw new VerificationException('The provider verification response reference did not match the browser return reference.');
        }

        if ($orderData !== null) {
            $this->assertCorrelatesToOrder($paymentNode, $orderData);
        }

        $operationMode = $paymentType !== '' ? $paymentType : $fallbackOperationMode;

        if ($status === ProviderOperationResult::OUTCOME_APPROVED && $enforceAvsCheck && !$this->hasFullAvsMatch($paymentNode)) {
            $avsCode = $this->avsResultCode($paymentNode) ?? 'missing';

            return new ProviderOperationResult(
                ProviderOperationResult::OUTCOME_DECLINED,
                $operationMode,
                $reference,
                $authCode,
                sprintf('Provider verification returned AVS code "%s", which is not a full address and ZIP match.', $avsCode),
                $paymentMethod,
                $paymentType,
                false,
                'avs_mismatch'
            );
        }

        return match ($status) {
            ProviderOperationResult::OUTCOME_APPROVED => ProviderOperationResult::approved(
                $operationMode,
                $reference,
                $authCode,
                'Provider verification succeeded.',
                $paymentMethod,
                $paymentType
            ),
            ProviderOperationResult::OUTCOME_DECLINED => ProviderOperationResult::declined(
                $operationMode,
                'Provider verification returned a decline.',
                false,
                'provider_business_decline'
            ),
            ProviderOperationResult::OUTCOME_CANCELLED => ProviderOperationResult::cancelled(
                $operationMode,
                'Provider verification returned a cancellation.',
                false,
                'shopper_cancelled'
            ),
            default => ProviderOperationResult::pending(
                $operationMode,
                'Provider verification is pending or inconclusive.',
                true,
                'verification_pending'
            ),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    public function findCustomerInternalId(array $body): ?string
    {
        $customerData = $this->findArrayWithKey($body, 'CustomerInternalId');

        return $customerData !== null
            ? $this->stringOrNull($customerData['CustomerInternalId'] ?? $customerData['customerInternalId'] ?? null)
            : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    public function extractSearchCustomers(array $body): array
    {
        $customers = $body['searchCustomersResponse']['SearchCustomersResult']['Customer']
            ?? $body['searchCustomersResponse']['SearchCustomersResult']
            ?? $body['searchCustomersResponse']['searchCustomersResult']['customer']
            ?? $body['searchCustomersResponse']['searchCustomersResult']
            ?? $body['SearchCustomersResult']['Customer']
            ?? $body['SearchCustomersResult']
            ?? null;

        if (!is_array($customers)) {
            return [];
        }

        if (isset($customers['CustomerId']) || isset($customers['customerId'])) {
            return [$customers];
        }

        $result = [];
        foreach ($customers as $customer) {
            if (is_array($customer)) {
                $result[] = $customer;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function extractGetCustomerToken(array $body): ?string
    {
        $value = $body['getCustomerTokenResponse']['GetCustomerTokenResult']
            ?? $body['getCustomerTokenResponse']['getCustomerTokenResult']
            ?? $body['GetCustomerTokenResult']
            ?? null;

        return $this->stringOrNull(is_scalar($value) ? $value : null);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    public function collectPaymentMethodProfiles(array $body): array
    {
        $keys = [
            'MethodID',
            'MethodId',
            'PaymentMethodID',
            'PaymentMethodId',
            'paymentMethodId',
            'methodID',
            'methodId',
        ];
        $queue = [$body];
        $profiles = [];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            $matched = false;
            foreach ($keys as $key) {
                if (array_key_exists($key, $current)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $id = $current['MethodID']
                    ?? $current['MethodId']
                    ?? $current['PaymentMethodID']
                    ?? $current['PaymentMethodId']
                    ?? $current['paymentMethodId']
                    ?? $current['methodID']
                    ?? $current['methodId']
                    ?? null;
                $signature = is_scalar($id) && trim((string) $id) !== ''
                    ? 'id:' . trim((string) $id)
                    : 'hash:' . md5(json_encode($current, JSON_THROW_ON_ERROR));

                if (!isset($seen[$signature])) {
                    $seen[$signature] = true;
                    $profiles[] = $current;
                }
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function assertCorrelatesToOrder(array $node, CheckoutOrderData $orderData): void
    {
        $lookupKey = $this->stringOrNull($node['TransactionLookupKey'] ?? $node['transactionLookupKey'] ?? $node['lookupKey'] ?? null);
        $orderId = $this->stringOrNull($node['OrderId'] ?? $node['orderId'] ?? $node['orderNumber'] ?? null);
        $invoiceNumber = $this->stringOrNull($node['invoiceNumber'] ?? $node['details']['invoice'] ?? null);
        $amount = $this->floatOrNull($node['paidAmount'] ?? $node['amount'] ?? $node['authAmount'] ?? null);
        $currency = $this->normalizedCurrency(
            $this->stringOrNull($node['currency'] ?? null)
        );

        if ($lookupKey !== null && !hash_equals($orderData->orderTransactionId, $lookupKey)) {
            throw new VerificationException('The provider verification response did not match the Shopware order transaction.');
        }

        if ($lookupKey === null) {
            if ($orderId === null && $invoiceNumber === null) {
                throw new VerificationException('The provider verification response did not contain a Shopware order correlation key.');
            }

            if ($orderId !== null && !$this->matchesOrderIdentity($orderData, $orderId)) {
                throw new VerificationException('The provider verification orderId did not match the Shopware order.');
            }

            if ($invoiceNumber !== null && !$this->matchesOrderIdentity($orderData, $invoiceNumber)) {
                throw new VerificationException('The provider verification invoiceNumber did not match the Shopware order.');
            }
        }

        if ($amount === null) {
            throw new VerificationException('The provider verification response did not contain a comparable amount.');
        }

        if (abs($amount - $orderData->amountDue) > 0.01) {
            throw new VerificationException('The provider verification amount did not match the Shopware order transaction amount.');
        }

        if ($currency !== null && !hash_equals($orderData->currencyIso, $currency)) {
            throw new VerificationException('The provider verification currency did not match the Shopware order transaction currency.');
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveProviderOutcome(array $node): string
    {
        $status = strtolower(trim((string) (
            $node['Result']
            ?? $node['result']
            ?? $node['Status']
            ?? $node['status']
            ?? $node['TransactionStatus']
            ?? $node['transactionStatus']
            ?? $node['StatusCode']
            ?? ''
        )));
        $collapsedStatus = str_replace([' ', '-', '_'], '', $status);

        if ($status !== '') {
            if (str_starts_with($collapsedStatus, 'unauth')) {
                return ProviderOperationResult::OUTCOME_DECLINED;
            }

            if (str_contains($status, 'declin') || str_contains($status, 'fail') || str_contains($status, 'error')) {
                return ProviderOperationResult::OUTCOME_DECLINED;
            }

            if (str_contains($status, 'cancel')) {
                return ProviderOperationResult::OUTCOME_CANCELLED;
            }

            if (
                str_contains($status, 'approv')
                || str_contains($status, 'success')
                || str_contains($status, 'paid')
                || str_contains($status, 'settl')
                || str_contains($status, 'captur')
                || \in_array($collapsedStatus, ['authorized', 'authonly'], true)
                || str_contains($status, 'complete')
            ) {
                return ProviderOperationResult::OUTCOME_APPROVED;
            }

            if (str_contains($status, 'pending') || str_contains($status, 'process') || str_contains($status, 'open')) {
                return ProviderOperationResult::OUTCOME_PENDING;
            }
        }

        if (
            $this->stringOrNull($node['DatePaid'] ?? $node['datePaid'] ?? null) !== null
            || $this->stringOrNull($node['AuthCode'] ?? $node['authCode'] ?? null) !== null
        ) {
            return ProviderOperationResult::OUTCOME_APPROVED;
        }

        return ProviderOperationResult::OUTCOME_PENDING;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasFullAvsMatch(array $node): bool
    {
        $code = $this->avsResultCode($node);

        if ($code === null) {
            return false;
        }

        return in_array($code, ['YYY', 'YYA', 'YYD', 'YYX', 'GGG', 'Y', 'X', 'D'], true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function avsResultCode(array $node): ?string
    {
        $value = $this->stringOrNull($node['avsResultCode'] ?? null);

        return $value !== null ? strtoupper($value) : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function connectionTestSucceeded(array $body): bool
    {
        $result = $body['getMerchantTransactionDataResponse']['getMerchantTransactionDataResult']
            ?? $body['getMerchantTransactionDataResult']
            ?? null;

        if ($result === null || $result === '' || $result === []) {
            return false;
        }

        $flat = strtolower(json_encode($result, JSON_THROW_ON_ERROR));

        return !str_contains($flat, '"error"')
            && !str_contains($flat, '"errors"')
            && !str_contains($flat, '"errormessage"');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function findFirstUrl(array $body): ?string
    {
        $queue = [$body];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($current as $key => $value) {
                if (is_string($value)) {
                    $normalizedKey = strtolower((string) $key);
                    if (
                        str_contains($normalizedKey, 'url')
                        && str_starts_with($value, 'http')
                    ) {
                        return $value;
                    }

                    if (str_contains($value, 'webforms.ebizcharge.net')) {
                        return $value;
                    }
                }

                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null
     */
    private function findFirstPaymentNode(array $body): ?array
    {
        $queue = [$body];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($this->looksLikePaymentNode($current)) {
                return $current;
            }

            $response = $current['response'] ?? $current['Response'] ?? null;
            if (is_array($response) && $this->looksLikePaymentNode($response)) {
                return array_merge($current, $response);
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function looksLikePaymentNode(array $node): bool
    {
        $keys = array_map(static fn ($key): string => strtolower((string) $key), array_keys($node));

        return (
            in_array('refnum', $keys, true)
            || in_array('paymentinternalid', $keys, true)
            || in_array('datepaid', $keys, true)
            || in_array('transactionlookupkey', $keys, true)
        ) && (
            in_array('paymenttype', $keys, true)
            || in_array('authcode', $keys, true)
            || in_array('paymentmethod', $keys, true)
            || in_array('amount', $keys, true)
            || in_array('authamount', $keys, true)
            || in_array('status', $keys, true)
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        return null;
    }

    private function normalizedCurrency(?string $currency): ?string
    {
        if ($currency === null) {
            return null;
        }

        $normalized = strtoupper(trim($currency));

        return match ($normalized) {
            '840' => 'USD',
            '978' => 'EUR',
            '826' => 'GBP',
            default => $normalized !== '' ? $normalized : null,
        };
    }

    private function matchesOrderIdentity(CheckoutOrderData $orderData, string $value): bool
    {
        return hash_equals($orderData->orderNumber, $value) || hash_equals($orderData->orderId, $value);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null
     */
    private function findArrayWithKey(array $body, string $key): ?array
    {
        $queue = [$body];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (array_key_exists($key, $current) || array_key_exists(lcfirst($key), $current)) {
                return $current;
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }

        return null;
    }
}
