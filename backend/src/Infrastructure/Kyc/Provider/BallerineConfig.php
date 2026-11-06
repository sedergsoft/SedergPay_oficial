<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

use yii\base\InvalidConfigException;

/**
 * Immutable Ballerine connection settings resolved from the environment.
 *
 * The base URLs are trusted server-side configuration. They are never taken
 * from request input, which prevents using the KYC adapter as an SSRF proxy.
 */
final class BallerineConfig
{
    public const DEFAULT_COLLECTION_URL_TEMPLATE = '{collectionBaseUrl}?workflowRuntimeId={workflowRuntimeId}';

    public readonly string $baseUrl;
    public readonly string $apiKey;
    public readonly string $workflowId;
    public readonly string $collectionBaseUrl;
    public readonly string $collectionUrlTemplate;
    public readonly string $authHeader;
    public readonly string $authScheme;
    public readonly string $webhookSecret;
    public readonly int $timeoutSeconds;
    public readonly int $webhookToleranceSeconds;
    public readonly int $sessionTtlSeconds;
    public readonly string $appReturnUrl;
    public readonly bool $enabled;

    public function __construct(
        string $baseUrl = '',
        string $apiKey = '',
        string $workflowId = '',
        string $collectionBaseUrl = '',
        string $collectionUrlTemplate = self::DEFAULT_COLLECTION_URL_TEMPLATE,
        string $authHeader = 'Authorization',
        string $authScheme = 'Api-Key',
        string $webhookSecret = '',
        int $timeoutSeconds = 15,
        int $webhookToleranceSeconds = 300,
        int $sessionTtlSeconds = 3600,
        string $appReturnUrl = '',
        bool $enabled = true,
    ) {
        $baseUrl = self::normalizeOptionalUrl($baseUrl, 'base URL');
        $collectionBaseUrl = self::normalizeOptionalUrl($collectionBaseUrl, 'collection base URL');
        $appReturnUrl = self::normalizeOptionalUrl($appReturnUrl, 'application return URL');
        $workflowId = trim($workflowId);
        $authHeader = trim($authHeader);
        $authScheme = trim($authScheme);
        $webhookSecret = trim($webhookSecret);
        $collectionUrlTemplate = trim($collectionUrlTemplate);

        if ($collectionUrlTemplate === '') {
            $collectionUrlTemplate = self::DEFAULT_COLLECTION_URL_TEMPLATE;
        }
        if ($workflowId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $workflowId)) {
            throw new \InvalidArgumentException('Ballerine workflow identifier is invalid');
        }
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $authHeader)) {
            throw new \InvalidArgumentException('Ballerine authentication header name is invalid');
        }
        if ($authScheme !== '' && !preg_match('/^[A-Za-z0-9._~-]{1,40}$/', $authScheme)) {
            throw new \InvalidArgumentException('Ballerine authentication scheme is invalid');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new \InvalidArgumentException('Ballerine timeout must be between 1 and 60 seconds');
        }
        if ($webhookToleranceSeconds < 30 || $webhookToleranceSeconds > 3600) {
            throw new \InvalidArgumentException('Ballerine webhook tolerance must be between 30 and 3600 seconds');
        }
        if ($sessionTtlSeconds < 60 || $sessionTtlSeconds > 86400) {
            throw new \InvalidArgumentException('Ballerine session TTL must be between 60 and 86400 seconds');
        }
        if ($webhookSecret !== '' && strlen($webhookSecret) < 16) {
            throw new \InvalidArgumentException('Ballerine webhook secret must contain at least 16 characters');
        }
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            foreach ([$baseUrl, $collectionBaseUrl, $appReturnUrl] as $url) {
                if ($url !== '' && parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new \InvalidArgumentException('Ballerine and application URLs must use HTTPS outside development mode');
                }
            }
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = trim($apiKey);
        $this->workflowId = $workflowId;
        $this->collectionBaseUrl = rtrim($collectionBaseUrl, '/');
        $this->collectionUrlTemplate = $collectionUrlTemplate;
        $this->authHeader = $authHeader;
        $this->authScheme = $authScheme;
        $this->webhookSecret = $webhookSecret;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->webhookToleranceSeconds = $webhookToleranceSeconds;
        $this->sessionTtlSeconds = $sessionTtlSeconds;
        $this->appReturnUrl = $appReturnUrl;
        $this->enabled = $enabled;
    }

    public static function fromEnvironment(): self
    {
        return new self(
            baseUrl: self::env('SEDERG_BALLERINE_BASE_URL'),
            apiKey: self::env('SEDERG_BALLERINE_API_KEY'),
            workflowId: self::env('SEDERG_BALLERINE_WORKFLOW_ID'),
            collectionBaseUrl: self::env('SEDERG_BALLERINE_COLLECTION_URL'),
            collectionUrlTemplate: self::env('SEDERG_BALLERINE_COLLECTION_URL_TEMPLATE') ?: self::DEFAULT_COLLECTION_URL_TEMPLATE,
            authHeader: self::env('SEDERG_BALLERINE_AUTH_HEADER') ?: 'Authorization',
            authScheme: self::env('SEDERG_BALLERINE_AUTH_SCHEME') ?: 'Api-Key',
            webhookSecret: self::env('SEDERG_BALLERINE_WEBHOOK_SECRET'),
            timeoutSeconds: self::envInt('SEDERG_BALLERINE_TIMEOUT_SECONDS', 15),
            webhookToleranceSeconds: self::envInt('SEDERG_BALLERINE_WEBHOOK_TOLERANCE_SECONDS', 300),
            sessionTtlSeconds: self::envInt('SEDERG_BALLERINE_SESSION_TTL_SECONDS', 3600),
            appReturnUrl: self::env('SEDERG_BALLERINE_RETURN_URL') ?: self::env('SEDERG_APP_URL'),
            enabled: self::envBool('SEDERG_BALLERINE_ENABLED', false),
        );
    }

    public function isSessionConfigured(): bool
    {
        return $this->enabled
            && $this->baseUrl !== ''
            && $this->apiKey !== ''
            && $this->workflowId !== ''
            && $this->collectionBaseUrl !== '';
    }

    public function isWebhookConfigured(): bool
    {
        return $this->enabled && $this->webhookSecret !== '';
    }

    /**
     * @throws InvalidConfigException
     */
    public function assertSessionConfigured(): void
    {
        if (!$this->isSessionConfigured()) {
            throw new InvalidConfigException(
                'Ballerine KYC is not configured. Set SEDERG_BALLERINE_ENABLED=true, SEDERG_BALLERINE_BASE_URL, '
                . 'SEDERG_BALLERINE_API_KEY, SEDERG_BALLERINE_WORKFLOW_ID and SEDERG_BALLERINE_COLLECTION_URL.'
            );
        }
        if (!str_contains($this->collectionUrlTemplate, '{workflowRuntimeId}')) {
            throw new InvalidConfigException('The Ballerine collection URL template must contain {workflowRuntimeId}');
        }
    }

    /**
     * @throws InvalidConfigException
     */
    public function assertWebhookConfigured(): void
    {
        if (!$this->isWebhookConfigured()) {
            throw new InvalidConfigException(
                'Ballerine webhook processing is disabled or SEDERG_BALLERINE_WEBHOOK_SECRET is not configured'
            );
        }
    }

    public function runUrl(): string
    {
        return $this->baseUrl . '/api/v1/external/workflows/run';
    }

    /**
     * @return array<string,string>
     */
    public function authHeaders(): array
    {
        return [$this->authHeader => trim($this->authScheme . ' ' . $this->apiKey)];
    }

    public function buildCollectionUrl(string $workflowRuntimeId, string $casePublicId, string $entityId, string $locale): string
    {
        $url = strtr($this->collectionUrlTemplate, [
            '{collectionBaseUrl}' => $this->collectionBaseUrl,
            '{workflowRuntimeId}' => rawurlencode($workflowRuntimeId),
            '{casePublicId}' => rawurlencode($casePublicId),
            '{entityId}' => rawurlencode($entityId),
            '{returnUrl}' => rawurlencode($this->appReturnUrl),
            '{locale}' => rawurlencode($locale),
        ]);

        if (self::normalizeOptionalUrl($url, 'collection URL') === '') {
            throw new \InvalidArgumentException('Ballerine collection URL is invalid');
        }
        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('Ballerine collection URL is too long');
        }

        return $url;
    }

    private static function normalizeOptionalUrl(string $url, string $label): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($url, "\r")
            || str_contains($url, "\n")) {
            throw new \InvalidArgumentException("Ballerine {$label} must be an absolute HTTP(S) URL without credentials");
        }

        return $url;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : trim((string) $value);
    }

    private static function envInt(string $name, int $default): int
    {
        $value = self::env($name);
        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return $default;
        }

        return (int) $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = strtolower(self::env($name));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
