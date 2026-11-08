<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Identity\VerificationMailer;
use app\Infrastructure\Configuration\PlatformSettings;
use app\Infrastructure\Auth\UserAuthenticator;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
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

    public function actionLogin(): array
    {
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
                    p.first_name, p.last_name
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             WHERE u.email = :email AND u.deleted_at IS NULL
             LIMIT 1',
            [':email' => $email]
        )->queryOne();

        if ($user === false || !Yii::$app->security->validatePassword($payload['password'], $user['password_hash'])) {
            throw new ForbiddenHttpException('Invalid email or password');
        }
        if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
            throw new ForbiddenHttpException('Verify your email before signing in');
        }

        Yii::$app->db->createCommand()->update(
            '{{%users}}',
            ['last_login_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $user['id']]
        )->execute();

        return $this->publicUser($user);
    }

    public function actionVerifyEmail(): array
    {
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
                // Preserve anti-enumeration shape in non-dev contexts.
            }
        }

        return $result;
    }

    public function actionMe(): array
    {
        // Use the same authentication path as profile, beneficiaries and remittances.
        // Development resolves X-User-Public-Id; production validates the OIDC bearer token.
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();

        $user = Yii::$app->db->createCommand(
            'SELECT u.id, u.public_id, u.email, u.role, u.status, p.first_name, p.last_name
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

    private function publicUser(array $user): array
    {
        return [
            'public_id' => $user['public_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'status' => $user['status'],
        ];
    }
}
