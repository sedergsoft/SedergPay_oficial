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
        array $metadata,
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
            'metadata' => new JsonExpression($metadata),
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
                    r.created_at, r.updated_at, r.paid_at, r.completed_at, r.cancelled_at,
                    r.failure_reason_code, r.metadata,
                    b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name,
                    b.phone_e164 AS beneficiary_phone, b.email AS beneficiary_email,
                    b.payout_method_code, b.payout_provider,
                    b.payout_account_last4, b.payout_card_last4,
                    su.email AS sender_email, sp.first_name AS sender_first_name,
                    sp.last_name AS sender_last_name,
                    d.public_id AS deposit_public_id, d.payment_reference,
                    d.expected_amount AS deposit_expected_amount, d.currency_code AS deposit_currency,
                    d.status AS deposit_status, d.expires_at AS deposit_expires_at,
                    d.confirmed_at AS deposit_confirmed_at,
                    po.public_id AS payout_public_id, po.status AS payout_status,
                    po.provider_reference AS payout_provider_reference, po.paid_at AS payout_paid_at
             FROM {{%remittances}} r
             INNER JOIN {{%beneficiaries}} b ON b.id = r.beneficiary_id
             INNER JOIN {{%users}} su ON su.id = r.sender_user_id
             LEFT JOIN {{%user_profiles}} sp ON sp.user_id = su.id
             LEFT JOIN {{%deposit_requests}} d ON d.remittance_id = r.id
             LEFT JOIN {{%payout_orders}} po ON po.remittance_id = r.id
             WHERE r.public_id = :public_id AND r.sender_user_id = :user_id
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();

        if ($row === false) {
            return null;
        }
        $row['metadata'] = $this->decodeJson($row['metadata'] ?? null);
        $row['payment_qr_payload'] = $row['metadata']['payment_qr_payload'] ?? null;
        $row['payment_instructions'] = $row['metadata'];
        $row['state_events'] = $this->db->createCommand(
            'SELECT from_status, to_status, reason, created_at
             FROM {{%remittance_state_events}} WHERE remittance_id = (
                 SELECT id FROM {{%remittances}} WHERE public_id = :public_id AND sender_user_id = :user_id LIMIT 1
             ) ORDER BY created_at ASC, id ASC',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryAll();
        return $row;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
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
