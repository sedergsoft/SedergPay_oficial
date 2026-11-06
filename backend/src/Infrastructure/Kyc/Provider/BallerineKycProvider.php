<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

use yii\base\InvalidConfigException;

/**
 * Ballerine implementation of the provider-neutral KYC boundary.
 *
 * It only starts a collection workflow and authenticates observations. It never
 * approves a user or writes the final SEDERG_PAY compliance decision.
 */
final class BallerineKycProvider implements KycProviderInterface
{
    public function __construct(
        private readonly BallerineConfig $config,
        private readonly BallerineTransportInterface $transport,
    ) {
    }

    /**
     * @param array{case_public_id:string,user_public_id:string,country_code:string,locale:string,return_url:string,first_name?:string,last_name?:string} $context
     * @return array{provider_session_id:string,provider_entity_id:?string,collection_url:string,expires_at:string}
     */
    public function createSession(array $context): array
    {
        $this->config->assertSessionConfigured();

        $casePublicId = $this->uuid($context['case_public_id'] ?? '', 'case identifier');
        $userPublicId = $this->uuid($context['user_public_id'] ?? '', 'user identifier');
        $countryCode = strtoupper(trim((string) ($context['country_code'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new \InvalidArgumentException('A valid ISO 3166-1 alpha-2 country code is required');
        }
        $locale = strtolower(trim((string) ($context['locale'] ?? 'es')));
        if (!in_array($locale, ['es', 'ru', 'en'], true)) {
            $locale = 'es';
        }
        $returnUrl = trim((string) ($context['return_url'] ?? ''));
        if ($returnUrl !== '' && !$this->isHttpUrl($returnUrl)) {
            throw new \InvalidArgumentException('The KYC return URL must be an absolute HTTP(S) URL');
        }

        $body = [
            'workflowId' => $this->config->workflowId,
            'context' => [
                'entity' => [
                    'id' => $userPublicId,
                    'type' => 'individual',
                    'data' => [
                        'firstName' => mb_substr(trim((string) ($context['first_name'] ?? '')), 0, 100),
                        'lastName' => mb_substr(trim((string) ($context['last_name'] ?? '')), 0, 100),
                    ],
                ],
                'documents' => [],
                'metadata' => [
                    'sedergCasePublicId' => $casePublicId,
                    'sedergUserPublicId' => $userPublicId,
                    'countryCode' => $countryCode,
                    'locale' => $locale,
                    'returnUrl' => $returnUrl,
                ],
            ],
        ];

        $response = $this->transport->postJson(
            $this->config->runUrl(),
            $body,
            $this->config->authHeaders(),
            $this->config->timeoutSeconds
        );

        $runtimeId = $response['workflowRuntimeId'] ?? null;
        if (!is_string($runtimeId) || !preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $runtimeId)) {
            throw new \RuntimeException('Ballerine did not return a valid workflow runtime identifier');
        }
        $entityId = $response['ballerineEntityId'] ?? null;
        if (!is_string($entityId) || $entityId === '') {
            $entityId = null;
        }

        return [
            'provider_session_id' => $runtimeId,
            'provider_entity_id' => $entityId,
            'collection_url' => $this->config->buildCollectionUrl($runtimeId, $casePublicId, $entityId ?? $userPublicId, $locale),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->sessionTtlSeconds),
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{event_id:string,provider_session_id:string,event_type:string,occurred_at:string,payload:array}
     */
    public function verifyAndNormalizeWebhook(string $rawBody, array $headers): array
    {
        try {
            $this->config->assertWebhookConfigured();
        } catch (InvalidConfigException $exception) {
            throw new \UnexpectedValueException('Ballerine webhook verification is not configured', 0, $exception);
        }

        return (new BallerineWebhookVerifier(
            $this->config->webhookSecret,
            $this->config->webhookToleranceSeconds
        ))->verify($rawBody, $headers);
    }

    private function uuid(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new \InvalidArgumentException("A valid {$label} is required");
        }

        return $value;
    }

    private function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
