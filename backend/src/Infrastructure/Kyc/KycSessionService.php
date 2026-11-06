<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc;

use app\Infrastructure\Kyc\Provider\KycProviderInterface;
use yii\db\Connection;
use yii\db\JsonExpression;
use yii\web\ForbiddenHttpException;
use yii\web\UnprocessableEntityHttpException;

/**
 * Creates and binds a short-lived provider collection session to the
 * authenticated user's internal KYC case.
 */
final class KycSessionService
{
    private const PROVIDER = 'ballerine';

    public function __construct(
        private readonly Connection $db,
        private readonly KycProviderInterface $provider,
        private readonly string $providerName = self::PROVIDER,
    ) {
    }

    /**
     * @return array{case_public_id:string,status:string,provider_session_id:string,collection_url:string,expires_at:string}
     */
    public function createForUser(
        int $userId,
        string $locale = 'es',
        string $returnUrl = '',
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $profile = $this->db->createCommand(
            'SELECT u.public_id, u.email, p.first_name, p.last_name,
                    c.iso2 AS country_code
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             LEFT JOIN {{%countries}} c
                    ON c.id = COALESCE(p.residence_country_id, p.nationality_country_id, u.country_id)
             WHERE u.id = :id AND u.deleted_at IS NULL
             LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($profile === false) {
            throw new ForbiddenHttpException('Authenticated user was not found');
        }

        $case = $this->loadOrCreateCase($userId);
        if (in_array($case['status'], ['approved', 'rejected'], true)) {
            throw new UnprocessableEntityHttpException('A new KYC session cannot be created for a finalized case');
        }

        if ($this->canReuseSession($case)) {
            return [
                'case_public_id' => (string) $case['public_id'],
                'status' => (string) $case['status'],
                'provider_session_id' => (string) $case['provider_session_id'],
                'collection_url' => (string) $case['provider_collection_url'],
                'expires_at' => (string) $case['provider_session_expires_at'],
            ];
        }

        $locale = in_array(strtolower($locale), ['es', 'ru', 'en'], true) ? strtolower($locale) : 'es';
        $countryCode = strtoupper(trim((string) ($profile['country_code'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new UnprocessableEntityHttpException(
                'Complete your country of residence or nationality in your profile before starting KYC'
            );
        }

        try {
            $session = $this->provider->createSession([
                'case_public_id' => (string) $case['public_id'],
                'user_public_id' => (string) $profile['public_id'],
                'country_code' => $countryCode,
                'locale' => $locale,
                'return_url' => $returnUrl,
                'first_name' => (string) ($profile['first_name'] ?? ''),
                'last_name' => (string) ($profile['last_name'] ?? ''),
            ]);
        } catch (\Throwable $exception) {
            $this->audit(
                $userId,
                'kyc.provider.session_failed',
                (string) $case['public_id'],
                ['provider' => $this->providerName, 'reason' => 'provider_call_failed'],
                $ipAddress,
                $userAgent
            );
            throw $exception;
        }

        $status = in_array($case['status'], ['not_started', 'draft', 'error', 'expired', 'requires_action', 'resubmission_required'], true)
            ? 'in_progress'
            : (string) $case['status'];

        $updated = $this->db->createCommand()->update('{{%kyc_cases}}', [
            'provider_name' => $this->providerName,
            'provider_session_id' => $session['provider_session_id'],
            'provider_entity_id' => $session['provider_entity_id'] ?? null,
            'provider_status' => 'created',
            'provider_collection_url' => $session['collection_url'],
            'provider_session_expires_at' => $session['expires_at'],
            'status' => $status,
        ], [
            'and',
            ['id' => $case['id']],
            ['not in', 'status', ['approved', 'rejected']],
        ])->execute();
        if ($updated === 0) {
            throw new UnprocessableEntityHttpException(
                'A new KYC session cannot be created for a finalized case'
            );
        }

        $this->audit(
            $userId,
            'kyc.provider.session_created',
            (string) $case['public_id'],
            [
                'provider' => $this->providerName,
                'provider_session_id' => $session['provider_session_id'],
                'expires_at' => $session['expires_at'],
            ],
            $ipAddress,
            $userAgent
        );

        return [
            'case_public_id' => (string) $case['public_id'],
            'status' => $status,
            'provider_session_id' => $session['provider_session_id'],
            'collection_url' => $session['collection_url'],
            'expires_at' => $session['expires_at'],
        ];
    }

    /**
     * @return array{id:int|string,public_id:string,status:string,provider_session_id:?string,provider_collection_url:?string,provider_session_expires_at:?string}
     */
    private function loadOrCreateCase(int $userId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $case = $this->db->createCommand(
                'SELECT id, public_id, status, provider_session_id, provider_collection_url,
                        provider_session_expires_at
                 FROM {{%kyc_cases}} WHERE user_id = :id LIMIT 1 FOR UPDATE',
                [':id' => $userId]
            )->queryOne();

            if ($case === false) {
                $publicId = $this->uuid();
                $this->db->createCommand()->insert('{{%kyc_cases}}', [
                    'public_id' => $publicId,
                    'user_id' => $userId,
                    'status' => 'in_progress',
                ])->execute();
                $case = [
                    'id' => (int) $this->db->getLastInsertID(),
                    'public_id' => $publicId,
                    'status' => 'in_progress',
                    'provider_session_id' => null,
                    'provider_collection_url' => null,
                    'provider_session_expires_at' => null,
                ];
            }

            $transaction->commit();

            return $case;
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $case
     */
    private function canReuseSession(array $case): bool
    {
        if (($case['provider_session_id'] ?? null) === null || ($case['provider_collection_url'] ?? null) === null) {
            return false;
        }
        if (in_array($case['status'], ['error', 'expired'], true)) {
            return false;
        }
        $expiresAt = (string) ($case['provider_session_expires_at'] ?? '');

        return $expiresAt === '' || $expiresAt > gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $afterData
     */
    private function audit(
        int $userId,
        string $action,
        string $casePublicId,
        array $afterData,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->db->createCommand()->insert('{{%audit_logs}}', [
            'actor_user_id' => $userId,
            'action' => $action,
            'entity_type' => 'kyc_case',
            'entity_id' => $casePublicId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 500),
            'after_data' => new JsonExpression($afterData),
        ])->execute();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
