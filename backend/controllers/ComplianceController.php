<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Compliance\ComplianceRepository;
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

final class ComplianceController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionReview(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        $request = Yii::$app->request;
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $key)) {
            throw new BadRequestHttpException('A valid Idempotency-Key is required');
        }
        $payload = $request->bodyParams;
        if (!is_array($payload)
            || !isset($payload['decision'], $payload['reason'])
            || !is_string($payload['decision'])
            || !is_string($payload['reason'])) {
            throw new UnprocessableEntityHttpException('Decision and reason are required');
        }
        if (!in_array($payload['decision'], ['approved', 'rejected'], true)) {
            throw new UnprocessableEntityHttpException('Decision must be approved or rejected');
        }
        $reason = trim($payload['reason']);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new UnprocessableEntityHttpException('A reason between 1 and 500 characters is required');
        }

        $repository = new ComplianceRepository(Yii::$app->db);
        $remittance = $repository->findRemittance($publicId, $userId);
        if ($remittance === null) {
            throw new NotFoundHttpException('Remittance not found');
        }
        $scope = "compliance:review:user:{$userId}";
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = $repository->findIdempotency($scope, $key);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $requestHash)) {
                throw new ConflictHttpException('Idempotency-Key was already used with another request');
            }
            $response = json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
            return is_string($response)
                ? json_decode($response, true, 512, JSON_THROW_ON_ERROR)
                : $response;
        }
        if ($remittance['status'] !== 'compliance_review') {
            throw new UnprocessableEntityHttpException('Remittance is not awaiting compliance review');
        }

        $response = [
            'remittance_public_id' => $publicId,
            'decision' => $payload['decision'],
            'status' => $payload['decision'] === 'approved' ? 'approved' : 'rejected',
            'compliance_status' => $payload['decision'],
        ];
        $transaction = $repository->beginTransaction();
        try {
            $repository->saveDecision($remittance, $userId, $payload['decision'], $reason, $transaction);
            $repository->saveIdempotency($scope, $key, $requestHash, $response, $transaction);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $response;
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }
}
