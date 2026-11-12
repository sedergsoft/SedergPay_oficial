<?php

declare(strict_types=1);

namespace app\Infrastructure\Security;

use Yii;
use yii\web\TooManyRequestsHttpException;

final class RateLimiter
{
    public static function enforce(string $action, int $defaultLimit, int $defaultWindowSeconds): void
    {
        $enabled = getenv('SEDERG_THROTTLE_ENABLED');
        if ($enabled === '0' || $enabled === 'false') {
            return;
        }
        $limit = self::envInt('SEDERG_THROTTLE_' . strtoupper(str_replace(['-', ':', '.'], '_', $action)) . '_LIMIT', $defaultLimit);
        $windowSeconds = self::envInt('SEDERG_THROTTLE_' . strtoupper(str_replace(['-', ':', '.'], '_', $action)) . '_WINDOW', $defaultWindowSeconds);
        $ip = (string) (Yii::$app->request->userIP ?? 'unknown');
        $key = 'throttle:' . $action . ':' . hash('sha256', $ip);
        $cache = Yii::$app->has('cache') ? Yii::$app->cache : null;
        if ($cache === null) {
            return;
        }
        $entry = $cache->get($key);
        $count = 1;
        if (is_array($entry) && isset($entry['count'], $entry['reset']) && (int) $entry['reset'] > time()) {
            $count = (int) $entry['count'] + 1;
            $windowSeconds = max(1, (int) $entry['reset'] - time());
        }
        $cache->set($key, ['count' => $count, 'reset' => time() + $windowSeconds], $windowSeconds);
        if ($count > $limit) {
            Yii::warning(['throttle_exceeded' => $action, 'ip' => $ip], 'security');
            $retryAfter = min(300, $windowSeconds);
            if (Yii::$app->has('response')) {
                Yii::$app->response->headers->set('Retry-After', (string) $retryAfter);
            }
            throw new TooManyRequestsHttpException('Too many attempts. Please try again later.');
        }
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        $int = (int) $value;
        return $int > 0 ? $int : $default;
    }
}