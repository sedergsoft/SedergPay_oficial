<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Payment\SimulatedPaymentRepository;
use app\Infrastructure\Auth\UserAuthenticator;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnprocessableEntityHttpException;

final class PaymentController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionCreateDeposit(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        $request = Yii::$app->request;
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $key)) {
            throw new BadRequestHttpException('A valid Idempotency-Key is required');
        }
        $repository = new SimulatedPaymentRepository(Yii::$app->db);
        $remittance = $repository->findRemittance($publicId, $userId);
        if ($remittance === null) {
            throw new NotFoundHttpException('Remittance not found');
        }
        $scope = "deposit:create:user:{$userId}";
        $hash = hash('sha256', $publicId);
        $existing = $repository->findIdempotency($scope, $key);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $hash)) {
                throw new ConflictHttpException('Idempotency-Key was already used with another request');
            }
            return json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ($remittance['status'] !== 'awaiting_payment') {
            throw new UnprocessableEntityHttpException('Remittance is not awaiting payment');
        }

        $depositId = $this->generateUuid();
        $reference = 'SIM-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        $response = [
            'public_id' => $depositId,
            'provider' => 'simulator',
            'status' => 'pending',
            'expected_amount' => $remittance['total_debit_amount'],
            'currency_code' => $remittance['send_currency'],
            'payment_reference' => $reference,
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format(DATE_ATOM),
        ];
        $transaction = $repository->beginTransaction();
        try {
            $repository->createDeposit($remittance, $depositId, $reference, $transaction);
            $repository->saveIdempotency($scope, $key, $hash, $response, $transaction);
            $transaction->commit();
        } catch (\DomainException $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }
        Yii::$app->response->statusCode = 201;
        return $response;
    }

    /**
     * Authenticated polling endpoint for the current state of a payment intent.
     * The remittance detail already exposes a snapshot; this endpoint keeps the
     * panel live while the payer completes the payment on another device.
     */
    public function actionView(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        $row = Yii::$app->db->createCommand(
            'SELECT d.public_id, d.status, d.expires_at, d.confirmed_at, d.paid_amount, d.paid_at,
                    d.qr_payload, d.qr_kind, d.pay_token, d.expected_amount, d.currency_code,
                    d.payment_reference, d.account_snapshot
             FROM {{%deposit_requests}} d
             INNER JOIN {{%remittances}} r ON r.id=d.remittance_id
             WHERE d.public_id=:public_id AND r.sender_user_id=:user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();
        if ($row === false) {
            throw new NotFoundHttpException('Deposit request not found');
        }
        $snapshot = json_decode((string) $row['account_snapshot'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $expired = $row['status'] === 'pending'
            && $row['expires_at'] !== null
            && strtotime((string) $row['expires_at'] . ' UTC') <= time();
        $expiresAt = $row['expires_at'] === null
            ? null
            : (new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM);

        return [
            'public_id' => $row['public_id'],
            'status' => $expired ? 'expired' : $row['status'],
            'expected_amount' => $row['expected_amount'],
            'currency_code' => $row['currency_code'],
            'payment_reference' => $row['payment_reference'],
            'expires_at' => $expiresAt,
            'confirmed_at' => $row['confirmed_at'],
            'paid_amount' => $row['paid_amount'],
            'paid_at' => $row['paid_at'],
            'qr_payload' => $row['qr_payload'],
            'qr_kind' => $row['qr_kind'],
            'pay_url' => $snapshot['pay_url'] ?? null,
            'pay_token' => $row['pay_token'],
            'bank_name' => $snapshot['bank_name'] ?? null,
            'account_holder_name' => $snapshot['account_holder_name'] ?? 'SEDERG_PAY',
            'account_mask' => $snapshot['account_mask'] ?? null,
            'routing_mask' => $snapshot['routing_mask'] ?? null,
            'method_name' => $snapshot['method_name'] ?? null,
        ];
    }

    public function actionSimulateConfirm(string $publicId): array
    {
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            throw new ForbiddenHttpException('Simulated payment confirmation is available only in development/sandbox.');
        }
        $userId = $this->authenticatedUserId();
        $repository = new SimulatedPaymentRepository(Yii::$app->db);
        $deposit = $repository->findDeposit($publicId, $userId);
        if ($deposit === null) {
            throw new NotFoundHttpException('Deposit request not found');
        }
        $remittance = $repository->findRemittanceByDeposit($publicId, $userId);
        if ($remittance === null) {
            throw new NotFoundHttpException('Remittance not found');
        }
        $transaction = $repository->beginTransaction();
        try {
            $repository->confirmDeposit($remittance, $publicId, 'sim-tx-' . bin2hex(random_bytes(6)), $transaction);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        return ['public_id' => $publicId, 'status' => 'confirmed', 'remittance_status' => 'compliance_review'];
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
