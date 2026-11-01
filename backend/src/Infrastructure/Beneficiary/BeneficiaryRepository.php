<?php

declare(strict_types=1);

namespace app\Infrastructure\Beneficiary;

use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class BeneficiaryRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findIdempotency(string $scope, string $key): ?array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM {{%idempotency_keys}} WHERE scope = :scope AND idempotency_key = :key LIMIT 1',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function findUserId(string $publicId): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE public_id = :public_id AND deleted_at IS NULL LIMIT 1',
            [':public_id' => $publicId]
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    public function countryId(string $iso2): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%countries}} WHERE iso2 = :iso2 AND is_active = 1 LIMIT 1',
            [':iso2' => $iso2]
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    public function paymentMethodExists(string $code): bool
    {
        return (bool) $this->db->createCommand(
            'SELECT 1 FROM {{%payment_methods}} WHERE code = :code AND is_active = 1 LIMIT 1',
            [':code' => $code]
        )->queryScalar();
    }

    public function save(
        int $userId,
        array $payload,
        int $countryId,
        string $publicId,
        Transaction $transaction
    ): void {
        $this->db->createCommand()->insert('{{%beneficiaries}}', [
            'public_id' => $publicId,
            'sender_user_id' => $userId,
            'country_id' => $countryId,
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'phone_e164' => $payload['phone_e164'] ?? null,
            'email' => isset($payload['email']) ? strtolower($payload['email']) : null,
            'payout_method_code' => $payload['payout_method_code'],
            'payout_provider' => $payload['payout_provider'] ?? null,
            'payout_account_token' => $payload['payout_account_token'] ?? null,
            'payout_account_last4' => $payload['payout_account_last4'] ?? null,
            'payout_card_token' => $payload['payout_card_token'] ?? null,
            'payout_card_last4' => $payload['payout_card_last4'] ?? null,
            'status' => 'active',
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
            'resource_type' => 'beneficiary',
            'resource_id' => $responseBody['public_id'],
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function findForUser(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT b.public_id, c.iso2 AS country_code, b.first_name, b.last_name,
                    b.phone_e164, b.email, b.payout_method_code, b.payout_provider,
                    b.payout_account_last4, b.payout_card_last4, b.status, b.created_at, b.updated_at
             FROM {{%beneficiaries}} b
             JOIN {{%countries}} c ON c.id = b.country_id
             WHERE b.public_id = :public_id AND b.sender_user_id = :user_id
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function listForUser(int $userId): array
    {
        return $this->db->createCommand(
            'SELECT b.public_id, c.iso2 AS country_code, b.first_name, b.last_name,
                    b.phone_e164, b.email, b.payout_method_code, b.payout_provider,
                    b.payout_account_last4, b.payout_card_last4, b.status, b.created_at, b.updated_at
             FROM {{%beneficiaries}} b
             JOIN {{%countries}} c ON c.id = b.country_id
             WHERE b.sender_user_id = :user_id AND b.status = :status
             ORDER BY b.created_at DESC',
            [':user_id' => $userId, ':status' => 'active']
        )->queryAll();
    }

    public function beginTransaction(): Transaction
    {
        return $this->db->beginTransaction();
    }
}
