<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use yii\db\Connection;
use yii\db\JsonExpression;

/**
 * Owns the lifecycle of a payable payment intent.
 *
 * A deposit request is the persistence row; this service turns it into
 * something a payer can actually complete:
 *
 *  - one active intent per remittance, enforced by locking the remittance row;
 *  - an opaque capability token that backs the public payment page;
 *  - a QR payload that is always a scannable URL (provider QR when the rail
 *    returns a real one, informational link otherwise);
 *  - expiry and sandbox confirmation that never trusts a scan by itself.
 *
 * The QR content is built here, never in the frontend.
 */
final class PaymentIntentService
{
    private const DEFAULT_TTL_MINUTES = 1440;
    private const MIN_TTL_MINUTES = 5;
    private const MAX_TTL_MINUTES = 10080;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Creates (or reuses) the active payment intent for a remittance.
     *
     * The remittance row is locked before counting active deposits, so two
     * concurrent requests cannot create two payable intents.
     *
     * @param array{id:int,total_debit_amount:string,send_currency:string,status:string,reference?:string} $remittance
     * @param array<string,mixed> $account
     * @param array{scope:string,key:string,hash:string}|null $idempotency
     * @return array<string,mixed>
     */
    public function createIntent(array $remittance, array $account, string $methodCode, ?array $idempotency = null): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $locked = $this->db->createCommand(
                'SELECT id,status FROM {{%remittances}} WHERE id=:id FOR UPDATE',
                [':id' => $remittance['id']]
            )->queryOne();
            if ($locked === false) {
                throw new DomainException('Remittance not found');
            }

            if ($idempotency !== null) {
                $existing = $this->db->createCommand(
                    'SELECT request_hash,response_body FROM {{%idempotency_keys}}
                     WHERE scope=:scope AND idempotency_key=:key
                       AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())',
                    [':scope' => $idempotency['scope'], ':key' => $idempotency['key']]
                )->queryOne();
                if ($existing !== false) {
                    if (!hash_equals((string) $existing['request_hash'], $idempotency['hash'])) {
                        throw new IdempotencyConflictException('Idempotency-Key was already used with another request');
                    }
                    $transaction->commit();

                    return json_decode((string) $existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
                }
            }

            if ($locked['status'] !== 'awaiting_payment') {
                throw new DomainException('Remittance is not awaiting payment');
            }

            $this->expireStaleForRemittance((int) $remittance['id']);

            $active = $this->db->createCommand(
                "SELECT id,public_id,expected_amount,currency_code,payment_reference,expires_at,
                        qr_payload,qr_kind,pay_token,status,provider_payment_id,account_snapshot
                 FROM {{%deposit_requests}}
                 WHERE remittance_id=:id AND status='pending'
                 ORDER BY id DESC LIMIT 1",
                [':id' => $remittance['id']]
            )->queryOne();

            if ($active !== false && trim((string) $active['pay_token']) !== '') {
                $response = $this->buildResponse($active, $this->decodeJson($active['account_snapshot']));
                $this->storeIdempotency($idempotency, $response);
                $transaction->commit();

                return $response;
            }

            // A legacy or tokenless pending row cannot be paid. Retire it so it
            // never shadows the new intent in the remittance detail.
            if ($active !== false) {
                $this->expireById((int) $active['id']);
            }

            $snapshot = $this->issueIntent($remittance, $account, $methodCode);
            $this->db->createCommand()->insert('{{%deposit_requests}}', [
                'public_id' => $snapshot['deposit_public_id'],
                'remittance_id' => (int) $remittance['id'],
                'bank_connection_id' => (int) $snapshot['bank_connection_id'],
                'funding_account_id' => (int) $account['id'],
                'funding_method_code' => $methodCode,
                'account_snapshot' => new JsonExpression($snapshot['account_snapshot']),
                'qr_payload' => $snapshot['qr_payload'],
                'qr_expires_at' => $snapshot['expires_at'],
                'qr_kind' => $snapshot['qr_kind'],
                'pay_token' => $snapshot['pay_token'],
                'provider_payment_id' => $snapshot['provider_payment_id'],
                'expected_amount' => $remittance['total_debit_amount'],
                'currency_code' => $remittance['send_currency'],
                'payment_reference' => $snapshot['payment_reference'],
                'status' => 'pending',
                'expires_at' => $snapshot['expires_at'],
            ])->execute();

