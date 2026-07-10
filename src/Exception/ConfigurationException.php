<?php

declare(strict_types=1);

namespace EbizChargeShopware\Exception;

final class ConfigurationException extends \RuntimeException
{
    public static function missing(string $field): self
    {
        return new self(sprintf('The plugin configuration field "%s" is required.', $field));
    }

    public static function connectionTestRequired(): self
    {
        return new self('Run a successful EBizCharge connection test with the current credentials before using the payment method.');
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
