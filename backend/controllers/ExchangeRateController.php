<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Pricing\ExchangeRateService;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\Response;

final class ExchangeRateController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionCurrent(): array
    {
        try {
            return (new ExchangeRateService(Yii::$app->db))->resolve('RUB', 'CUP');
        } catch (\Throwable $exception) {
            throw new HttpException(
                503,
                'La tasa oficial RUB/CUP no está disponible en este momento.',
                0,
                $exception
            );
        }
    }
}
