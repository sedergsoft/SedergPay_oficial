<?php

declare(strict_types=1);

namespace app\Infrastructure\Auth;

use Yii;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\web\ForbiddenHttpException;

final class UserAuthenticator
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function userId(): int
    {
        if (getenv('SEDERG_AUTH_MODE') === 'development') {
            $publicId = trim((string) Yii::$app->request->headers->get('X-User-Public-Id', ''));
            if ($publicId === '') {
                throw new ForbiddenHttpException('Development identity is required');
            }
            $id = $this->findByPublicId($publicId);
            if ($id === null) {
                throw new ForbiddenHttpException('Authenticated user was not found');
            }
            return $id;
        }

        $claims = $this->verifyBearerToken();
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE oidc_subject = :subject AND deleted_at IS NULL LIMIT 1',
            [':subject' => $claims['sub']]
        )->queryScalar();
        if ($id === false) {
            throw new ForbiddenHttpException('OIDC subject is not provisioned');
        }
        return (int) $id;
    }

    private function findByPublicId(string $publicId): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}} WHERE public_id = :public_id AND deleted_at IS NULL LIMIT 1',
            [':public_id' => $publicId]
        )->queryScalar();
        return $id === false ? null : (int) $id;
    }

    /**
     * @return array{sub: string, iss: string, aud: string|list<string>, exp: int}
     */
    private function verifyBearerToken(): array
    {
        $issuer = trim((string) getenv('SEDERG_OIDC_ISSUER'));
        $audience = trim((string) getenv('SEDERG_OIDC_AUDIENCE'));
        $publicKey = trim((string) getenv('SEDERG_OIDC_PUBLIC_KEY'));
        if ($issuer === '' || $audience === '' || $publicKey === '') {
            throw new InvalidConfigException('OIDC issuer, audience and public key are required outside development mode');
        }

        $header = trim((string) Yii::$app->request->headers->get('Authorization', ''));
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/', $header, $matches)) {
            throw new ForbiddenHttpException('A valid OIDC bearer token is required');
        }
        $parts = explode('.', $matches[1]);
        if (count($parts) !== 3) {
            throw new ForbiddenHttpException('OIDC token encoding is invalid');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $tokenHeader = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);
        if (($tokenHeader['alg'] ?? null) !== 'RS256'
            || !isset($claims['sub'], $claims['iss'], $claims['aud'], $claims['exp'])
            || !is_string($claims['sub'])
            || !is_string($claims['iss'])
            || !is_int($claims['exp'])) {
            throw new ForbiddenHttpException('OIDC token claims are invalid');
        }
        if (!hash_equals($issuer, $claims['iss'])
            || $claims['exp'] <= time()
            || !$this->audienceMatches($claims['aud'])) {
            throw new ForbiddenHttpException('OIDC token validation failed');
        }
        if (openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) !== 1) {
            throw new ForbiddenHttpException('OIDC token signature validation failed');
        }
        return $claims;
    }

    private function audienceMatches(mixed $audience): bool
    {
        $expected = trim((string) getenv('SEDERG_OIDC_AUDIENCE'));
        return is_string($audience)
            ? hash_equals($expected, $audience)
            : is_array($audience) && in_array($expected, $audience, true);
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($this->base64UrlDecode($value), true);
        if (!is_array($decoded)) {
            throw new ForbiddenHttpException('OIDC token encoding is invalid');
        }
        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new ForbiddenHttpException('OIDC token encoding is invalid');
        }
        return $decoded;
    }
}
