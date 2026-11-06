<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Kyc\KycWebhookService;
use app\Infrastructure\Kyc\Provider\BallerineConfig;
use app\Infrastructure\Kyc\Provider\BallerineKycProvider;
use app\Infrastructure\Kyc\Provider\StreamBallerineTransport;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;
use yii\web\ServiceUnavailableHttpException;

/**
 * Isolated Ballerine webhook receiver. It must never use the customer's bearer
 * token or development identity header: authenticity comes from the provider
 * secret and the persisted event ID.
 */
final class BallerineWebhookController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionReceive(): array
    {
        try {
            $config = BallerineConfig::fromEnvironment();
            $config->assertWebhookConfigured();
        } catch (\InvalidArgumentException | \yii\base\InvalidConfigException $exception) {
            throw new ServiceUnavailableHttpException(
                'Ballerine webhook processing is not available on this deployment',
                0,
                $exception
            );
        }

        $headers = [];
        foreach (Yii::$app->request->headers->toArray() as $name => $values) {
            $headers[strtolower((string) $name)] = is_array($values)
                ? (string) ($values[0] ?? '')
                : (string) $values;
        }

        $provider = new BallerineKycProvider($config, new StreamBallerineTransport());
        try {
            $event = $provider->verifyAndNormalizeWebhook(
                Yii::$app->request->getRawBody(),
                $headers
            );
        } catch (\UnexpectedValueException $exception) {
            Yii::warning('Rejected Ballerine webhook: ' . $exception->getMessage(), __METHOD__);
            throw new ForbiddenHttpException('Invalid webhook signature, timestamp or payload');
        }

        try {
            $result = (new KycWebhookService(Yii::$app->db))->record($event);
        } catch (\Throwable $exception) {
            Yii::error($exception, __METHOD__);
            throw new ServerErrorHttpException('The webhook could not be processed', 0, $exception);
        }

        return [
            'received' => true,
            'duplicate' => $result['duplicate'],
        ];
    }
}
