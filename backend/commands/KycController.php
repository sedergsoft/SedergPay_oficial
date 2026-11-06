<?php

declare(strict_types=1);

namespace app\commands;

use app\Infrastructure\Kyc\Provider\BallerineConfig;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Operator-facing readiness check for the Ballerine KYC activation.
 *
 * It only reads configuration and persisted event metadata. It never prints
 * the API key, webhook secret or full provider URLs.
 */
final class KycController extends Controller
{
    public function actionProviderStatus(): int
    {
        try {
            $config = BallerineConfig::fromEnvironment();
        } catch (\Throwable $exception) {
            $this->stderr('Ballerine configuration is invalid: ' . $exception->getMessage() . PHP_EOL);

            return ExitCode::CONFIG;
        }

        $this->stdout('Ballerine KYC activation status' . PHP_EOL);
        $this->stdout('  enabled:             ' . ($config->enabled ? 'yes' : 'no') . PHP_EOL);
        $this->stdout('  session configured:  ' . ($config->isSessionConfigured() ? 'yes' : 'no') . PHP_EOL);
        $this->stdout('  webhook configured:  ' . ($config->isWebhookConfigured() ? 'yes' : 'no') . PHP_EOL);
        $this->stdout('  webhook path:        /api/v1/kyc/webhooks/ballerine' . PHP_EOL);

        $eventsAvailable = false;
        $lastEventAt = null;
        $events = [];
        $pendingCases = 0;
        try {
            $eventsAvailable = Yii::$app->db->createCommand(
                'SELECT COUNT(*) FROM {{%kyc_provider_events}}'
            )->queryScalar() !== false;
            $lastEventAt = Yii::$app->db->createCommand(
                'SELECT MAX(received_at) FROM {{%kyc_provider_events}} WHERE provider_name = :provider',
                [':provider' => 'ballerine']
            )->queryScalar();
            $events = Yii::$app->db->createCommand(
                'SELECT outcome, COUNT(*) AS event_count
                 FROM {{%kyc_provider_events}}
                 WHERE provider_name = :provider AND received_at >= :cutoff
                 GROUP BY outcome ORDER BY outcome',
                [
                    ':provider' => 'ballerine',
                    ':cutoff' => gmdate('Y-m-d H:i:s', time() - 86400),
                ]
            )->queryAll();
            $pendingCases = (int) Yii::$app->db->createCommand(
                'SELECT COUNT(*) FROM {{%kyc_cases}}
                 WHERE provider_name = :provider AND status IN (:pending, :manual)',
                [
                    ':provider' => 'ballerine',
                    ':pending' => 'pending_verification',
                    ':manual' => 'manual_review',
                ]
            )->queryScalar();
        } catch (\Throwable $exception) {
            $this->stderr('Database check failed (is the Ballerine migration applied?): '
                . $exception->getMessage() . PHP_EOL);
        }

        $this->stdout('  events table:        ' . ($eventsAvailable ? 'available' : 'unavailable') . PHP_EOL);
        $this->stdout('  last provider event: ' . ($lastEventAt === false || $lastEventAt === null ? 'none' : $lastEventAt) . PHP_EOL);
        $this->stdout('  pending provider review cases: ' . $pendingCases . PHP_EOL);
        if ($events !== []) {
            $this->stdout('  events in last 24h:' . PHP_EOL);
            foreach ($events as $event) {
                $this->stdout('    - ' . $event['outcome'] . ': ' . $event['event_count'] . PHP_EOL);
            }
        }

        if (!$config->isSessionConfigured() || !$config->isWebhookConfigured() || !$eventsAvailable) {
            $this->stderr(PHP_EOL . 'Ballerine KYC is NOT ready for activation.' . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(PHP_EOL . 'Ballerine KYC is configured and ready for live traffic.' . PHP_EOL);

        return ExitCode::OK;
    }
}
