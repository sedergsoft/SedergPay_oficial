<?php

declare(strict_types=1);

return [
    'id' => 'sederg-pay-web',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => [
            'cookieValidationKey' => getenv('SEDERG_COOKIE_KEY') ?: 'local-development-only',
            'parsers' => ['application/json' => 'yii\web\JsonParser'],
        ],
        'response' => ['format' => yii\web\Response::FORMAT_JSON],
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => getenv('SEDERG_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=sederg_pay',
            'username' => getenv('SEDERG_DB_USER') ?: 'root',
            'password' => getenv('SEDERG_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ],
        'security' => ['class' => yii\base\Security::class],
        'log' => [
            'class' => yii\log\Dispatcher::class,
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logFile' => '@runtime/logs/web.log',
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET health' => 'health/index',
                'POST api/v1/registrations' => 'registration/create',
                'POST api/v1/beneficiaries' => 'beneficiary/create',
                'GET api/v1/beneficiaries' => 'beneficiary/index',
                'GET api/v1/beneficiaries/<publicId:[0-9a-fA-F-]{36}>' => 'beneficiary/view',
                'POST api/v1/remittances' => 'remittance/create',
                'GET api/v1/remittances' => 'remittance/index',
                'GET api/v1/remittances/<publicId:[0-9a-fA-F-]{36}>' => 'remittance/view',
                'POST api/v1/remittances/<publicId:[0-9a-fA-F-]{36}>/deposit' => 'payment/create-deposit',
                'POST api/v1/deposits/<publicId:[0-9a-fA-F-]{36}>/simulate-confirmation' => 'payment/simulate-confirm',
                'POST api/v1/remittances/<publicId:[0-9a-fA-F-]{36}>/compliance-review' => 'compliance/review',
                'POST api/v1/remittances/<publicId:[0-9a-fA-F-]{36}>/payout' => 'payout/create',
                'POST api/v1/payouts/<publicId:[0-9a-fA-F-]{36}>/simulate-completion' => 'payout/simulate-complete',
                'POST api/v1/remittances/<publicId:[0-9a-fA-F-]{36}>/reconcile' => 'reconciliation/complete',
                'POST api/v1/quotes' => 'quote/create',
                'GET api/v1/quotes/<publicId:[0-9a-fA-F-]{36}>' => 'quote/view',
            ],
        ],
    ],
    'controllerNamespace' => 'app\\controllers',
];
