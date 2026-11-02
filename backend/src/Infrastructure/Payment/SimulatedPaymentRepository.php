<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

use app\Domain\Ledger\DoubleEntryValidator;
use app\Domain\Remittance\RemittanceStateMachine;
use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class SimulatedPaymentRepository
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
            'SELECT id, reference, total_debit_amount, send_currency, status
             FROM {{%remittances}} WHERE public_id = :public_id AND sender_user_id = :user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function findDeposit(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT d.public_id, d.expected_amount, d.currency_code, d.payment_reference,
                    d.status, d.expires_at, d.confirmed_at
             FROM {{%deposit_requests}} d
             JOIN {{%remittances}} r ON r.id = d.remittance_id
             WHERE d.public_id = :public_id AND r.sender_user_id = :user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function findRemittanceByDeposit(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT r.id, r.total_debit_amount, r.send_currency, r.status
             FROM {{%deposit_requests}} d
             JOIN {{%remittances}} r ON r.id = d.remittance_id
             WHERE d.public_id = :public_id AND r.sender_user_id = :user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function findIdempotency(string $scope, string $key): ?array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM {{%idempotency_keys}} WHERE scope = :scope AND idempotency_key = :key AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();
        return $row === false ? null : $row;
    }

    public function createDeposit(array $remittance, string $publicId, string $reference, Transaction $transaction): int
    {
        $lockedRemittance = $this->db->createCommand(
            'SELECT id FROM {{%remittances}} WHERE id = :id FOR UPDATE',
            [':id' => $remittance['id']]
        )->queryScalar();
        if ($lockedRemittance === false) {
            throw new \DomainException('Remittance not found');
        }
        $activeDeposit = (int) $this->db->createCommand(
            "SELECT COUNT(*) FROM {{%deposit_requests}}
             WHERE remittance_id = :remittance_id AND status IN ('pending', 'confirmed')
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
            [':remittance_id' => $remittance['id']]
        )->queryScalar();
        if ($activeDeposit > 0) {
            throw new \DomainException('An active deposit request already exists for this remittance');
        }
        $bankId = $this->db->createCommand(
            "SELECT id FROM {{%bank_connections}}
             WHERE bank_code = 'simulator' AND environment = 'sandbox' AND status = 'active' LIMIT 1"
        )->queryScalar();
        if ($bankId === false) {
            throw new \RuntimeException('Simulated payment provider is not configured');
        }
        $this->db->createCommand()->insert('{{%deposit_requests}}', [
            'public_id' => $publicId,
            'remittance_id' => $remittance['id'],
            'bank_connection_id' => (int) $bankId,
            'expected_amount' => $remittance['total_debit_amount'],
            'currency_code' => $remittance['send_currency'],
            'payment_reference' => $reference,
            'status' => 'pending',
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function confirmDeposit(
        array $remittance,
        string $depositPublicId,
        string $providerTransactionId,
        Transaction $transaction
    ): void {
        $deposit = $this->db->createCommand(
            'SELECT id, status FROM {{%deposit_requests}}
             WHERE public_id = :public_id AND remittance_id = :remittance_id FOR UPDATE',
            [':public_id' => $depositPublicId, ':remittance_id' => $remittance['id']]
        )->queryOne();
        if ($deposit === false) {
            throw new \DomainException('Deposit request not found');
        }
        if ($deposit['status'] === 'confirmed') {
            return;
        }
        if ($deposit['status'] !== 'pending') {
            throw new \DomainException('Deposit request is not pending');
        }
        $connectionId = (int) $this->db->createCommand(
            "SELECT id FROM {{%bank_connections}} WHERE bank_code = 'simulator' AND environment = 'sandbox' LIMIT 1"
        )->queryScalar();
        $this->db->createCommand()->insert('{{%bank_transactions}}', [
            'bank_connection_id' => $connectionId,
            'provider_transaction_id' => $providerTransactionId,
            'transaction_type' => 'credit',
            'amount' => $remittance['total_debit_amount'],
            'currency_code' => $remittance['send_currency'],
            'payment_reference' => $depositPublicId,
            'description' => 'Simulated deposit confirmation',
            'booked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'provider_status' => 'booked',
            'raw_payload' => new JsonExpression(['simulated' => true]),
            'source' => 'simulator',
        ])->execute();
        $transactionId = (int) $this->db->getLastInsertID();
        $this->db->createCommand()->update('{{%deposit_requests}}', [
            'status' => 'confirmed',
            'matched_transaction_id' => $transactionId,
            'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $deposit['id']])->execute();
        (new RemittanceStateMachine())->assertCanTransition($remittance['status'], 'compliance_review');
        $this->db->createCommand()->update('{{%remittances}}', [
            'status' => 'compliance_review',
        ], ['id' => $remittance['id']])->execute();
        $this->db->createCommand()->insert('{{%remittance_state_events}}', [
            'remittance_id' => $remittance['id'],
            'from_status' => $remittance['status'],
            'to_status' => 'compliance_review',
            'actor_user_id' => null,
            'reason' => 'Simulated deposit confirmed; pending compliance review',
        ])->execute();
        $this->postDepositLedger($remittance, $transactionId);
    }

    private function postDepositLedger(array $remittance, int $bankTransactionId): void
    {
        $currency = $remittance['send_currency'];
        $suffix = strtolower($currency);
        $accounts = $this->db->createCommand(
            'SELECT id,code FROM {{%ledger_accounts}} WHERE code IN (:funds,:deposits)',
            [':funds' => 'ledger-sandbox-funds-' . $suffix, ':deposits' => 'ledger-sandbox-deposits-' . $suffix]
        )->queryAll();
        $byCode = [];
        foreach ($accounts as $account) {
            $byCode[$account['code']] = (int) $account['id'];
        }
        if (count($byCode) !== 2) {
            throw new \RuntimeException('Sandbox deposit ledger accounts are not configured');
        }
        $amount = (string) $remittance['total_debit_amount'];
        (new DoubleEntryValidator())->assertBalanced([
            ['direction' => 'debit', 'amount' => $amount, 'currency' => $currency],
            ['direction' => 'credit', 'amount' => $amount, 'currency' => $currency],
        ]);
        $this->db->createCommand()->insert('{{%ledger_transactions}}', [
            'public_id' => $this->uuid(),
            'reference' => 'DEPOSIT-' . $bankTransactionId,
            'remittance_id' => $remittance['id'],
            'status' => 'posted',
            'description' => 'Simulated deposit',
            'posted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ])->execute();
        $transactionId = (int) $this->db->getLastInsertID();
        foreach ([
            ['ledger-sandbox-funds-' . $suffix, 'debit'],
            ['ledger-sandbox-deposits-' . $suffix, 'credit'],
        ] as [$code, $direction]) {
            $this->db->createCommand()->insert('{{%ledger_entries}}', [
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $byCode[$code],
                'direction' => $direction,
                'amount' => $amount,
                'currency_code' => $currency,
                'idempotency_key' => 'deposit:' . $bankTransactionId . ':' . $direction,
            ])->execute();
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function saveIdempotency(string $scope, string $key, string $hash, array $response, Transaction $transaction): void
    {
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'response_status' => 201,
            'response_body' => new JsonExpression($response),
            'resource_type' => 'deposit_request',
            'resource_id' => $response['public_id'],
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function beginTransaction(): Transaction
    {
        return $this->db->beginTransaction();
    }
}
