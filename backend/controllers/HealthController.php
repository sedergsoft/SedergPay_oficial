<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;

final class HealthController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex(): array
    {
        Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        Yii::$app->response->statusCode = 200;
        return [
            'status' => 'ok',
            'service' => 'sederg-pay-api',
            'environment' => getenv('SEDERG_AUTH_MODE') === 'development' ? 'sandbox' : 'production',
        ];
    }

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }
}
