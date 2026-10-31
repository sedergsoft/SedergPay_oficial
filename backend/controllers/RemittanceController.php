<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Remittance\RemittanceRepository;
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

final class RemittanceController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
        ];
    }

    public function actionCreate(): array
    {
        $request = Yii::$app->request;
        $userId = $this->authenticatedUserId();
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $idempotencyKey)) {
            throw new BadRequestHttpException('A valid Idempotency-Key is required');
        }
        $payload = $request->bodyParams;
        if (!is_array($payload)
            || !isset($payload['quote_public_id'], $payload['beneficiary_public_id'])
            || !is_string($payload['quote_public_id'])
            || !is_string($payload['beneficiary_public_id'])) {
            throw new UnprocessableEntityHttpException('Quote and beneficiary are required');
        }
        if (!$this->isUuid($payload['quote_public_id']) || !$this->isUuid($payload['beneficiary_public_id'])) {
            throw new UnprocessableEntityHttpException('Invalid quote or beneficiary identifier');
        }

        $repository = new RemittanceRepository(Yii::$app->db);
        $scope = "remittance:create:user:{$userId}";
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = $repository->findIdempotency($scope, $idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $requestHash)) {
                throw new ConflictHttpException('Idempotency-Key was already used with another request');
            }
            $response = json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
            return is_string($response)
                ? json_decode($response, true, 512, JSON_THROW_ON_ERROR)
                : $response;
        }

        $quote = $repository->findQuoteForUser($payload['quote_public_id'], $userId);
        if ($quote === null) {
            throw new NotFoundHttpException('Quote not found');
        }
        if ($quote['status'] !== 'valid' || new \DateTimeImmutable($quote['expires_at']) <= new \DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('Quote is expired or unavailable');
        }
        $beneficiary = $repository->findBeneficiaryForUser($payload['beneficiary_public_id'], $userId);
        if ($beneficiary === null) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        if ($beneficiary['country_id'] !== $quote['destination_country_id']
            || $beneficiary['payout_method_code'] !== $quote['payout_method_code']) {
            throw new UnprocessableEntityHttpException('Beneficiary does not match quote destination');
        }

        $publicId = $this->generateUuid();
        $reference = 'REM-' . gmdate('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $response = [
            'public_id' => $publicId,
            'reference' => $reference,
            'direction' => (int) $quote['source_country_id'] === 1 ? 'ru_to_cu' : 'cu_to_ru',
            'send_amount' => $quote['send_amount'],
            'send_currency' => $quote['send_currency'],
            'receive_amount' => $quote['receive_amount'],
            'receive_currency' => $quote['receive_currency'],
            'status' => 'awaiting_payment',
            'compliance_status' => 'pending',
        ];
        $transaction = $repository->beginTransaction();
        try {
            $remittanceId = $repository->save($userId, $quote, $beneficiary, $publicId, $reference, $transaction);
            $repository->saveIdempotency($scope, $idempotencyKey, $requestHash, $response, $remittanceId, $transaction);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        Yii::$app->response->statusCode = 201;
        return $response;
    }

    public function actionIndex(): array
    {
        return (new RemittanceRepository(Yii::$app->db))->listForUser($this->authenticatedUserId());
    }

    public function actionView(string $publicId): array
    {
        if (!$this->isUuid($publicId)) {
            throw new NotFoundHttpException('Remittance not found');
        }
        $remittance = (new RemittanceRepository(Yii::$app->db))
            ->findForUser($publicId, $this->authenticatedUserId());
        if ($remittance === null) {
            throw new NotFoundHttpException('Remittance not found');
        }
        return $remittance;
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f-]{36}$/i', $value);
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
