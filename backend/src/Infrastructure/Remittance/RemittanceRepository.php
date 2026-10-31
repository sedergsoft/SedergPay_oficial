<?php

declare(strict_types=1);

namespace app\Infrastructure\Remittance;

use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class RemittanceRepository
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

    public function findQuoteForUser(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT id, source_country_id, destination_country_id, send_amount, send_currency,
                    receive_amount, receive_currency, exchange_rate, fee_amount, fee_currency,
                    total_debit_amount, payout_method_code, status, expires_at
             FROM {{%remittance_quotes}}
             WHERE public_id = :public_id AND user_id = :user_id
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function findBeneficiaryForUser(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT id, country_id, payout_method_code
             FROM {{%beneficiaries}}
             WHERE public_id = :public_id AND sender_user_id = :user_id AND status = :status
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId, ':status' => 'active']
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function save(
        int $userId,
        array $quote,
        array $beneficiary,
        string $publicId,
        string $reference,
        Transaction $transaction
    ): int {
        $this->db->createCommand()->insert('{{%remittances}}', [
            'public_id' => $publicId,
            'reference' => $reference,
            'sender_user_id' => $userId,
            'beneficiary_id' => $beneficiary['id'],
            'quote_id' => $quote['id'],
            'direction' => (int) $quote['source_country_id'] === 1 ? 'ru_to_cu' : 'cu_to_ru',
            'source_country_id' => $quote['source_country_id'],
            'destination_country_id' => $quote['destination_country_id'],
            'send_amount' => $quote['send_amount'],
            'send_currency' => $quote['send_currency'],
            'receive_amount' => $quote['receive_amount'],
            'receive_currency' => $quote['receive_currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'fee_amount' => $quote['fee_amount'],
            'fee_currency' => $quote['fee_currency'],
            'total_debit_amount' => $quote['total_debit_amount'],
            'status' => 'awaiting_payment',
            'compliance_status' => 'pending',
        ])->execute();
        $remittanceId = (int) $this->db->getLastInsertID();

        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittanceId,
            'from_status' => null,
            'to_status' => 'awaiting_payment',
            'actor_user_id' => $userId,
            'reason' => 'Remittance created from valid quote',
        ])->execute();

        return $remittanceId;
    }

    public function saveIdempotency(
        string $scope,
        string $key,
        string $requestHash,
        array $responseBody,
        int $remittanceId,
        Transaction $transaction
    ): void {
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'response_status' => 201,
            'response_body' => new JsonExpression($responseBody),
            'resource_type' => 'remittance',
            'resource_id' => (string) $remittanceId,
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function findForUser(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT r.public_id, r.reference, r.direction, r.send_amount, r.send_currency,
                    r.receive_amount, r.receive_currency, r.exchange_rate, r.fee_amount,
                    r.fee_currency, r.total_debit_amount, r.status, r.compliance_status,
                    r.created_at, r.updated_at
             FROM {{%remittances}} r
             WHERE r.public_id = :public_id AND r.sender_user_id = :user_id
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function listForUser(int $userId): array
    {
        return $this->db->createCommand(
            'SELECT r.public_id, r.reference, r.direction, r.send_amount, r.send_currency,
                    r.receive_amount, r.receive_currency, r.status, r.compliance_status,
                    r.created_at, r.updated_at
             FROM {{%remittances}} r
             WHERE r.sender_user_id = :user_id
             ORDER BY r.created_at DESC',
            [':user_id' => $userId]
        )->queryAll();
    }

    public function beginTransaction(): Transaction
    {
        return $this->db->beginTransaction();
    }
}
