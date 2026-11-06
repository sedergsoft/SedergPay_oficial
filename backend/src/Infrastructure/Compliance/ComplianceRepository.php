<?php

declare(strict_types=1);

namespace app\Infrastructure\Compliance;

use app\Domain\Remittance\RemittanceStateMachine;
use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class ComplianceRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findUserId(string $publicId): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE public_id = :public_id AND deleted_at IS NULL LIMIT 1',
            [':public_id' => $publicId]
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    public function findRemittance(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT id, status, compliance_status
             FROM {{%remittances}}
             WHERE public_id = :public_id AND sender_user_id = :user_id
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function findIdempotency(string $scope, string $key): ?array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM {{%idempotency_keys}} WHERE scope = :scope AND idempotency_key = :key AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP) LIMIT 1',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function saveDecision(
        array $remittance,
        int $actorUserId,
        string $decision,
        string $reason,
        Transaction $transaction
    ): void {
        $currentStatus = $this->db->createCommand(
            'SELECT status FROM {{%remittances}} WHERE id = :id FOR UPDATE',
            [':id' => $remittance['id']]
        )->queryScalar();
        if (!is_string($currentStatus)) {
            throw new \DomainException('Remittance not found');
        }

        $newStatus = $decision === 'approved' ? 'approved' : 'rejected';
        (new RemittanceStateMachine())->assertCanTransition($currentStatus, $newStatus);

        $this->db->createCommand()->insert('{{%compliance_checks}}', [
            'remittance_id' => $remittance['id'],
            'check_type' => 'manual_sandbox_review',
            'provider' => 'simulator',
            'status' => $decision,
            'risk_level' => $decision === 'approved' ? 'low' : 'high',
            'external_reference' => 'sim-review-' . bin2hex(random_bytes(6)),
            'result_summary' => $reason,
            'checked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ])->execute();

        $this->db->createCommand()->update('{{%remittances}}', [
            'status' => $newStatus,
            'compliance_status' => $decision,
        ], ['id' => $remittance['id']])->execute();

        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittance['id'],
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
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
            'response_status' => 200,
            'response_body' => new JsonExpression($responseBody),
            'resource_type' => 'compliance_review',
            'resource_id' => $responseBody['remittance_public_id'],
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function beginTransaction(): Transaction
    {
        return $this->db->beginTransaction();
    }
}
