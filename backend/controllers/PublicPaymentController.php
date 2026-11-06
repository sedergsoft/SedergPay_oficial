<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Payment\PaymentIntentService;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnprocessableEntityHttpException;

/**
 * Payer-facing, unauthenticated endpoints reached from the QR code.
 *
 * The capability token in the URL is the only credential. It is random,
 * single-purpose and never exposed without the payer physically holding the
 * link, so no OIDC identity is required to view the amount and instructions.
 */
final class PublicPaymentController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionView(string $token): array
    {
        $intent = (new PaymentIntentService(Yii::$app->db))->findPublic($token);
        if ($intent === null) {
            throw new NotFoundHttpException('Payment link not found');
        }
        $intent['can_confirm'] = $intent['status'] === 'pending'
            && PaymentIntentService::sandboxConfirmationEnabled();

        return $intent;
    }

    public function actionConfirm(string $token): array
    {
        if (!PaymentIntentService::sandboxConfirmationEnabled()) {
            throw new ForbiddenHttpException('Sandbox payment confirmation is disabled in this environment.');
        }
        try {
            return (new PaymentIntentService(Yii::$app->db))->confirmByToken($token);
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'Payment link not found') {
                throw new NotFoundHttpException($exception->getMessage(), 0, $exception);
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
        }
    }
}
