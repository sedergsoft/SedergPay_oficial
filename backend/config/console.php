<?php

declare(strict_types=1);

return [
    'id' => 'sederg-pay-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => getenv('SEDERG_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=sederg_pay',
            'username' => getenv('SEDERG_DB_USER') ?: 'root',
            'password' => getenv('SEDERG_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ],
        'log' => [
            'class' => yii\log\Dispatcher::class,
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logFile' => '@runtime/logs/app.log',
                ],
            ],
        ],
    ],
    'controllerNamespace' => 'app\\commands',
];
