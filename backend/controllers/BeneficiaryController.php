<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Beneficiary\BeneficiaryRepository;
use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Configuration\PlatformSettings;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnprocessableEntityHttpException;

final class BeneficiaryController extends Controller
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

    private function throttle(string $action, int $limit, int $windowSeconds): void
    {
        \app\Infrastructure\Security\RateLimiter::enforce($action, $limit, $windowSeconds);
    }

    public function actionCreate(): array
    {
        $this->throttle('beneficiary-create', 30, 600);
        $request = Yii::$app->request;
        $userId = $this->authenticatedUserId();
        $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $idempotencyKey)) {
            throw new BadRequestHttpException('A valid Idempotency-Key is required');
        }

        $payload = $request->bodyParams;
        if (!is_array($payload)) {
            throw new BadRequestHttpException('JSON object body is required');
        }
        $this->validatePayload($payload);
        $repository = new BeneficiaryRepository(Yii::$app->db);
        $scope = "beneficiary:create:user:{$userId}";
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = $repository->findIdempotency($scope, $idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $requestHash)) {
                throw new ConflictHttpException('Idempotency-Key was already used with another request');
            }
            $response = json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
            return is_string($response)
                ? json_decode($response, true, 512, JSON_THROW_ON_ERROR)
                : $response;
        }
        $beneficiaryCount = (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM {{%beneficiaries}} WHERE sender_user_id = :user_id AND status <> :deleted',
            [':user_id' => $userId, ':deleted' => 'deleted']
        )->queryScalar();
        if ($beneficiaryCount >= (int) PlatformSettings::get(Yii::$app->db, 'operations.max_beneficiaries')) {
            throw new UnprocessableEntityHttpException('The maximum number of beneficiaries has been reached');
        }

        $countryId = $repository->countryId(strtoupper($payload['country_code']));
        if ($countryId === null) {
            throw new UnprocessableEntityHttpException('Unsupported beneficiary country');
        }
        if (!$repository->paymentMethodExists($payload['payout_method_code'])) {
            throw new UnprocessableEntityHttpException('Unsupported payout method');
        }

        $publicId = $this->generateUuid();
        $response = [
            'public_id' => $publicId,
            'country_code' => strtoupper($payload['country_code']),
            'first_name' => trim($payload['first_name']),
            'last_name' => trim($payload['last_name']),
            'phone_e164' => $payload['phone_e164'] ?? null,
            'email' => isset($payload['email']) ? strtolower($payload['email']) : null,
            'payout_method_code' => $payload['payout_method_code'],
            'payout_provider' => $payload['payout_provider'] ?? null,
            'payout_account_last4' => $payload['payout_account_last4'] ?? null,
            'payout_card_last4' => $payload['payout_card_last4'] ?? null,
            'status' => 'active',
        ];
        if (!empty($payload['payout_account_number'])) {
            $payload['payout_account_token'] = $this->encryptPaymentValue($payload['payout_account_number']);
            $payload['payout_account_last4'] = substr($payload['payout_account_number'], -4);
            $response['payout_account_last4'] = $payload['payout_account_last4'];
        }
        if (!empty($payload['payout_card_number'])) {
            $payload['payout_card_token'] = $this->encryptPaymentValue($payload['payout_card_number']);
            $payload['payout_card_last4'] = substr($payload['payout_card_number'], -4);
            $response['payout_card_last4'] = $payload['payout_card_last4'];
        }
        $transaction = $repository->beginTransaction();
        try {
            $repository->save($userId, $payload, $countryId, $publicId, $transaction);
            $repository->saveIdempotency($scope, $idempotencyKey, $requestHash, $response, $transaction);
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

    public function actionIndex(): array
    {
        return (new BeneficiaryRepository(Yii::$app->db))->listForUser($this->authenticatedUserId());
    }

    public function actionView(string $publicId): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        $beneficiary = (new BeneficiaryRepository(Yii::$app->db))
            ->findForUser($publicId, $this->authenticatedUserId());
        if ($beneficiary === null) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        return $beneficiary;
    }

    public function actionUpdate(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload)) {
            throw new BadRequestHttpException('JSON object body is required');
        }
        $repository = new BeneficiaryRepository(Yii::$app->db);
        $current = $repository->findForUser($publicId, $userId);
        if ($current === null) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        $this->validatePayload($payload, false);
        $countryId = $repository->countryId(strtoupper($payload['country_code']));
        if ($countryId === null || !$repository->paymentMethodExists($payload['payout_method_code'])) {
            throw new UnprocessableEntityHttpException('Unsupported country or payout method');
        }
        if (!empty($payload['payout_account_number'])) {
            $digits = preg_replace('/\D+/', '', (string) $payload['payout_account_number']);
            if (!preg_match('/^\d{6,34}$/', $digits)) throw new UnprocessableEntityHttpException('A valid bank account number is required');
            $payload['payout_account_token'] = $this->encryptPaymentValue($digits);
            $payload['payout_account_last4'] = substr($digits, -4);
        } elseif ($payload['payout_method_code'] === 'bank_account' && empty($current['payout_account_last4'])) {
            throw new UnprocessableEntityHttpException('A bank account number is required for the selected method');
        }
        if (!empty($payload['payout_card_number'])) {
            $digits = preg_replace('/\D+/', '', (string) $payload['payout_card_number']);
            if (!$this->isValidCardNumber($digits)) throw new UnprocessableEntityHttpException('A valid card number is required');
            $payload['payout_card_token'] = $this->encryptPaymentValue($digits);
            $payload['payout_card_last4'] = substr($digits, -4);
        } elseif ($payload['payout_method_code'] === 'card_transfer' && empty($current['payout_card_last4'])) {
            throw new UnprocessableEntityHttpException('A card number is required for the selected method');
        }
        $tx = Yii::$app->db->beginTransaction();
        try {
            $repository->updateForUser($publicId, $userId, $payload, $countryId);
            Yii::$app->db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'beneficiary.updated',
                'entity_type' => 'beneficiary',
                'entity_id' => $publicId,
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
                'after_data' => new \yii\db\JsonExpression([
                    'changed_fields' => array_values(array_intersect(array_keys($payload), [
                        'country_code', 'first_name', 'last_name', 'phone_e164', 'email',
                        'payout_method_code', 'payout_provider',
                    ])),
                ]),
            ])->execute();
            $tx->commit();
        } catch (\Throwable $exception) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            }
            throw $exception;
        }
        return $repository->findForUser($publicId, $userId) ?? [];
    }

    /**
     * Borrado logico de un beneficiario del usuario.
     */
    public function actionDelete(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        $repository = new BeneficiaryRepository(Yii::$app->db);
        $current = $repository->findForUser($publicId, $userId);
        if ($current === null) {
            throw new NotFoundHttpException('Beneficiary not found');
        }
        $tx = Yii::$app->db->beginTransaction();
        try {
            $repository->softDeleteForUser($publicId, $userId);
            Yii::$app->db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'beneficiary.deleted',
                'entity_type' => 'beneficiary',
                'entity_id' => $publicId,
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
                'after_data' => new \yii\db\JsonExpression([
                    'first_name' => $current['first_name'] ?? null,
                    'last_name' => $current['last_name'] ?? null,
                ]),
            ])->execute();
            $tx->commit();
        } catch (\Throwable $exception) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            }
            throw $exception;
        }

        return ['public_id' => $publicId, 'status' => 'deleted'];
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function validatePayload(array &$payload, bool $requirePaymentDetails = true): void
    {
        foreach (['payout_account_token', 'payout_account_last4', 'payout_card_token', 'payout_card_last4'] as $serverField) {
            if (array_key_exists($serverField, $payload)) {
                throw new UnprocessableEntityHttpException('Payment credential metadata is server-managed');
            }
        }
        foreach (['country_code', 'first_name', 'last_name', 'payout_method_code'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new UnprocessableEntityHttpException("Missing field: {$field}");
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', strtoupper($payload['country_code']))) {
            throw new UnprocessableEntityHttpException('A valid country is required');
        }
        foreach (['first_name' => 100, 'last_name' => 100, 'payout_method_code' => 30] as $field => $max) {
            if (mb_strlen(trim($payload[$field])) > $max) {
                throw new UnprocessableEntityHttpException("Field too long: {$field}");
            }
        }
        if (isset($payload['phone_e164']) && $payload['phone_e164'] !== ''
            && (!is_string($payload['phone_e164']) || !preg_match('/^\+[1-9]\d{7,14}$/', $payload['phone_e164']))) {
            throw new UnprocessableEntityHttpException('Phone must use E.164 format');
        }
        if (isset($payload['email']) && $payload['email'] !== ''
            && (!is_string($payload['email']) || filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false || strlen($payload['email']) > 190)) {
            throw new UnprocessableEntityHttpException('Invalid beneficiary email');
        }
        if (isset($payload['payout_provider']) && (!is_string($payload['payout_provider']) || mb_strlen($payload['payout_provider']) > 80)) {
            throw new UnprocessableEntityHttpException('Payout provider is too long');
        }
        if (isset($payload['payout_account_token'])
            && (!is_string($payload['payout_account_token']) || strlen($payload['payout_account_token']) > 255)) {
            throw new UnprocessableEntityHttpException('Invalid payout account token');
        }
        if (isset($payload['payout_account_last4'])
            && (!is_string($payload['payout_account_last4']) || !preg_match('/^\d{4}$/', $payload['payout_account_last4']))) {
            throw new UnprocessableEntityHttpException('Payout account last4 must contain four digits');
        }
        if (isset($payload['payout_account_number']) && $payload['payout_account_number'] !== ''
            && !preg_match('/^\d{6,34}$/', preg_replace('/\D+/', '', (string) $payload['payout_account_number']))) {
            throw new UnprocessableEntityHttpException('A valid bank account number is required');
        }
        if (isset($payload['payout_card_number']) && $payload['payout_card_number'] !== ''
            && !$this->isValidCardNumber((string) $payload['payout_card_number'])) {
            throw new UnprocessableEntityHttpException('A valid card number is required');
        }
        if ($payload['payout_method_code'] === 'bank_account'
            && (!isset($payload['payout_provider']) || trim((string) $payload['payout_provider']) === ''
                || ($requirePaymentDetails && empty($payload['payout_account_number'])))) {
            throw new UnprocessableEntityHttpException('Bank provider and account number are required for the preferred method');
        }
        if ($payload['payout_method_code'] === 'card_transfer'
            && $requirePaymentDetails && empty($payload['payout_card_number'])) {
            throw new UnprocessableEntityHttpException('A valid card number is required for the preferred method');
        }
        $payload['country_code'] = strtoupper($payload['country_code']);
        $payload['first_name'] = trim($payload['first_name']);
        $payload['last_name'] = trim($payload['last_name']);
        $payload['phone_e164'] = isset($payload['phone_e164']) && trim((string)$payload['phone_e164']) !== '' ? trim((string)$payload['phone_e164']) : null;
        $payload['email'] = isset($payload['email']) && trim((string)$payload['email']) !== '' ? strtolower(trim((string)$payload['email'])) : null;
        if (isset($payload['payout_account_number'])) {
            $payload['payout_account_number'] = preg_replace('/\D+/', '', $payload['payout_account_number']);
        }
        if (isset($payload['payout_card_number'])) {
            $payload['payout_card_number'] = preg_replace('/\D+/', '', $payload['payout_card_number']);
        }
    }

    private function encryptPaymentValue(string $value): string
    {
        $key = (string) (getenv('SEDERG_PII_KEY') ?: getenv('SEDERG_COOKIE_KEY'));
        if (strlen($key) < 16) {
            throw new \yii\web\ServerErrorHttpException('Payment data encryption is not configured');
        }
        return base64_encode(Yii::$app->security->encryptByKey($value, $key));
    }

    private function isValidCardNumber(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value);
        if (!is_string($digits) || !preg_match('/^\d{12,19}$/', $digits)) {
            return false;
        }
        $sum = 0;
        $alternate = false;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];
            if ($alternate && ($digit *= 2) > 9) {
                $digit -= 9;
            }
            $sum += $digit;
            $alternate = !$alternate;
        }
        return $sum % 10 === 0;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
