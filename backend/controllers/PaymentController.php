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
use yii\web\TooManyRequestsHttpException;
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

    private function throttle(string $action, int $limit, int $windowSeconds): void
    {
        \app\Infrastructure\Security\RateLimiter::enforce($action, $limit, $windowSeconds);
    }

    public function actionCreateDeposit(string $publicId): array
    {
        $this->throttle('sim-deposit-create', 30, 600);
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

    public function actionSimulateConfirm(string $publicId): array
    {
        $this->throttle('sim-deposit-confirm', 30, 600);
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
