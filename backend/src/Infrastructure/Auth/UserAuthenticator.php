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
        $authMode = getenv('SEDERG_AUTH_MODE');
        if ($authMode === 'development') {
            $allowHeaderAuth = getenv('SEDERG_ALLOW_DEV_HEADER_AUTH');
            if ($allowHeaderAuth !== '1') {
                throw new ForbiddenHttpException('Development header authentication is disabled');
            }
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

        // Fail closed: only the OIDC bearer-token path is accepted outside development mode.
        if ($authMode !== 'production') {
            throw new ForbiddenHttpException('Unsupported authentication mode');
        }

        $claims = $this->verifyBearerToken();
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}}
             WHERE oidc_subject = :subject AND status = :status
               AND email_verified_at IS NOT NULL AND deleted_at IS NULL LIMIT 1',
            [':subject' => $claims['sub'], ':status' => 'active']
        )->queryScalar();
        if ($id === false) {
            throw new ForbiddenHttpException('OIDC subject is not provisioned');
        }
        return (int) $id;
    }

    public function role(): string
    {
        $userId = $this->userId();
        $role = $this->db->createCommand(
            'SELECT role FROM {{%users}} WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [':id' => $userId]
        )->queryScalar();

        return is_string($role) ? $role : 'customer';
    }

    public function requireRole(string $requiredRole): void
    {
        if ($this->role() !== $requiredRole) {
            throw new \yii\web\ForbiddenHttpException('Administrator access is required');
        }
    }

    private function findByPublicId(string $publicId): ?int
    {
        $id = $this->db->createCommand(
            'SELECT id FROM {{%users}}
             WHERE public_id = :public_id AND status = :status
               AND email_verified_at IS NOT NULL AND deleted_at IS NULL LIMIT 1',
            [':public_id' => $publicId, ':status' => 'active']
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
        $jwksUri = trim((string) getenv('SEDERG_OIDC_JWKS_URI'));
        if ($issuer === '' || $audience === '' || ($publicKey === '' && $jwksUri === '')) {
            throw new InvalidConfigException('OIDC issuer, audience and a public key or JWKS URI are required outside development mode');
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
        $alg = $tokenHeader['alg'] ?? null;
        if ($alg !== 'RS256'
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
        $keyMaterial = $publicKey;
        if ($jwksUri !== '') {
            $kid = isset($tokenHeader['kid']) && is_string($tokenHeader['kid']) ? $tokenHeader['kid'] : null;
            $keyMaterial = $this->resolveJwksKey($jwksUri, $kid, false);
        }
        $verified = $this->verifySignature($encodedHeader . '.' . $encodedPayload, $signature, $keyMaterial);
        if ($verified !== 1 && $jwksUri !== '') {
            // Refresh JWKS once in case of key rotation.
            $kid = isset($tokenHeader['kid']) && is_string($tokenHeader['kid']) ? $tokenHeader['kid'] : null;
            $keyMaterial = $this->resolveJwksKey($jwksUri, $kid, true);
            $verified = $this->verifySignature($encodedHeader . '.' . $encodedPayload, $signature, $keyMaterial);
        }
        if ($verified !== 1) {
            throw new ForbiddenHttpException('OIDC token signature validation failed');
        }
        return $claims;
    }

    private function verifySignature(string $data, string $signature, string $keyMaterial): int
    {
        $result = @openssl_verify($data, $signature, $keyMaterial, OPENSSL_ALGO_SHA256);
        return $result === 1 ? 1 : 0;
    }

    private function resolveJwksKey(string $jwksUri, ?string $kid, bool $forceRefresh): string
    {
        $cacheKey = 'oidc:jwks:' . hash('sha256', $jwksUri);
        $jwks = null;
        if (!$forceRefresh && Yii::$app->has('cache')) {
            $cached = Yii::$app->cache->get($cacheKey);
            if (is_array($cached)) {
                $jwks = $cached;
            }
        }
        if ($jwks === null) {
            $jwks = $this->fetchJwks($jwksUri);
            if (Yii::$app->has('cache')) {
                Yii::$app->cache->set($cacheKey, $jwks, 300);
            }
        }
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            throw new ForbiddenHttpException('OIDC JWKS is invalid');
        }
        $selected = null;
        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }
            if (($key['kty'] ?? null) !== 'RSA') {
                continue;
            }
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if ($kid !== null) {
                if (($key['kid'] ?? null) === $kid) {
                    $selected = $key;
                    break;
                }
            } elseif ($selected === null) {
                $selected = $key;
            }
        }
        if ($selected === null) {
            throw new ForbiddenHttpException('OIDC signing key not found');
        }
        $n = $selected['n'] ?? null;
        $e = $selected['e'] ?? null;
        if (!is_string($n) || !is_string($e)) {
            throw new ForbiddenHttpException('OIDC signing key is invalid');
        }
        $pem = $this->rsaJwkToPem($n, $e);
        if ($pem === '') {
            throw new ForbiddenHttpException('OIDC signing key could not be processed');
        }
        return $pem;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchJwks(string $jwksUri): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
            'https' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($jwksUri, false, $context);
        if (!is_string($body) || $body === '') {
            throw new ForbiddenHttpException('Unable to fetch OIDC JWKS');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ForbiddenHttpException('OIDC JWKS is invalid');
        }
        return $decoded;
    }

    private function rsaJwkToPem(string $n, string $e): string
    {
        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);
        if ($modulus === '' || $exponent === '') {
            return '';
        }
        $components = [
            'modulus' => $this->derInteger($modulus),
            'publicExponent' => $this->derInteger($exponent),
        ];
        $rsaPublicKey = $this->derSequence($components['modulus'] . $components['publicExponent']);
        // rsaEncryption OID: 1.2.840.113549.1.1.1
        $algorithm = $this->derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = "\x03" . $this->derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = $this->derSequence($algorithm . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string
    {
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derSequence(string $value): string
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string
    {
        if ($length <= 127) {
            return chr($length);
        }
        $temp = '';
        while ($length > 0) {
            $temp = chr($length & 0xff) . $temp;
            $length >>= 8;
        }
        return chr(0x80 | strlen($temp)) . $temp;
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
