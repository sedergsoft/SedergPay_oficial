<?php

declare(strict_types=1);

namespace app\Infrastructure\Reconciliation;

use app\Domain\Remittance\RemittanceStateMachine;
use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class ReconciliationRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findUserId(string $publicId): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE public_id=:public_id AND deleted_at IS NULL LIMIT 1',
            [':public_id' => $publicId]
        )->queryScalar();
        return $id === false ? null : (int) $id;
    }

    public function findRemittance(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT r.id,r.public_id,r.status,d.matched_transaction_id
             FROM {{%remittances}} r
             LEFT JOIN {{%deposit_requests}} d ON d.remittance_id=r.id AND d.status=:confirmed
             WHERE r.public_id=:public_id AND r.sender_user_id=:user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId, ':confirmed' => 'confirmed']
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function findIdempotency(string $scope, string $key): ?array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM {{%idempotency_keys}} WHERE scope=:scope AND idempotency_key=:key LIMIT 1',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function complete(array $remittance, int $actorUserId, string $notes, Transaction $transaction): void
    {
        if ($remittance['matched_transaction_id'] === null) {
            throw new \DomainException('No confirmed bank transaction is available');
        }
        (new RemittanceStateMachine())->assertCanTransition($remittance['status'], 'completed');
        $this->db->createCommand()->insert('{{%reconciliation_cases}}', [
            'bank_transaction_id' => $remittance['matched_transaction_id'],
            'remittance_id' => $remittance['id'],
            'case_type' => 'remittance_payout',
            'status' => 'resolved',
            'match_score' => '100.00',
            'resolution_code' => 'sandbox_exact_match',
            'resolution_notes' => $notes,
            'resolved_by_user_id' => $actorUserId,
            'resolved_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ])->execute();
        $this->db->createCommand()->update('{{%remittances}}', [
            'status' => 'completed',
            'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $remittance['id']])->execute();
        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittance['id'],
            'from_status' => 'reconciliation_pending',
            'to_status' => 'completed',
            'actor_user_id' => $actorUserId,
            'reason' => $notes,
        ])->execute();
    }

    public function saveIdempotency(string $scope, string $key, string $hash, array $response, Transaction $transaction): void
    {
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $scope, 'idempotency_key' => $key, 'request_hash' => $hash,
            'response_status' => 200, 'response_body' => new JsonExpression($response),
            'resource_type' => 'reconciliation', 'resource_id' => $response['remittance_public_id'],
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function beginTransaction(): Transaction { return $this->db->beginTransaction(); }
}
