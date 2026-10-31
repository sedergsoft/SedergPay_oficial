<?php

declare(strict_types=1);

if (getenv('SEDERG_DEBUG') === '1') {
    defined('YII_DEBUG') or define('YII_DEBUG', true);
    defined('YII_ENV') or define('YII_ENV', 'dev');
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) getenv('SEDERG_CORS_ORIGINS'))));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: false');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-User-Public-Id');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = require dirname(__DIR__) . '/config/web.php';
$config['components']['response']['on beforeSend'] = static function (yii\base\Event $event) use ($origin, $allowedOrigins): void {
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        $event->sender->headers->set('Access-Control-Allow-Origin', $origin);
        $event->sender->headers->set('Vary', 'Origin');
        $event->sender->headers->set('Access-Control-Allow-Credentials', 'false');
    }
};
try {
    (new yii\web\Application($config))->run();
} catch (Throwable $exception) {
    if (getenv('SEDERG_DEBUG') === '1') {
        file_put_contents(
            dirname(__DIR__) . '/runtime/fatal.log',
            $exception::class . ': ' . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString()
        );
    }
    throw $exception;
}
