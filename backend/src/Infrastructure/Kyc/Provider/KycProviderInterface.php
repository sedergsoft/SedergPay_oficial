<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

/**
 * Provider-neutral boundary for external KYC collection/orchestration.
 *
 * Implementations must never make the SEDERG_PAY compliance decision. Yii2
 * remains responsible for user binding, persistence, audit and final approval.
 */
interface KycProviderInterface
{
    /**
     * Create a short-lived collection session for an already authenticated user.
     *
     * @param array{case_public_id:string,user_public_id:string,country_code:string,locale:string,return_url:string} $context
     * @return array{provider_session_id:string,provider_entity_id?:?string,collection_url:string,expires_at:string}
     */
    public function createSession(array $context): array;

    /**
     * Verify and normalize a provider webhook. Invalid signatures/replays must
     * throw; returned events are untrusted observations, never approval grants.
     *
     * @param array<string,string> $headers
     * @return array{event_id:string,provider_session_id:string,event_type:string,occurred_at:string,payload:array}
     */
    public function verifyAndNormalizeWebhook(string $rawBody, array $headers): array;
}
