<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

use UnexpectedValueException;

/**
 * Authenticates Ballerine webhooks before any payload field is trusted.
 *
 * Upstream sends the shared WEBHOOK_SECRET in X-Authorization. An optional
 * HMAC-SHA256 signature header is also accepted and, when present, must be
 * valid. Replay protection is completed by the unique event ID persisted by
 * KycWebhookService; this verifier additionally rejects stale timestamps.
 */
final class BallerineWebhookVerifier
{
    private const MAX_BODY_BYTES = 1048576;
    private readonly \Closure $clock;

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
        ?callable $clock = null,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('A Ballerine webhook secret is required');
        }
        if ($this->toleranceSeconds < 30 || $this->toleranceSeconds > 3600) {
            throw new \InvalidArgumentException('Ballerine webhook tolerance is invalid');
        }
        $this->clock = $clock === null ? static fn (): int => time() : \Closure::fromCallable($clock);
    }

    /**
     * @param array<string,string> $headers
     * @return array{event_id:string,provider_session_id:string,event_type:string,occurred_at:string,payload:array}
     */
    public function verify(string $rawBody, array $headers): array
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new UnexpectedValueException('Ballerine webhook body is empty or too large');
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $authorization = trim((string) ($headers['x-authorization'] ?? ''));
        if ($authorization === '') {
            // Some Ballerine versions emit the shared secret in Authorization.
            $authorization = trim((string) ($headers['authorization'] ?? ''));
        }
        $signature = trim((string) ($headers['x-ballerine-signature'] ?? ''));
        $authorized = false;

        if ($authorization !== '' && hash_equals($this->secret, $authorization)) {
            $authorized = true;
        }
        if ($signature !== '') {
            $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
            if (!preg_match('/^[0-9a-f]{64}$/i', $provided)) {
                throw new UnexpectedValueException('Ballerine webhook signature format is invalid');
            }
            $expected = hash_hmac('sha256', $rawBody, $this->secret);
            if (!hash_equals($expected, strtolower($provided))) {
                throw new UnexpectedValueException('Ballerine webhook signature is invalid');
            }
            $authorized = true;
        }
        if (!$authorized) {
            throw new UnexpectedValueException('Ballerine webhook authentication failed');
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new UnexpectedValueException('Ballerine webhook body is not valid JSON', 0, $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new UnexpectedValueException('Ballerine webhook body must be a JSON object');
        }

        $eventId = $payload['id'] ?? null;
        $eventType = $payload['eventName'] ?? null;
        $runtimeId = $payload['workflowRuntimeId'] ?? null;
        $timestamp = $payload['timestamp'] ?? null;
        foreach (['event id' => $eventId, 'event name' => $eventType, 'workflow runtime id' => $runtimeId] as $label => $value) {
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $value)) {
                throw new UnexpectedValueException("Ballerine webhook {$label} is invalid");
            }
        }
        if (!is_string($timestamp) || trim($timestamp) === '') {
            throw new UnexpectedValueException('Ballerine webhook timestamp is required');
        }

        try {
            $occurredAt = new \DateTimeImmutable($timestamp);
        } catch (\Exception $exception) {
            throw new UnexpectedValueException('Ballerine webhook timestamp is invalid', 0, $exception);
        }
        $now = ($this->clock)();
        if (abs($now - $occurredAt->getTimestamp()) > $this->toleranceSeconds) {
            throw new UnexpectedValueException('Ballerine webhook timestamp is outside the accepted window');
        }

        return [
            'event_id' => $eventId,
            'provider_session_id' => $runtimeId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'payload' => $payload,
        ];
    }
}
