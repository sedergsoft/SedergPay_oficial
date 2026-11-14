<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Reconciliation\ReconciliationRepository;
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

final class ReconciliationController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionComplete(string $publicId): array
    {
        $this->requireBackOfficeAccess();
        $userId = $this->authenticatedUserId();
        $key = trim((string) Yii::$app->request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $key)) {
            throw new BadRequestHttpException('A valid Idempotency-Key is required');
        }
        $payload = Yii::$app->request->bodyParams;
        $notes = is_array($payload) && isset($payload['notes']) && is_string($payload['notes'])
            ? trim($payload['notes']) : 'Sandbox reconciliation exact match';
        if ($notes === '' || mb_strlen($notes) > 500) {
            throw new UnprocessableEntityHttpException('Notes must contain 1 to 500 characters');
        }
        $repository = new ReconciliationRepository(Yii::$app->db);
        $remittance = $repository->findRemittance($publicId, $userId);
        if ($remittance === null) throw new NotFoundHttpException('Remittance not found');
        $scope = "reconciliation:complete:user:{$userId}";
        $hash = hash('sha256', json_encode(['public_id' => $publicId, 'notes' => $notes], JSON_THROW_ON_ERROR));
        $existing = $repository->findIdempotency($scope, $key);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $hash)) {
                throw new ConflictHttpException('Idempotency-Key was already used with another request');
            }
            return json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ($remittance['status'] !== 'reconciliation_pending') {
            throw new UnprocessableEntityHttpException('Remittance is not awaiting reconciliation');
        }
        $response = ['remittance_public_id' => $publicId, 'status' => 'completed', 'reconciliation_status' => 'resolved'];
        $transaction = $repository->beginTransaction();
        try {
            $repository->complete($remittance, $userId, $notes, $transaction);
            $repository->saveIdempotency($scope, $key, $hash, $response, $transaction);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) $transaction->rollBack();
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        try {
            (new \app\Infrastructure\Notification\NotificationService(Yii::$app->db))->notifyUser(
                $userId,
                'remittance.completed',
                'Remesa completada',
                'Tu remesa fue conciliada y completada exitosamente.',
                ['remittance_public_id' => $publicId, 'status' => 'completed']
            );
        } catch (\Throwable $exception) {
            Yii::warning('Reconciliation notification failed: ' . $exception->getMessage(), __METHOD__);
        }
        return $response;
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function requireBackOfficeAccess(): void
    {
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            (new UserAuthenticator(Yii::$app->db))->requireRole('admin');
        }
    }
}
