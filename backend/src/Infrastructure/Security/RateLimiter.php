<?php

declare(strict_types=1);

namespace app\Infrastructure\Security;

use Yii;
use yii\web\HttpException;

/**
 * Rate limiter basado en el sistema de archivos (sin dependencias externas).
 *
 * Almacena marcas de tiempo en `runtime/rate_limits/{endpoint}:{ipHash}` para
 * limitar intentos por dirección IP y endpoint. Ventana de 15 minutos, máximo
 * 5 intentos por ventana por defecto.
 */
final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900; // 15 minutos

    private string $storagePath;

    public function __construct(string $storagePath = '@runtime/rate_limits')
    {
        $this->storagePath = Yii::getAlias($storagePath);
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0770, true);
        }
    }

    /**
     * Registra un intento y lanza TooManyRequestsHttpException si se supera el límite.
     *
     * @param string $endpoint identificador del endpoint (ej. 'auth:login')
     * @param string $ip dirección IP del cliente
     * @param int $maxAttempts máximo de intentos permitidos en la ventana
     * @param int $windowSeconds duración de la ventana en segundos
     * @throws TooManyRequestsHttpException
     */
    public function check(string $endpoint, string $ip, int $maxAttempts = self::MAX_ATTEMPTS, int $windowSeconds = self::WINDOW_SECONDS): void
    {
        $key = $this->sanitizeKey($endpoint . ':' . $ip);
        $file = $this->storagePath . DIRECTORY_SEPARATOR . $key . '.json';
        $now = time();
        $windowStart = $now - $windowSeconds;

        $attempts = $this->loadAttempts($file, $windowStart);

        if (count($attempts) >= $maxAttempts) {
            $retryAfter = $windowSeconds - ($now - min($attempts));
            $response = Yii::$app->getResponse();
            $response->setStatusCode(429, 'Demasiados intentos. Inténtelo de nuevo más tarde.');
            $response->getHeaders()->set('Retry-After', (string) max($retryAfter, 1));
            $response->getHeaders()->set('Content-Type', 'application/json');
            $response->content = json_encode([
                'name' => 'Too Many Requests',
                'message' => 'Demasiados intentos. Inténtelo de nuevo más tarde.',
                'code' => 0,
                'status' => 429,
            ]);
            $response->send();
            exit;
        }

        $attempts[] = $now;
        $this->saveAttempts($file, $attempts);
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9._:-]/', '_', $key);
    }

    /**
     * @return int[]
     */
    private function loadAttempts(string $file, int $windowStart): array
    {
        if (!is_file($file)) {
            return [];
        }
        $content = (string) @file_get_contents($file);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }
        $attempts = [];
        foreach ($data as $ts) {
            if (is_int($ts) && $ts >= $windowStart) {
                $attempts[] = $ts;
            }
        }
        return $attempts;
    }

    private function saveAttempts(string $file, array $attempts): void
    {
        $tmp = $file . '.tmp';
        @file_put_contents($tmp, json_encode($attempts));
        @rename($tmp, $file);
        @chmod($file, 0660);
    }
}
