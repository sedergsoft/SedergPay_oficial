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
        $body = ['status' => 'ok', 'service' => 'sederg-pay-api'];
        // Solo exponer el entorno en modo desarrollo (D-04: no filtrar en producción).
        if (getenv('SEDERG_AUTH_MODE') === 'development') {
            $body['environment'] = 'sandbox';
        }
        return $body;
    }

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }
}
