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

$config = require dirname(__DIR__) . '/config/web.php';
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