            $row = [
                'public_id' => $snapshot['deposit_public_id'],
                'expected_amount' => $remittance['total_debit_amount'],
                'currency_code' => $remittance['send_currency'],
                'payment_reference' => $snapshot['payment_reference'],
                'expires_at' => $snapshot['expires_at'],
                'qr_payload' => $snapshot['qr_payload'],
                'qr_kind' => $snapshot['qr_kind'],
                'pay_token' => $snapshot['pay_token'],
                'status' => 'pending',
                'provider_payment_id' => $snapshot['provider_payment_id'],
                'account_snapshot' => $snapshot['account_snapshot'],
            ];
            $response = $this->buildResponse($row, $snapshot['account_snapshot']);
            $this->storeIdempotency($idempotency, $response, $snapshot['expires_at']);
            $transaction->commit();

            return $response;
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Returns the payer-facing view of an intent by its capability token.
     * The token is the only credential: it must never be logged or indexed.
     *
     * @return array<string,mixed>|null
     */
    public function findPublic(string $token): ?array
    {
        $row = $this->db->createCommand(
            'SELECT d.public_id, d.expected_amount, d.currency_code, d.payment_reference,
                    d.status, d.expires_at, d.confirmed_at, d.paid_amount, d.paid_at,
                    d.qr_payload, d.qr_kind, d.pay_token, d.account_snapshot,
                    r.public_id AS remittance_public_id, r.reference AS remittance_reference,
                    r.status AS remittance_status
             FROM {{%deposit_requests}} d
             INNER JOIN {{%remittances}} r ON r.id=d.remittance_id
             WHERE d.pay_token=:token LIMIT 1',
            [':token' => $token]
        )->queryOne();
        if ($row === false) {
            return null;
        }
        $snapshot = $this->decodeJson($row['account_snapshot']);
        $expired = $this->isExpired($row['expires_at'], (string) $row['status']);

        return [
            'public_id' => $row['public_id'],
            'remittance_public_id' => $row['remittance_public_id'],
            'remittance_reference' => $row['remittance_reference'],
            'remittance_status' => $row['remittance_status'],
            'payment_reference' => $row['payment_reference'],
            'amount' => $row['expected_amount'],
            'currency_code' => $row['currency_code'],
            'status' => $expired ? 'expired' : $row['status'],
            'expires_at' => $this->atom((string) $row['expires_at']),
            'confirmed_at' => $this->atom($row['confirmed_at']),
            'paid_amount' => $row['paid_amount'],
            'method_name' => $snapshot['method_name'] ?? null,
            'bank_name' => $snapshot['bank_name'] ?? null,
            'account_holder_name' => $snapshot['account_holder_name'] ?? 'SEDERG_PAY',
            'account_mask' => $snapshot['account_mask'] ?? null,
            'routing_mask' => $snapshot['routing_mask'] ?? null,
            'qr_kind' => $row['qr_kind'],
            'pay_token' => $row['pay_token'],
        ];
    }

