<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Pricing\QuoteFactory;
use app\Infrastructure\Pricing\QuoteRepository;
use app\Infrastructure\Pricing\ExchangeRateService;
use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Configuration\PlatformSettings;
use DomainException;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnprocessableEntityHttpException;

final class QuoteController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
        ];
    }

    public function actionCreate(): array
    {
        if (!PlatformSettings::getBool(Yii::$app->db, 'operations.transfers_enabled')) {
            throw new ForbiddenHttpException('Transfers are temporarily disabled by the administrator');
        }
        $request = Yii::$app->request;
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $idempotencyKey)) {
            throw new BadRequestHttpException('A valid Idempotency-Key is required');
        }

        $payload = $request->bodyParams;
        if (!is_array($payload)) {
            throw new BadRequestHttpException('JSON object body is required');
        }

        $repository = new QuoteRepository(Yii::$app->db);
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();

        $scope = "quote:create:user:{$userId}";
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = $repository->findIdempotency($scope, $idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $requestHash)) {
                throw new ConflictHttpException('Idempotency-Key was already used with another request');
            }

            $response = json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
            if (is_string($response)) {
                $response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            }

            return $response;
        }

        foreach (['source_country_code', 'destination_country_code', 'send_amount', 'send_currency', 'receive_currency', 'payout_method_code'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new UnprocessableEntityHttpException("Missing field: {$field}");
            }
        }

        if (!in_array($payload['source_country_code'], ['RU', 'CU'], true)
            || !in_array($payload['destination_country_code'], ['RU', 'CU'], true)
            || $payload['source_country_code'] === $payload['destination_country_code']) {
            throw new UnprocessableEntityHttpException('Only RU-CU and CU-RU corridors are supported');
        }

        if (!$repository->paymentMethodExists($payload['payout_method_code'])) {
            throw new UnprocessableEntityHttpException('Unsupported payout method');
        }

        try {
            $exchangeRate = (new ExchangeRateService(Yii::$app->db))->resolve(
                $payload['send_currency'],
                $payload['receive_currency']
            );
            $payload['exchange_rate'] = $exchangeRate['rate'];
            $calculated = QuoteFactory::create(Yii::$app->db)->createQuote($payload);
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
        } catch (\RuntimeException $exception) {
            throw new HttpException(503, 'La tasa RUB/CUP no está disponible en este momento.', 0, $exception);
        }

        $publicId = $this->generateUuid();
        $response = ['public_id' => $publicId, 'exchange_rate_source' => $exchangeRate['source_label'],
            'exchange_rate_effective_date' => $exchangeRate['effective_date']] + $calculated;
        $transaction = $repository->beginTransaction();
        try {
            $quoteId = $repository->saveQuote($userId, $payload, $calculated, $publicId, $transaction);
            $repository->saveIdempotency($scope, $idempotencyKey, $requestHash, 201, $response, (string) $quoteId, $transaction);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        Yii::$app->response->statusCode = 201;
        return $response;
    }

    public function actionView(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new NotFoundHttpException('Quote not found');
        }

        $quote = (new QuoteRepository(Yii::$app->db))->findQuoteForUser($publicId, $userId);
        if ($quote === null) {
            throw new NotFoundHttpException('Quote not found');
        }

        $expiresAt = new \DateTimeImmutable($quote['expires_at']);
        $status = $expiresAt <= new \DateTimeImmutable()
            ? 'expired'
            : $quote['status'];

        return [
            'public_id' => $quote['public_id'],
            'send_amount' => $quote['send_amount'],
            'send_currency' => $quote['send_currency'],
            'receive_amount' => $quote['receive_amount'],
            'receive_currency' => $quote['receive_currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'fee_amount' => $quote['fee_amount'],
            'fee_currency' => $quote['fee_currency'],
            'total_debit_amount' => $quote['total_debit_amount'],
            'payout_method_code' => $quote['payout_method_code'],
            'status' => $status,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ];
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
