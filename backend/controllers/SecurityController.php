<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Security\TotpService;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnprocessableEntityHttpException;

final class SecurityController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    private function throttle(string $action, int $limit, int $windowSeconds): void
    {
        \app\Infrastructure\Security\RateLimiter::enforce($action, $limit, $windowSeconds);
    }

    public function actionIndex(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $user = Yii::$app->db->createCommand(
            'SELECT public_id, email, two_factor_enabled, two_factor_confirmed_at
             FROM {{%users}} WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($user === false) {
            throw new ForbiddenHttpException('Authenticated user was not found');
        }
        $enabled = !empty($user['two_factor_enabled']) && $user['two_factor_confirmed_at'] !== null;
        return [
            'public_id' => $user['public_id'],
            'email' => $user['email'],
            'two_factor_enabled' => $enabled,
            'password_change_available' => getenv('SEDERG_AUTH_MODE') === 'development',
        ];
    }

    public function actionChangePassword(): array
    {
        $this->throttle('change-password', 10, 600);
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            throw new ForbiddenHttpException('Password is managed by the configured OIDC provider');
        }
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload)
            || !is_string($payload['current_password'] ?? null)
            || !is_string($payload['new_password'] ?? null)) {
            throw new UnprocessableEntityHttpException('Current password and new password are required');
        }
        if (strlen($payload['new_password']) < 12 || strlen($payload['new_password']) > 255) {
            throw new UnprocessableEntityHttpException('La nueva contraseña debe tener entre 12 y 255 caracteres');
        }
        if ($payload['current_password'] === $payload['new_password']) {
            throw new UnprocessableEntityHttpException('La nueva contraseña debe ser diferente de la actual');
        }

        $user = Yii::$app->db->createCommand(
            'SELECT id, password_hash FROM {{%users}} WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($user === false || !Yii::$app->security->validatePassword($payload['current_password'], $user['password_hash'])) {
            throw new ForbiddenHttpException('La contraseña actual no es correcta');
        }

        Yii::$app->db->createCommand()->update('{{%users}}', [
            'password_hash' => Yii::$app->security->generatePasswordHash($payload['new_password']),
        ], ['id' => $userId])->execute();

        return ['changed' => true];
    }

    public function actionTwoFactorSetup(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $user = Yii::$app->db->createCommand(
            'SELECT email, two_factor_enabled, two_factor_confirmed_at FROM {{%users}} WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($user === false) {
            throw new ForbiddenHttpException('Authenticated user was not found');
        }
        if (!empty($user['two_factor_enabled']) && $user['two_factor_confirmed_at'] !== null) {
            throw new UnprocessableEntityHttpException('2FA ya está activado');
        }

        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $uri = $totp->provisioningUri($secret, (string) $user['email']);
        Yii::$app->db->createCommand()->update('{{%users}}', [
            'two_factor_secret' => $secret,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ], ['id' => $userId])->execute();

        return ['secret' => $secret, 'otpauth_uri' => $uri];
    }

    public function actionTwoFactorConfirm(): array
    {
        $this->throttle('2fa-confirm', 10, 600);
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $payload = Yii::$app->request->bodyParams;
        $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : '';
        $user = Yii::$app->db->createCommand(
            'SELECT two_factor_secret, two_factor_confirmed_at FROM {{%users}} WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($user === false || !is_string($user['two_factor_secret']) || $user['two_factor_secret'] === '') {
            throw new UnprocessableEntityHttpException('Inicia la configuración de 2FA primero');
        }
        if ($user['two_factor_confirmed_at'] !== null) {
            throw new UnprocessableEntityHttpException('2FA ya está activado');
        }

        $totp = new TotpService();
        if (!$totp->verify($user['two_factor_secret'], $code)) {
            throw new ForbiddenHttpException('Código 2FA inválido');
        }

        $codes = $totp->generateRecoveryCodes(8);
        $now = gmdate('Y-m-d H:i:s');
        Yii::$app->db->createCommand()->update('{{%users}}', [
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => $now,
            'two_factor_recovery_codes' => json_encode($codes['hashed'], JSON_UNESCAPED_UNICODE),
        ], ['id' => $userId])->execute();

        return ['enabled' => true, 'recovery_codes' => $codes['plain']];
    }

    public function actionTwoFactorDisable(): array
    {
        $this->throttle('2fa-disable', 10, 600);
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload) || !is_string($payload['password'] ?? null)) {
            throw new UnprocessableEntityHttpException('Password is required');
        }
        $code = is_string($payload['code'] ?? null) ? $payload['code'] : '';

        $user = Yii::$app->db->createCommand(
            'SELECT password_hash, two_factor_enabled, two_factor_confirmed_at, two_factor_secret, two_factor_recovery_codes
             FROM {{%users}} WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryOne();
        if ($user === false || !Yii::$app->security->validatePassword($payload['password'], $user['password_hash'])) {
            throw new ForbiddenHttpException('Contraseña incorrecta');
        }
        $enabled = !empty($user['two_factor_enabled']) && $user['two_factor_confirmed_at'] !== null;
        if (!$enabled) {
            return ['enabled' => false];
        }

        $totp = new TotpService();
        $verified = $code !== '' && is_string($user['two_factor_secret']) && $totp->verify($user['two_factor_secret'], $code);
        $recoveryRemaining = null;
        if (!$verified && $code !== '') {
            $remaining = $totp->consumeRecoveryCode($code, $this->decodeRecoveryCodes($user['two_factor_recovery_codes'] ?? null));
            if ($remaining !== null) {
                $verified = true;
                $recoveryRemaining = $remaining;
            }
        }
        if ($code === '') {
            throw new UnprocessableEntityHttpException('Se requiere un código 2FA o código de recuperación para desactivar 2FA');
        }
        if (!$verified) {
            throw new ForbiddenHttpException('Código 2FA inválido');
        }

        Yii::$app->db->createCommand()->update('{{%users}}', [
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ], ['id' => $userId])->execute();

        return ['enabled' => false];
    }

    /** @return list<string> */
    private function decodeRecoveryCodes(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}
