<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Registration\RegistrationRepository;
use app\Infrastructure\Identity\VerificationMailer;
use app\Infrastructure\Configuration\PlatformSettings;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\HttpException;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnprocessableEntityHttpException;

final class RegistrationController extends Controller
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
        $this->throttle('registration', 5, 600);
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            throw new ForbiddenHttpException('Client registration must be provisioned through OIDC');
        }
        if (!PlatformSettings::getBool(Yii::$app->db, 'operations.registrations_enabled')) {
            throw new ForbiddenHttpException('New registrations are temporarily disabled by the administrator');
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
        $this->validatePayload($payload);

        $repository = new RegistrationRepository(Yii::$app->db);
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $scope = 'registration:create';
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

        $email = strtolower(trim($payload['email']));
        $phone = isset($payload['phone_e164']) ? trim($payload['phone_e164']) : null;
        if ($repository->contactExists($email, $phone)) {
            throw new ConflictHttpException('Registration could not be completed. Please verify your information or sign in.');
        }

        $countryCode = strtoupper($payload['residence_country_code']);
        $countryId = $repository->countryId($countryCode);
        if ($countryId === null) {
            throw new UnprocessableEntityHttpException('Unsupported residence country');
        }

        $publicId = $this->generateUuid();
        $verificationToken = bin2hex(random_bytes(32));
        $ttlMinutes = (int) PlatformSettings::get(Yii::$app->db, 'security.verification_token_ttl_minutes');
        $response = [
            'public_id' => $publicId,
            'status' => 'pending',
            'email' => $email,
            'phone_e164' => $phone,
            'preferred_language' => $payload['preferred_language'] ?? 'es',
            'verification_required' => true,
        ];
        $transaction = $repository->beginTransaction();
        try {
            $userId = $repository->saveRegistration(
                ['email' => $email, 'phone_e164' => $phone] + $payload,
                $publicId,
                Yii::$app->security->generatePasswordHash($payload['password']),
                $countryId,
                $payload['consent_policy_version'],
                (string) $request->getUserIP(),
                (string) $request->userAgent,
                $transaction
            );
            $repository->saveVerificationToken($userId, hash('sha256', $verificationToken));
            $mailLogged = (new VerificationMailer())->send($email, $payload['first_name'], $verificationToken, $ttlMinutes);
            if (getenv('SEDERG_AUTH_MODE') === 'development' && $mailLogged) {
                $response['dev_verification_token'] = $verificationToken;
                $response['dev_verification_url'] = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/') . '/?verify_email=' . rawurlencode($email) . '&token=' . rawurlencode($verificationToken);
            }
            $repository->saveIdempotency($scope, $idempotencyKey, $requestHash, $response, $transaction);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            $message = $exception instanceof \RuntimeException ? $exception->getMessage() : '';
            if (str_starts_with($message, 'SMTP') || str_starts_with($message, 'MAIL')) {
                throw new HttpException(503, 'No se pudo enviar el correo de verificación. Configura el SMTP o la API de correo e inténtalo de nuevo.', 0, $exception);
            }
            if ($exception instanceof \yii\db\IntegrityException) {
                throw new ConflictHttpException('Registration could not be completed. Please verify your information or sign in.');
            }
            throw $exception;
        }
        Yii::$app->response->statusCode = 201;
        return $response;
    }

    private function validatePayload(array &$payload): void
    {
        $required = ['email', 'password', 'first_name', 'last_name', 'residence_country_code', 'consent_policy_version'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new UnprocessableEntityHttpException("Missing field: {$field}");
            }
        }

        if (filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false
            || strlen($payload['email']) > 190) {
            throw new UnprocessableEntityHttpException('A valid email is required');
        }
        if (strlen($payload['password']) < 12 || strlen($payload['password']) > 255) {
            throw new UnprocessableEntityHttpException('Password must contain 12 to 255 characters');
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (mb_strlen(trim($payload[$field])) > 100) {
                throw new UnprocessableEntityHttpException("Field too long: {$field}");
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', strtoupper($payload['residence_country_code']))) {
            throw new UnprocessableEntityHttpException('A valid residence country is required');
        }
        if (isset($payload['phone_e164'])
            && (!is_string($payload['phone_e164']) || !preg_match('/^\+[1-9]\d{7,14}$/', $payload['phone_e164']))) {
            throw new UnprocessableEntityHttpException('Phone must use E.164 format');
        }
        if (!isset($payload['accept_terms']) || $payload['accept_terms'] !== true
            || !isset($payload['accept_privacy']) || $payload['accept_privacy'] !== true) {
            throw new UnprocessableEntityHttpException('Terms and privacy consent are required');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $payload['consent_policy_version'])) {
            throw new UnprocessableEntityHttpException('Invalid consent policy version');
        }
        $payload['residence_country_code'] = strtoupper($payload['residence_country_code']);
        $payload['email'] = strtolower(trim($payload['email']));
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
