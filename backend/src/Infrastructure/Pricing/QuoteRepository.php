<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use yii\db\Connection;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class QuoteRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findIdempotency(string $scope, string $key): ?array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM {{%idempotency_keys}} WHERE scope = :scope AND idempotency_key = :key AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP) LIMIT 1',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function findUserIdByPublicId(string $publicId): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE public_id = :public_id AND deleted_at IS NULL LIMIT 1',
            [':public_id' => $publicId]
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    public function findCountryId(string $iso2): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%countries}} WHERE iso2 = :iso2 AND is_active = TRUE LIMIT 1',
            [':iso2' => strtoupper($iso2)]
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    public function paymentMethodExists(string $code): bool
    {
        return (bool) $this->db->createCommand(
            'SELECT 1 FROM {{%payment_methods}} WHERE code = :code AND is_active = TRUE LIMIT 1',
            [':code' => $code]
        )->queryScalar();
    }

    public function findQuoteForUser(string $publicId, int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT public_id, send_amount, send_currency, receive_amount, receive_currency,
                    exchange_rate, fee_amount, fee_currency, total_debit_amount,
                    payout_method_code, fee_mode, status, expires_at
             FROM {{%remittance_quotes}}
             WHERE public_id = :public_id AND user_id = :user_id
             LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();

        return $row === false ? null : $row;
    }

    public function saveQuote(
        int $userId,
        array $request,
        array $calculated,
        string $publicId,
        Transaction $transaction
    ): int {
        $sourceCountryId = $this->findCountryId($request['source_country_code']);
        $destinationCountryId = $this->findCountryId($request['destination_country_code']);
        if ($sourceCountryId === null || $destinationCountryId === null) {
            throw new \DomainException('Unsupported country');
        }

        $this->db->createCommand()->insert('{{%remittance_quotes}}', [
            'public_id' => $publicId,
            'user_id' => $userId,
            'source_country_id' => $sourceCountryId,
            'destination_country_id' => $destinationCountryId,
            'send_amount' => $calculated['send_amount'],
            'send_currency' => strtoupper($request['send_currency']),
            'receive_amount' => $calculated['receive_amount'],
            'receive_currency' => strtoupper($request['receive_currency']),
            'exchange_rate' => $calculated['exchange_rate'],
            'fee_amount' => $calculated['fee_amount'],
            'fee_currency' => $calculated['fee_currency'],
            'fee_mode' => $calculated['fee_mode'] ?? ($request['fee_mode'] ?? 'add_to_send'),
            'total_debit_amount' => $calculated['total_debit_amount'],
            'payout_method_code' => $request['payout_method_code'],
            'status' => $calculated['status'],
            'expires_at' => (new \DateTimeImmutable($calculated['expires_at']))->format('Y-m-d H:i:s'),
        ])->execute();

        return (int) $this->db->createCommand("SELECT currval(pg_get_serial_sequence('remittance_quotes', 'id'))")->queryScalar();
    }

    public function saveIdempotency(
        string $scope,
        string $key,
        string $requestHash,
        int $responseStatus,
        array $responseBody,
        string $resourceId,
        Transaction $transaction
    ): void {
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'response_status' => $responseStatus,
            'response_body' => new JsonExpression($responseBody),
            'resource_type' => 'quote',
            'resource_id' => $resourceId,
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
        ])->execute();
    }

    public function beginTransaction(): Transaction
    {
        return $this->db->beginTransaction();
    }
}
