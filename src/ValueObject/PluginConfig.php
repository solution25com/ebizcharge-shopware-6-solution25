<?php

declare(strict_types=1);

namespace EbizChargeShopware\ValueObject;

use EbizChargeShopware\Exception\ConfigurationException;

final class PluginConfig
{
    public function __construct(
        private readonly string $environmentMode,
        private readonly string $baseUrl,
        private readonly string $securityId,
        private readonly string $userId,
        private readonly string $password,
        private readonly string $subscriptionKey,
        private readonly string $shipFromZip,
        private readonly string $processingCommand,
        private readonly int $verificationLookbackDays,
        private readonly int $connectionTimeoutSeconds,
        private readonly int $retryCount,
        private readonly string $descriptionTemplate,
        private readonly bool $enforceAvsCheck = false,
        private readonly string $webhookBasicUsername = '',
        private readonly string $webhookBasicPassword = '',
        private readonly string $webhookSignatureKey = ''
    ) {
    }

    public function hasCompleteCredentials(): bool
    {
        try {
            $this->assertComplete();

            return true;
        } catch (ConfigurationException) {
            return false;
        }
    }

    public function assertComplete(): void
    {
        $required = [
            'baseUrl' => $this->baseUrl,
            'securityId' => $this->securityId,
            'userId' => $this->userId,
            'password' => $this->password,
            'subscriptionKey' => $this->subscriptionKey,
            'shipFromZip' => $this->shipFromZip,
            'processingCommand' => $this->processingCommand,
        ];

        foreach ($required as $field => $value) {
            if (trim($value) === '') {
                throw ConfigurationException::missing($field);
            }
        }
    }

    public function environmentMode(): string
    {
        return $this->environmentMode;
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function securityId(): string
    {
        return $this->securityId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function subscriptionKey(): string
    {
        return $this->subscriptionKey;
    }

    public function shipFromZip(): string
    {
        return $this->shipFromZip;
    }

    public function processingCommand(): string
    {
        return $this->processingCommand;
    }

    public function verificationLookbackDays(): int
    {
        return max(1, $this->verificationLookbackDays);
    }

    public function connectionTimeoutSeconds(): int
    {
        return max(5, $this->connectionTimeoutSeconds);
    }

    public function retryCount(): int
    {
        return max(0, min(3, $this->retryCount));
    }

    public function descriptionTemplate(): string
    {
        return $this->descriptionTemplate;
    }

    public function descriptionForOrder(string $orderNumber): string
    {
        return str_replace('{{ orderNumber }}', $orderNumber, $this->descriptionTemplate);
    }

    public function enforceAvsCheck(): bool
    {
        return $this->enforceAvsCheck;
    }

    public function credentialFingerprint(): string
    {
        return strtoupper(substr(hash('sha256', implode('|', [
            $this->environmentMode,
            $this->baseUrl(),
            $this->securityId,
            $this->userId,
            $this->password,
            $this->subscriptionKey,
        ])), 0, 12));
    }

    public function webhookSignatureKey(): string
    {
        return $this->webhookSignatureKey;
    }

    public function webhookBasicUsername(): string
    {
        return $this->webhookBasicUsername;
    }

    public function webhookBasicPassword(): string
    {
        return $this->webhookBasicPassword;
    }
}