    /**
     * Confirms the intent through the sandbox simulator. The caller must have
     * already validated that sandbox confirmation is allowed in this environment.
     *
     * @return array{public_id:string,status:string,remittance_status:string}
     */
    public function confirmByToken(string $token): array
    {
        $deposit = $this->db->createCommand(
            'SELECT d.id, d.public_id, d.status, d.expires_at,
                    r.id AS remittance_id, r.total_debit_amount, r.send_currency, r.status AS remittance_status
             FROM {{%deposit_requests}} d
             INNER JOIN {{%remittances}} r ON r.id=d.remittance_id
             WHERE d.pay_token=:token LIMIT 1',
            [':token' => $token]
        )->queryOne();
        if ($deposit === false) {
            throw new DomainException('Payment link not found');
        }
        if ($deposit['status'] === 'confirmed') {
            return [
                'public_id' => $deposit['public_id'],
                'status' => 'confirmed',
                'remittance_status' => $deposit['remittance_status'],
            ];
        }
        if ($this->isExpired($deposit['expires_at'], (string) $deposit['status'])) {
            throw new DomainException('This payment link has expired. Generate a new one from the operation.');
        }
        if ($deposit['status'] !== 'pending') {
            throw new DomainException('This payment link can no longer be paid');
        }
        if ($deposit['remittance_status'] !== 'awaiting_payment') {
            throw new DomainException('The operation is not awaiting payment');
        }

        $repository = new SimulatedPaymentRepository($this->db);
        $transaction = $this->db->beginTransaction();
        try {
            $repository->confirmDeposit(
                [
                    'id' => (int) $deposit['remittance_id'],
                    'total_debit_amount' => $deposit['total_debit_amount'],
                    'send_currency' => $deposit['send_currency'],
                    'status' => $deposit['remittance_status'],
                ],
                (string) $deposit['public_id'],
                'sim-tx-' . bin2hex(random_bytes(6)),
                $transaction
            );
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        return [
            'public_id' => $deposit['public_id'],
            'status' => 'confirmed',
            'remittance_status' => 'compliance_review',
        ];
    }

    /** Expires every pending intent past its deadline. Returns the affected rows. */
    public function expireStale(): int
    {
        return $this->db->createCommand(
            "UPDATE {{%deposit_requests}}
             SET status='expired', updated_at=UTC_TIMESTAMP()
             WHERE status='pending' AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()"
        )->execute();
    }

    public static function sandboxConfirmationEnabled(): bool
    {
        if (getenv('SEDERG_AUTH_MODE') === 'development') {
            return true;
        }

        return in_array(getenv('SEDERG_SANDBOX_PAYMENTS'), ['1', 'true', 'yes'], true);
    }

    /**
     * @param array<string,mixed> $remittance
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private function issueIntent(array $remittance, array $account, string $methodCode): array
    {
        $connectionId = $this->db->createCommand(
            "SELECT id FROM {{%bank_connections}}
             WHERE bank_code='simulator' AND environment='sandbox' AND status='active' LIMIT 1"
        )->queryScalar();
        if ($connectionId === false) {
            throw new DomainException('Sandbox payment simulator is not configured');
        }

        $depositId = self::uuid();
        $reference = 'PAY-' . strtoupper(bin2hex(random_bytes(5)));
        $token = bin2hex(random_bytes(24));
        $expiresAt = $this->expiresAt();
        $payUrl = $this->publicBaseUrl() . '/pay/' . $token;

        $qrPayload = $payUrl;
        $qrKind = 'link';
        $qrType = 'pay_link';
        $providerPaymentId = null;

        if ((int) ($account['supports_dynamic_qr'] ?? 0) === 1
            && ($account['method_type'] ?? '') === 'instant_qr'
            && !empty($account['provider_code'])) {
            $issued = (new FundingPaymentProviderFactory())
                ->create((string) $account['provider_code'], (string) $account['environment'])
                ->createDynamicQr(
                    (string) $remittance['total_debit_amount'],
                    (string) $remittance['send_currency'],
                    $reference,
                    $expiresAt,
                    'SEDERG_PAY'
                );
            $providerPaymentId = $issued['provider_payment_id'] ?? null;
            $providerPayload = (string) ($issued['payload'] ?? '');

            // Only a routable payload is presented as a provider QR. Sandbox
            // providers return a non-routable schema, so the informational
            // payment link is used instead and labelled honestly in the UI.
            if (preg_match('#^https?://#i', $providerPayload) === 1) {
                $qrPayload = $providerPayload;
                $qrKind = 'provider_dynamic';
                $qrType = (string) $account['provider_code'];
            }
        }

        return [
            'deposit_public_id' => $depositId,
            'bank_connection_id' => (int) $connectionId,
            'payment_reference' => $reference,
            'pay_token' => $token,
            'expires_at' => $expiresAt,
            'qr_payload' => $qrPayload,
            'qr_kind' => $qrKind,
            'qr_type' => $qrType,
            'provider_payment_id' => $providerPaymentId,
            'account_snapshot' => [
                'platform' => 'SEDERG_PAY',
                'bank_code' => $account['bank_code'],
                'bank_name' => $account['bank_name'],
                'account_holder_name' => 'SEDERG_PAY',
                'account_mask' => $account['account_mask'],
                'routing_mask' => $account['routing_mask'],
                'currency_code' => $remittance['send_currency'],
                'amount' => $remittance['total_debit_amount'],
                'method_code' => $methodCode,
                'method_name' => $account['name'],
                'reference' => $reference,
                'environment' => 'sandbox',
                'qr_type' => $qrType,
                'qr_kind' => $qrKind,
                'pay_url' => $payUrl,
                'provider_code' => $account['provider_code'] ?? null,
                'provider_payment_id' => $providerPaymentId,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function buildResponse(array $row, array $snapshot): array
    {
        $token = (string) $row['pay_token'];
        $payUrl = (string) ($snapshot['pay_url'] ?? ($this->publicBaseUrl() . '/pay/' . $token));

        return [
            'public_id' => $row['public_id'],
            'provider' => 'simulator',
            'status' => $row['status'],
            'expected_amount' => $row['expected_amount'],
            'currency_code' => $row['currency_code'],
            'payment_reference' => $row['payment_reference'],
            'expires_at' => $this->atom((string) $row['expires_at']),
            'method_code' => $snapshot['method_code'] ?? null,
            'method_name' => $snapshot['method_name'] ?? null,
            'bank_name' => $snapshot['bank_name'] ?? null,
            'account_holder_name' => $snapshot['account_holder_name'] ?? 'SEDERG_PAY',
            'account_mask' => $snapshot['account_mask'] ?? null,
            'routing_mask' => $snapshot['routing_mask'] ?? null,
            'qr_payload' => $row['qr_payload'],
            'qr_type' => $snapshot['qr_type'] ?? 'pay_link',
            'qr_kind' => $row['qr_kind'],
            'pay_url' => $payUrl,
            'pay_token' => $token,
            'provider_payment_id' => $row['provider_payment_id'],
        ];
    }

    private function expireStaleForRemittance(int $remittanceId): void
    {
        $this->db->createCommand(
            "UPDATE {{%deposit_requests}}
             SET status='expired', updated_at=UTC_TIMESTAMP()
             WHERE remittance_id=:id AND status='pending'
               AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()",
            [':id' => $remittanceId]
        )->execute();
    }

    private function expireById(int $id): void
    {
        $this->db->createCommand(
            "UPDATE {{%deposit_requests}} SET status='expired', updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='pending'",
            [':id' => $id]
        )->execute();
    }

    /**
     * @param array{scope:string,key:string,hash:string}|null $idempotency
     * @param array<string,mixed> $response
     */
    private function storeIdempotency(?array $idempotency, array $response, ?string $expiresAt = null): void
    {
        if ($idempotency === null) {
            return;
        }
        $this->db->createCommand()->insert('{{%idempotency_keys}}', [
            'scope' => $idempotency['scope'],
            'idempotency_key' => $idempotency['key'],
            'request_hash' => $idempotency['hash'],
            'response_status' => 201,
            'response_body' => new JsonExpression($response),
            'resource_type' => 'deposit_request',
            'resource_id' => $response['public_id'],
            'expires_at' => $expiresAt,
        ])->execute();
    }

    private function isExpired(mixed $expiresAt, string $status): bool
    {
        if ($status === 'expired') {
            return true;
        }
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        return strtotime($expiresAt . ' UTC') <= time();
    }

    private function expiresAt(): string
    {
        $ttl = (int) (getenv('SEDERG_PAYMENT_TTL_MINUTES') ?: self::DEFAULT_TTL_MINUTES);
        $ttl = max(self::MIN_TTL_MINUTES, min(self::MAX_TTL_MINUTES, $ttl));

        return gmdate('Y-m-d H:i:s', time() + ($ttl * 60));
    }

    private function publicBaseUrl(): string
    {
        foreach (['SEDERG_PUBLIC_BASE_URL', 'SEDERG_PORTAL_URL'] as $name) {
            $value = trim((string) getenv($name));
            if ($value !== '') {
                return rtrim($value, '/');
            }
        }

        return 'http://localhost:5173';
    }

    private function atom(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    /** @return array<string,mixed> */
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

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
