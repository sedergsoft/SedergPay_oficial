<?php

declare(strict_types=1);

namespace app\commands;

use app\Infrastructure\Remittance\RemittanceExpiryService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class RemittancesController extends Controller
{
    /**
     * Cancela las remesas que llevan más tiempo del configurado esperando el
     * depósito. Ejecutar cada pocos minutos vía cron/scheduler.
     */
    public function actionExpire(): int
    {
        $service = new RemittanceExpiryService(Yii::$app->db);
        try {
            $cancelled = $service->expireOverdue();
        } catch (\Throwable $exception) {
            $this->stderr("Remittance expiry failed: {$exception->getMessage()}\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "Remesas canceladas por falta de depósito (%d h): %d\n",
            $service->windowHours(),
            $cancelled
        ));

        return ExitCode::OK;
    }
}
