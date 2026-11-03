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
            'SELECT * FROM {{%idempotency_keys}} WHERE scope = :scope AND idempotency_key = :key AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1',
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
                    total_debit_amount, payout_method_code, fee_mode, status, expires_at
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
            'SELECT id, country_id, payout_method_code, payout_provider,
                    payout_account_last4, payout_card_last4
             FROM {{%beneficiaries}}
             WHERE public_id = :public_id AND sender_user_id = :user_id AND status = :status
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId, ':status' => 'active']
        )->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * Resuelve la dirección del corredor a partir de los códigos ISO de los países,
     * en lugar de depender del orden numérico de las claves primarias.
     */
    public function findDirection(int $sourceCountryId, int $destinationCountryId): string
    {
        $rows = $this->db->createCommand(
            'SELECT id, iso2 FROM {{%countries}} WHERE id IN (:source, :destination)',
            [':source' => $sourceCountryId, ':destination' => $destinationCountryId]
        )->queryAll();

        $isoByCountry = [];
        foreach ($rows as $row) {
            $isoByCountry[(int) $row['id']] = strtolower((string) $row['iso2']);
        }
        $source = $isoByCountry[$sourceCountryId] ?? null;
        $destination = $isoByCountry[$destinationCountryId] ?? null;
        if ($source === null || $destination === null) {
            throw new \DomainException('Country not found for remittance direction');
        }

        return $source . '_to_' . $destination;
    }

    /**
     * Bloquea la cotización y la marca como consumida, evitando que la misma
     * cotización se use para crear varias remesas (incluso en peticiones concurrentes).
     */
    public function consumeQuote(int $quoteId, Transaction $transaction): void
    {
        $quote = $this->db->createCommand(
            'SELECT status, expires_at FROM {{%remittance_quotes}} WHERE id = :id FOR UPDATE',
            [':id' => $quoteId]
        )->queryOne();
        if ($quote === false) {
            throw new \DomainException('Quote not found');
        }
        if ($quote['status'] !== 'valid' || new \DateTimeImmutable($quote['expires_at']) <= new \DateTimeImmutable()) {
            throw new \DomainException('Quote is expired or unavailable');
        }
        $this->db->createCommand()->update(
            '{{%remittance_quotes}}',
            ['status' => 'used'],
            ['id' => $quoteId]
        )->execute();
    }

    public function save(
        int $userId,
        array $quote,
        array $beneficiary,
        string $publicId,
        string $reference,
        string $direction,
        array $metadata,
        Transaction $transaction
    ): int {
        // Snapshot the payout method and masked destination details selected for this
        // operation. The beneficiary's preferred method may change later.
        $metadata['payout_method_code'] = $quote['payout_method_code'];
        $metadata['fee_mode'] = $quote['fee_mode'] ?? 'add_to_send';
        $metadata['fee_amount_snapshot'] = $quote['fee_amount'];
        $metadata['fee_currency_snapshot'] = $quote['fee_currency'];
        $metadata['payout_provider_snapshot'] = $beneficiary['payout_provider'] ?? null;
        $metadata['payout_account_last4_snapshot'] = $quote['payout_method_code'] === 'bank_account' ? ($beneficiary['payout_account_last4'] ?? null) : null;
        $metadata['payout_card_last4_snapshot'] = $quote['payout_method_code'] === 'card_transfer' ? ($beneficiary['payout_card_last4'] ?? null) : null;
        $this->db->createCommand()->insert('{{%remittances}}', [
            'public_id' => $publicId,
            'reference' => $reference,
            'sender_user_id' => $userId,
            'beneficiary_id' => $beneficiary['id'],
            'quote_id' => $quote['id'],
            'direction' => $direction,
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
                    d.confirmed_at AS deposit_confirmed_at, d.qr_payload AS deposit_qr_payload,
                    d.account_snapshot AS deposit_account_snapshot, d.payment_reference AS deposit_reference_snapshot,
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
        $row['payout_method_code'] = $row['metadata']['payout_method_code'] ?? $row['payout_method_code'];
        $row['payout_provider'] = $row['metadata']['payout_provider_snapshot'] ?? $row['payout_provider'];
        $row['payout_account_last4'] = $row['metadata']['payout_account_last4_snapshot'] ?? null;
        $row['payout_card_last4'] = $row['metadata']['payout_card_last4_snapshot'] ?? null;
        $snapshot = $this->decodeJson($row['deposit_account_snapshot'] ?? null);
        $row['payment_qr_payload'] = $row['deposit_qr_payload'] ?? ($row['metadata']['payment_qr_payload'] ?? null);
        $row['payment_instructions'] = array_merge($row['metadata'], $snapshot);
        if (!empty($row['deposit_reference_snapshot'])) {
            $row['payment_instructions']['reference'] = $row['deposit_reference_snapshot'];
        }
        unset($row['deposit_account_snapshot'], $row['deposit_qr_payload'], $row['deposit_reference_snapshot']);
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
