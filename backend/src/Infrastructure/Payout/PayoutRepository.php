<?php

declare(strict_types=1);

namespace app\Infrastructure\Payout;

use app\Domain\Remittance\RemittanceStateMachine;
use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class PayoutRepository
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
            'SELECT r.id,r.public_id,r.status,r.beneficiary_id,r.receive_amount,r.receive_currency,
                    b.payout_method_code
             FROM {{%remittances}} r
             JOIN {{%beneficiaries}} b ON b.id = r.beneficiary_id
             WHERE r.public_id=:public_id AND r.sender_user_id=:user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
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

    public function findRemittanceByPayout(string $payoutPublicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT r.id,r.public_id,r.status,r.beneficiary_id,r.receive_amount,r.receive_currency,
                    b.payout_method_code
             FROM {{%payout_orders}} p
             JOIN {{%remittances}} r ON r.id = p.remittance_id
             JOIN {{%beneficiaries}} b ON b.id = r.beneficiary_id
             WHERE p.public_id=:payout_public_id AND r.sender_user_id=:user_id LIMIT 1',
            [':payout_public_id' => $payoutPublicId, ':user_id' => $userId]
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function create(array $remittance, string $publicId, Transaction $transaction): int
    {
        $this->db->createCommand()->insert('{{%payout_orders}}', [
            'public_id' => $publicId,
            'remittance_id' => $remittance['id'],
            'beneficiary_id' => $remittance['beneficiary_id'],
            'payment_method_code' => $remittance['payout_method_code'],
            'provider' => 'simulator',
            'amount' => $remittance['receive_amount'],
            'currency_code' => $remittance['receive_currency'],
            'status' => 'pending',
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function complete(array $remittance, string $payoutPublicId, Transaction $transaction): void
    {
        $order = $this->db->createCommand(
            'SELECT id,status FROM {{%payout_orders}} WHERE public_id=:public_id AND remittance_id=:remittance_id FOR UPDATE',
            [':public_id' => $payoutPublicId, ':remittance_id' => $remittance['id']]
        )->queryOne();
        if ($order === false) {
            throw new \DomainException('Payout not found');
        }
        if ($order['status'] === 'paid') {
            return;
        }
        if ($order['status'] !== 'pending') {
            throw new \DomainException('Payout is not pending');
        }
        $machine = new RemittanceStateMachine();
        $machine->assertCanTransition($remittance['status'], 'funds_confirmed');
        $this->db->createCommand()->update('{{%remittances}}', [
            'status' => 'funds_confirmed',
        ], ['id' => $remittance['id']])->execute();
        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittance['id'],
            'from_status' => $remittance['status'],
            'to_status' => 'funds_confirmed',
            'actor_user_id' => null,
            'reason' => 'Sandbox funds confirmed before payout',
        ])->execute();
        $machine->assertCanTransition('funds_confirmed', 'payout_pending');
        $this->db->createCommand()->update('{{%payout_orders}}', [
            'status' => 'paid',
            'provider_reference' => 'sim-payout-' . bin2hex(random_bytes(6)),
            'paid_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $order['id']])->execute();
        $this->db->createCommand()->insert('{{%payout_attempts}}', [
            'payout_order_id' => $order['id'],
            'attempt_no' => 1,
            'provider_request_id' => 'sim-request-' . bin2hex(random_bytes(6)),
            'status' => 'succeeded',
            'request_payload' => new JsonExpression(['simulated' => true]),
            'response_payload' => new JsonExpression(['status' => 'paid']),
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ])->execute();
        $this->postLedger($remittance, $order['id']);
        $this->db->createCommand()->update('{{%remittances}}', [
            'status' => 'payout_pending',
        ], ['id' => $remittance['id']])->execute();
        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittance['id'],
            'from_status' => 'funds_confirmed',
            'to_status' => 'payout_pending',
            'actor_user_id' => null,
            'reason' => 'Sandbox payout started',
        ])->execute();
        $this->db->createCommand()->update('{{%remittances}}', [
            'status' => 'reconciliation_pending',
        ], ['id' => $remittance['id']])->execute();
        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittance['id'],
            'from_status' => 'payout_pending',
            'to_status' => 'reconciliation_pending',
            'actor_user_id' => null,
            'reason' => 'Simulated payout completed; awaiting reconciliation',
        ])->execute();
    }

    private function postLedger(array $remittance, int $payoutOrderId): void
    {
        $currency = $remittance['receive_currency'];
        $suffix = strtolower($currency);
        $accounts = $this->db->createCommand(
            'SELECT id,code FROM {{%ledger_accounts}} WHERE code IN (:funds,:payout)',
            [':funds' => 'ledger-sandbox-funds-' . $suffix, ':payout' => 'ledger-sandbox-payout-' . $suffix]
        )->queryAll();
        $byCode = [];
        foreach ($accounts as $account) {
            $byCode[$account['code']] = (int) $account['id'];
        }
        if (count($byCode) !== 2) {
            throw new \RuntimeException('Sandbox ledger accounts are not configured');
        }
        $this->db->createCommand()->insert('{{%ledger_transactions}}', [
            'public_id' => $this->uuid(),
            'reference' => 'PAYOUT-' . $payoutOrderId,
            'remittance_id' => $remittance['id'],
            'status' => 'posted',
            'description' => 'Simulated payout',
            'posted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ])->execute();
        $transactionId = (int) $this->db->getLastInsertID();
        foreach ([
            ['ledger-sandbox-funds-' . $suffix, 'debit'],
            ['ledger-sandbox-payout-' . $suffix, 'credit'],
        ] as [$code, $direction]) {
            $this->db->createCommand()->insert('{{%ledger_entries}}', [
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $byCode[$code],
                'direction' => $direction,
                'amount' => $remittance['receive_amount'],
                'currency_code' => $currency,
                'idempotency_key' => 'payout:' . $payoutOrderId . ':' . $direction,
            ])->execute();
        }
    }

    public function saveIdempotency(string $scope, string $key, string $hash, array $response, Transaction $transaction): void
    {
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $scope, 'idempotency_key' => $key, 'request_hash' => $hash,
            'response_status' => 201, 'response_body' => new JsonExpression($response),
            'resource_type' => 'payout_order', 'resource_id' => $response['public_id'],
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function beginTransaction(): Transaction { return $this->db->beginTransaction(); }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
