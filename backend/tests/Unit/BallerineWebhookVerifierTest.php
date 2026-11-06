<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Kyc\Provider\BallerineWebhookVerifier;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class BallerineWebhookVerifierTest extends TestCase
{
    private const SECRET = 'test-webhook-secret-0123456789';
    private const NOW = 1793880000; // 2026-11-05T12:00:00Z

    public function testAcceptsSharedSecretAndNormalizesEvent(): void
    {
        $rawBody = $this->payloadJson();
        $verifier = new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW);

        $event = $verifier->verify($rawBody, ['x-authorization' => self::SECRET]);

        self::assertSame('event-1', $event['event_id']);
        self::assertSame('runtime-1', $event['provider_session_id']);
        self::assertSame('workflow.state.changed', $event['event_type']);
        self::assertSame('2026-11-05 12:00:00', $event['occurred_at']);
        self::assertSame('approved', $event['payload']['state']);
    }

    public function testAcceptsValidHmacSignatureWithoutSharedSecretHeader(): void
    {
        $rawBody = $this->payloadJson();
        $signature = hash_hmac('sha256', $rawBody, self::SECRET);
        $verifier = new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW);

        $event = $verifier->verify($rawBody, ['x-ballerine-signature' => 'sha256=' . $signature]);

        self::assertSame('event-1', $event['event_id']);
    }

    public function testAcceptsAuthorizationHeaderAlias(): void
    {
        $verifier = new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW);

        $event = $verifier->verify($this->payloadJson(), ['authorization' => self::SECRET]);

        self::assertSame('event-1', $event['event_id']);
    }

    public function testRejectsInvalidSecret(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW))
            ->verify($this->payloadJson(), ['x-authorization' => 'wrong-secret']);
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW))
            ->verify($this->payloadJson(), ['x-ballerine-signature' => str_repeat('0', 64)]);
    }

    public function testRejectsStaleTimestamp(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW + 301))
            ->verify($this->payloadJson(), ['x-authorization' => self::SECRET]);
    }

    public function testRejectsMissingRuntimeId(): void
    {
        $rawBody = json_encode([
            'id' => 'event-1',
            'eventName' => 'workflow.completed',
            'timestamp' => '2026-11-05T12:00:00Z',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(UnexpectedValueException::class);
        (new BallerineWebhookVerifier(self::SECRET, 300, fn (): int => self::NOW))
            ->verify($rawBody, ['x-authorization' => self::SECRET]);
    }

    private function payloadJson(): string
    {
        return json_encode([
            'id' => 'event-1',
            'eventName' => 'workflow.state.changed',
            'state' => 'approved',
            'timestamp' => '2026-11-05T12:00:00Z',
            'workflowDefinitionId' => 'workflow-definition-1',
            'workflowRuntimeId' => 'runtime-1',
            'ballerineEntityId' => 'entity-1',
            'correlationId' => 'correlation-1',
            'environment' => 'development',
            'data' => ['documents' => []],
        ], JSON_THROW_ON_ERROR);
    }
}
