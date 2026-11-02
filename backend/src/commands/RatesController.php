<?php

declare(strict_types=1);

namespace app\commands;

use app\Infrastructure\Pricing\ExchangeRateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class RatesController extends Controller
{
    /** Intended to be invoked by system cron every 12 hours. */
    public function actionUpdate(): int
    {
        try {
            $rate = (new ExchangeRateService(Yii::$app->db))->refreshOfficial();
            $this->stdout(sprintf(
                "Official RUB/CUP rate refreshed: %s (%s)\n",
                $rate['rate'],
                $rate['effective_date']
            ));

            return ExitCode::OK;
        } catch (\Throwable $exception) {
            $this->stderr("Exchange-rate refresh failed: {$exception->getMessage()}\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
