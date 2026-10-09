<?php

declare(strict_types=1);

namespace Kolai\Payment;

/**
 * Each module reads these from its own settings store and hands them over, so
 * the client never touches configuration.
 */
final class IyzicoCredentials
{
    public const ENV_SANDBOX    = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    public const BASE_URL_SANDBOX    = 'https://sandbox-api.iyzipay.com';
    public const BASE_URL_PRODUCTION = 'https://api.iyzipay.com';

    private string $apiKey;

    private string $secretKey;

    private string $environment;

    public function __construct(string $apiKey, string $secretKey, string $environment = self::ENV_SANDBOX)
    {
        $this->apiKey    = trim($apiKey);
        $this->secretKey = trim($secretKey);
        // Anything that is not explicitly production is sandbox. A typo in the
        // setting must never silently point live money at the real API.
        $this->environment = $environment === self::ENV_PRODUCTION ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function baseUrl(): string
    {
        return $this->environment === self::ENV_PRODUCTION
            ? self::BASE_URL_PRODUCTION
            : self::BASE_URL_SANDBOX;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }
}
