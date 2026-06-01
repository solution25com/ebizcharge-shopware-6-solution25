<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

final class ConnectionTestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $environment,
        public readonly string $endpoint,
        public readonly string $credentialFingerprint,
        public readonly string $testedAt,
        public readonly ?string $failureCategory = null,
        public readonly ?int $statusCode = null,
        public readonly ?float $durationMs = null
    ) {
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'environment' => $this->environment,
            'endpoint' => $this->endpoint,
            'credentialFingerprint' => $this->credentialFingerprint,
            'testedAt' => $this->testedAt,
            'failureCategory' => $this->failureCategory,
            'statusCode' => $this->statusCode,
            'durationMs' => $this->durationMs,
        ];
    }
}
