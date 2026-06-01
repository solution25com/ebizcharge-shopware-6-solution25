<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class ProviderOperationResult
{
    public const OUTCOME_APPROVED = 'approved';
    public const OUTCOME_DECLINED = 'declined';
    public const OUTCOME_CANCELLED = 'cancelled';
    public const OUTCOME_PENDING = 'pending';

    public function __construct(
        public readonly string $outcome,
        public readonly string $operationMode,
        public readonly ?string $providerReference,
        public readonly ?string $authorizationCode,
        public readonly string $supportMessage,
        public readonly ?string $providerPaymentMethod = null,
        public readonly ?string $providerPaymentType = null,
        public readonly bool $retriable = false,
        public readonly ?string $failureCategory = null
    ) {
    }

    public static function approved(
        string $operationMode,
        ?string $providerReference,
        ?string $authorizationCode,
        string $supportMessage,
        ?string $providerPaymentMethod = null,
        ?string $providerPaymentType = null
    ): self {
        return new self(
            self::OUTCOME_APPROVED,
            $operationMode,
            $providerReference,
            $authorizationCode,
            $supportMessage,
            $providerPaymentMethod,
            $providerPaymentType,
            false,
            null
        );
    }

    public static function declined(string $operationMode, string $supportMessage, bool $retriable = false, ?string $failureCategory = null): self
    {
        return new self(self::OUTCOME_DECLINED, $operationMode, null, null, $supportMessage, null, null, $retriable, $failureCategory);
    }

    public static function cancelled(string $operationMode, string $supportMessage, bool $retriable = false, ?string $failureCategory = null): self
    {
        return new self(self::OUTCOME_CANCELLED, $operationMode, null, null, $supportMessage, null, null, $retriable, $failureCategory);
    }

    public static function pending(string $operationMode, string $supportMessage, bool $retriable = true, ?string $failureCategory = null): self
    {
        return new self(self::OUTCOME_PENDING, $operationMode, null, null, $supportMessage, null, null, $retriable, $failureCategory);
    }
}
