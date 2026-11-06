<?php

declare(strict_types=1);

namespace app\commands;

use app\Infrastructure\Payment\PaymentIntentService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Payment intent maintenance.
 *
 * `payments/expire` is meant to run every minute (cron / scheduler). It only
 * retires intents whose deadline passed; it never touches a confirmed payment.
 */
final class PaymentsController extends Controller
{
    public function actionExpire(): int
    {
        $expired = (new PaymentIntentService(Yii::$app->db))->expireStale();
        $this->stdout("Expired {$expired} payment intent(s)." . PHP_EOL);

        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        $db = Yii::$app->db;
        $rows = $db->createCommand(
            "SELECT status, COUNT(*) AS total FROM {{%deposit_requests}} GROUP BY status ORDER BY status"
        )->queryAll();
        $pending = (int) $db->createCommand(
            "SELECT COUNT(*) FROM {{%deposit_requests}}
             WHERE status='pending' AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()"
        )->queryScalar();

        $this->stdout('Payment intents' . PHP_EOL);
        if ($rows === []) {
            $this->stdout('  (none)' . PHP_EOL);
        }
        foreach ($rows as $row) {
            $this->stdout('  ' . str_pad((string) $row['status'], 12) . $row['total'] . PHP_EOL);
        }
        $this->stdout('  pending past deadline: ' . $pending . PHP_EOL);

        return ExitCode::OK;
    }
}
