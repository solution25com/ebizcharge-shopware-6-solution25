<?php

declare(strict_types=1);

namespace EbizChargeShopware\Exception;

final class ProviderCommunicationException extends \RuntimeException
{
    public static function requestFailed(string $operation, string $message, ?\Throwable $previous = null): self
    {
        return new self(sprintf('EBizCharge %s request failed: %s', $operation, $message), 0, $previous);
    }
}
