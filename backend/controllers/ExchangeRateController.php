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
        $request = Yii::$app->request;
        $base = strtoupper(trim($request->get('base', 'RUB')));
        $quote = strtoupper(trim($request->get('quote', 'CUP')));
        if (!in_array($base, ['RUB', 'CUP'], true) || !in_array($quote, ['RUB', 'CUP'], true) || $base === $quote) {
            throw new HttpException(400, 'Par de monedas no soportado. Use RUB/CUP o CUP/RUB.');
        }
        try {
            return (new ExchangeRateService(Yii::$app->db))->resolve($base, $quote);
        } catch (\Throwable $exception) {
            throw new HttpException(
                503,
                "La tasa oficial {$base}/{$quote} no está disponible en este momento.",
                0,
                $exception
            );
        }
    }

    public function actionHistory(): array
    {
        $request = Yii::$app->request;
        $base = strtoupper(trim($request->get('base', 'RUB')));
        $quote = strtoupper(trim($request->get('quote', 'CUP')));
        $limit = (int) $request->get('limit', 30);
        if ($limit < 1) $limit = 1;
        if ($limit > 365) $limit = 365;
        if (!in_array($base, ['RUB', 'CUP'], true) || !in_array($quote, ['RUB', 'CUP'], true) || $base === $quote) {
            throw new HttpException(400, 'Par de monedas no soportado. Use RUB/CUP o CUP/RUB.');
        }
        try {
            $points = (new ExchangeRateService(Yii::$app->db))->getRateHistory($base, $quote, $limit);
            // chronological order for charting
            $points = array_reverse($points);
            return ['base_currency' => $base, 'quote_currency' => $quote, 'points' => $points];
        } catch (\Throwable $exception) {
            throw new HttpException(503, "No se pudo obtener el historial de tasas {$base}/{$quote}.", 0, $exception);
        }
    }
}
