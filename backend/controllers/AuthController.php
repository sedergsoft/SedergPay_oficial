<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Configuration\PlatformSettings;
use app\Infrastructure\Identity\VerificationMailer;
use app\Infrastructure\Security\TotpService;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnprocessableEntityHttpException;

final class AuthController extends Controller
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

    public function actionLogin(): array
    {
        $this->throttle('login', 10, 300);
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            throw new ForbiddenHttpException('Use the configured OIDC provider to sign in');
        }

        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload) || !is_string($payload['email'] ?? null) || !is_string($payload['password'] ?? null)) {
            throw new UnprocessableEntityHttpException('Email and password are required');
        }

        $email = strtolower(trim($payload['email']));
        $user = Yii::$app->db->createCommand(
            'SELECT u.id, u.public_id, u.email, u.password_hash, u.role, u.status, u.email_verified_at,
                    u.two_factor_enabled, u.two_factor_confirmed_at,
                    p.first_name, p.last_name
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             WHERE u.email = :email AND u.deleted_at IS NULL
             LIMIT 1',
            [':email' => $email]
        )->queryOne();

        $valid = $user !== false && Yii::$app->security->validatePassword($payload['password'], $user['password_hash']);
        if (!$valid) {
            throw new ForbiddenHttpException('Invalid email or password');
        }
        if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
            // Evitar oráculo en entornos expuestos; en desarrollo local se conserva
            // la señal para guiar el reenvío de verificación sin romper la UX.
            if (getenv('SEDERG_EXPOSE_AUTH_STATE') === '1') {
                Yii::$app->response->statusCode = 403;
                return [
                    'email_verification_required' => true,
                    'message' => 'Verify your email before signing in',
                ];
            }
            throw new ForbiddenHttpException('Invalid email or password');
        }

        $twoFactorEnabled = !empty($user['two_factor_enabled']) && $user['two_factor_confirmed_at'] !== null;
        if ($twoFactorEnabled) {
            $challenge = bin2hex(random_bytes(32));
            Yii::$app->db->createCommand()->insert('{{%user_auth_challenges}}', [
                'user_id' => $user['id'],
                'challenge_hash' => hash('sha256', $challenge),
                'type' => '2fa_login',
                'expires_at' => (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s'),
            ])->execute();
            return [
                'requires_2fa' => true,
                'challenge' => $challenge,
                'public_id' => $user['public_id'],
            ];
        }

        $this->completeLogin((int) $user['id']);
        return $this->publicUser($user);
    }

    public function actionTwoFactorLoginVerify(): array
    {
        $this->throttle('2fa', 10, 300);
        if (getenv('SEDERG_AUTH_MODE') !== 'development') {
            throw new ForbiddenHttpException('Use the configured OIDC provider to sign in');
        }
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload)
            || !is_string($payload['challenge'] ?? null)
            || !is_string($payload['code'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/i', $payload['challenge'])) {
            throw new UnprocessableEntityHttpException('Challenge and code are required');
        }

        $row = Yii::$app->db->createCommand(
            "SELECT c.id, c.user_id, u.public_id, u.email, u.role, u.status, u.two_factor_secret, u.two_factor_recovery_codes,
                    u.email_verified_at, p.first_name, p.last_name
             FROM {{%user_auth_challenges}} c
             INNER JOIN {{%users}} u ON u.id = c.user_id
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             WHERE c.challenge_hash = :hash AND c.type = '2fa_login'
               AND c.consumed_at IS NULL AND c.expires_at > CURRENT_TIMESTAMP
               AND u.deleted_at IS NULL
             ORDER BY c.created_at DESC LIMIT 1",
            [':hash' => hash('sha256', strtolower($payload['challenge']))]
        )->queryOne();
        if ($row === false || $row['status'] !== 'active' || $row['email_verified_at'] === null) {
            throw new ForbiddenHttpException('2FA challenge is invalid or expired');
        }

        $code = (string) $payload['code'];
        $totp = new TotpService();
        $verified = false;
        $recoveryCodes = $this->decodeRecoveryCodes($row['two_factor_recovery_codes'] ?? null);
        $updatedRecovery = null;
        if (is_string($row['two_factor_secret']) && $row['two_factor_secret'] !== '' && $totp->verify($row['two_factor_secret'], $code)) {
            $verified = true;
        } elseif ($recoveryCodes !== []) {
            $remaining = $totp->consumeRecoveryCode($code, $recoveryCodes);
            if ($remaining !== null) {
                $verified = true;
                $updatedRecovery = $remaining;
            }
        }
        if (!$verified) {
            throw new ForbiddenHttpException('Invalid 2FA code');
        }

        $now = gmdate('Y-m-d H:i:s');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->update('{{%user_auth_challenges}}', ['consumed_at' => $now], ['id' => $row['id']])->execute();
            $userUpdates = ['last_login_at' => $now];
            if ($updatedRecovery !== null) {
                $userUpdates['two_factor_recovery_codes'] = json_encode(array_values($updatedRecovery), JSON_UNESCAPED_UNICODE);
            }
            Yii::$app->db->createCommand()->update('{{%users}}', $userUpdates, ['id' => $row['user_id']])->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $e;
        }

        return $this->publicUser($row);
    }

    public function actionVerifyEmail(): array
    {
        $this->throttle('verify-email', 20, 600);
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload)
            || !is_string($payload['email'] ?? null)
            || !is_string($payload['token'] ?? null)
            || filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false
            || !preg_match('/^[a-f0-9]{64}$/i', $payload['token'])) {
            throw new UnprocessableEntityHttpException('Email and verification token are required');
        }

        $user = Yii::$app->db->createCommand(
            'SELECT id, public_id FROM {{%users}} WHERE email = :email AND deleted_at IS NULL LIMIT 1',
            [':email' => strtolower(trim($payload['email']))]
        )->queryOne();
        if ($user === false) {
            throw new ForbiddenHttpException('Invalid verification request');
        }

        $token = Yii::$app->db->createCommand(
            'SELECT id FROM {{%email_verification_tokens}}
             WHERE user_id = :user_id AND token_hash = :token_hash
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP
             ORDER BY created_at DESC LIMIT 1',
            [':user_id' => $user['id'], ':token_hash' => hash('sha256', strtolower($payload['token']))]
        )->queryOne();
        if ($token === false) {
            throw new ForbiddenHttpException('Verification token is invalid or expired');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');
            Yii::$app->db->createCommand()->update('{{%email_verification_tokens}}', ['consumed_at' => $now], ['id' => $token['id']])->execute();
            Yii::$app->db->createCommand()->update('{{%users}}', [
                'status' => 'active',
                'email_verified_at' => $now,
            ], ['id' => $user['id']])->execute();
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        return ['public_id' => $user['public_id'], 'status' => 'active'];
    }

    public function actionResendVerification(): array
    {
        $this->throttle('resend-verification', 5, 600);
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload)
            || !is_string($payload['email'] ?? null)
            || filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new UnprocessableEntityHttpException('A valid email is required');
        }

        $user = Yii::$app->db->createCommand(
            'SELECT u.id, u.email, u.status, p.first_name
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             WHERE u.email = :email AND u.deleted_at IS NULL LIMIT 1',
            [':email' => strtolower(trim($payload['email']))]
        )->queryOne();
        $result = ['verification_sent' => true];
        if ($user !== false && $user['status'] !== 'active') {
            $token = bin2hex(random_bytes(32));
            $ttlMinutes = (int) PlatformSettings::get(Yii::$app->db, 'security.verification_token_ttl_minutes');
            Yii::$app->db->createCommand()->insert('{{%email_verification_tokens}}', [
                'user_id' => $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => (new \DateTimeImmutable('+' . $ttlMinutes . ' minutes'))->format('Y-m-d H:i:s'),
            ])->execute();
            try {
                $mailLogged = (new VerificationMailer())->send($user['email'], (string) $user['first_name'], $token, $ttlMinutes);
                if (getenv('SEDERG_AUTH_MODE') === 'development' && $mailLogged) {
                    $result['dev_verification_token'] = $token;
                    $result['dev_verification_url'] = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/') . '/?verify_email=' . rawurlencode((string) $user['email']) . '&token=' . rawurlencode($token);
                }
            } catch (\Throwable $exception) {
                Yii::error('Resend verification mail failed: ' . $exception->getMessage(), __METHOD__);
                if (getenv('SEDERG_AUTH_MODE') === 'development') {
                    $result['dev_verification_token'] = $token;
                    $result['dev_verification_url'] = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/') . '/?verify_email=' . rawurlencode((string) $user['email']) . '&token=' . rawurlencode($token);
                    $result['mail_warning'] = 'No se pudo enviar el correo; se generó un enlace de verificación de desarrollo.';
                }
            }
        }

        return $result;
    }

    public function actionForgotPassword(): array
    {
        $this->throttle('forgot-password', 5, 600);
        $payload = Yii::$app->request->bodyParams;
        $result = ['sent' => true];
        if (!is_array($payload) || !is_string($payload['email'] ?? null) || filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
            return $result;
        }
        $user = Yii::$app->db->createCommand(
            'SELECT u.id, u.email, u.status, p.first_name
             FROM {{%users}} u LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             WHERE u.email = :email AND u.deleted_at IS NULL LIMIT 1',
            [':email' => strtolower(trim($payload['email']))]
        )->queryOne();
        if ($user === false) {
            return $result;
        }
        $token = bin2hex(random_bytes(32));
        $ttlMinutes = (int) PlatformSettings::get(Yii::$app->db, 'security.verification_token_ttl_minutes');
        Yii::$app->db->createCommand()->insert('{{%password_reset_tokens}}', [
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => (new \DateTimeImmutable('+' . $ttlMinutes . ' minutes'))->format('Y-m-d H:i:s'),
        ])->execute();
        try {
            $mailLogged = (new VerificationMailer())->sendPasswordReset($user['email'], (string) ($user['first_name'] ?: 'usuario'), $token, $ttlMinutes);
            if (getenv('SEDERG_AUTH_MODE') === 'development' && $mailLogged) {
                $result['dev_reset_token'] = $token;
                $result['dev_reset_url'] = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/') . '/?reset_password=1&email=' . rawurlencode((string) $user['email']) . '&token=' . rawurlencode($token);
            }
        } catch (\Throwable $e) {
            Yii::error('Forgot password mail failed: ' . $e->getMessage(), __METHOD__);
            if (getenv('SEDERG_AUTH_MODE') === 'development') {
                $result['dev_reset_token'] = $token;
                $result['dev_reset_url'] = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/') . '/?reset_password=1&email=' . rawurlencode((string) $user['email']) . '&token=' . rawurlencode($token);
                $result['mail_warning'] = 'No se pudo enviar el correo; se generó un enlace de restablecimiento de desarrollo.';
            }
        }
        return $result;
    }

    public function actionResetPassword(): array
    {
        $this->throttle('reset-password', 10, 600);
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload)
            || !is_string($payload['email'] ?? null)
            || !is_string($payload['token'] ?? null)
            || !is_string($payload['password'] ?? null)
            || filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false
            || !preg_match('/^[a-f0-9]{64}$/i', $payload['token'])) {
            throw new UnprocessableEntityHttpException('Datos de restablecimiento inválidos');
        }
        if (strlen($payload['password']) < 12 || strlen($payload['password']) > 255) {
            throw new UnprocessableEntityHttpException('La contraseña debe tener entre 12 y 255 caracteres');
        }

        $email = strtolower(trim($payload['email']));
        $user = Yii::$app->db->createCommand(
            'SELECT id, public_id, status FROM {{%users}} WHERE email = :email AND deleted_at IS NULL LIMIT 1',
            [':email' => $email]
        )->queryOne();
        if ($user === false) {
            throw new ForbiddenHttpException('El enlace de restablecimiento no es válido o ha expirado');
        }

        $token = Yii::$app->db->createCommand(
            'SELECT id FROM {{%password_reset_tokens}}
             WHERE user_id = :user_id AND token_hash = :token_hash
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP
             ORDER BY created_at DESC LIMIT 1',
            [':user_id' => $user['id'], ':token_hash' => hash('sha256', strtolower($payload['token']))]
        )->queryOne();
        if ($token === false) {
            throw new ForbiddenHttpException('El enlace de restablecimiento no es válido o ha expirado');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');
            Yii::$app->db->createCommand()->update('{{%password_reset_tokens}}', ['consumed_at' => $now], ['id' => $token['id']])->execute();
            Yii::$app->db->createCommand()->update('{{%users}}', [
                'password_hash' => Yii::$app->security->generatePasswordHash($payload['password']),
            ], ['id' => $user['id']])->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $e;
        }

        return ['reset' => true];
    }

    public function actionMe(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();

        $user = Yii::$app->db->createCommand(
            'SELECT u.id, u.public_id, u.email, u.role, u.status, u.two_factor_enabled, u.two_factor_confirmed_at,
                    p.first_name, p.last_name
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             WHERE u.id = :id AND u.status = :status
               AND u.email_verified_at IS NOT NULL AND u.deleted_at IS NULL
             LIMIT 1',
            [':id' => $userId, ':status' => 'active']
        )->queryOne();
        if ($user === false) {
            throw new ForbiddenHttpException('Authenticated user was not found');
        }

        return $this->publicUser($user);
    }

    private function completeLogin(int $userId): void
    {
        Yii::$app->db->createCommand()->update(
            '{{%users}}',
            ['last_login_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $userId]
        )->execute();
    }

    private function publicUser(array $user): array
    {
        return [
            'public_id' => $user['public_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'role' => $user['role'],
            'status' => $user['status'],
            'two_factor_enabled' => !empty($user['two_factor_enabled']) && ($user['two_factor_confirmed_at'] ?? null) !== null,
        ];
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
