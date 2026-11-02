<?php

declare(strict_types=1);

namespace app\Infrastructure\Registration;

use app\Infrastructure\Configuration\PlatformSettings;
use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Query;
use yii\db\Transaction;

final class RegistrationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findIdempotency(string $scope, string $key): ?array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM {{%idempotency_keys}} WHERE scope = :scope AND idempotency_key = :key AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function contactExists(string $email, ?string $phone): bool
    {
        $conditions = [['email' => $email]];
        if ($phone !== null) {
            $conditions[] = ['phone_e164' => $phone];
        }

        return (new Query())
            ->from('{{%users}}')
            ->where(['or', ...$conditions])
            ->andWhere(['deleted_at' => null])
            ->exists($this->db);
    }

    public function countryId(string $iso2): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%countries}} WHERE iso2 = :iso2 AND is_active = 1 LIMIT 1',
            [':iso2' => $iso2]
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    public function saveRegistration(
        array $payload,
        string $publicId,
        string $passwordHash,
        int $countryId,
        string $policyVersion,
        string $ipAddress,
        string $userAgent,
        Transaction $transaction
    ): int {
        $this->db->createCommand()->insert('{{%users}}', [
            'public_id' => $publicId,
            'email' => $payload['email'],
            'phone_e164' => $payload['phone_e164'] ?? null,
            'password_hash' => $passwordHash,
            'status' => 'pending',
            'preferred_language' => $payload['preferred_language'] ?? 'es',
            'country_id' => $countryId,
        ])->execute();
        $userId = (int) $this->db->getLastInsertID();

        $this->db->createCommand()->insert('{{%user_profiles}}', [
            'user_id' => $userId,
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'residence_country_id' => $countryId,
        ])->execute();

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (['terms_of_service', 'privacy_policy'] as $consentType) {
            $this->db->createCommand()->insert('{{%user_consents}}', [
                'user_id' => $userId,
                'consent_type' => $consentType,
                'policy_version' => $policyVersion,
                'granted_at' => $now,
                'ip_address' => $ipAddress !== '' ? $ipAddress : null,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
            ])->execute();
        }

        return $userId;
    }

    public function saveVerificationToken(int $userId, string $tokenHash): void
    {
        $this->db->createCommand()->insert('{{%email_verification_tokens}}', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => (new \DateTimeImmutable('+' . PlatformSettings::get($this->db, 'security.verification_token_ttl_minutes') . ' minutes'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function saveIdempotency(
        string $scope,
        string $key,
        string $requestHash,
        array $responseBody,
        Transaction $transaction
    ): void {
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'response_status' => 201,
            'response_body' => new JsonExpression($responseBody),
            'resource_type' => 'user',
            'resource_id' => $responseBody['public_id'],
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function beginTransaction(): Transaction
    {
        return $this->db->beginTransaction();
    }
}
