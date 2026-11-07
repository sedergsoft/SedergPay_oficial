<?php

declare(strict_types=1);

namespace app\Infrastructure\Auth;

use Yii;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;

/**
 * Minimal JWT (HS256) implementation for development-mode session tokens.
 *
 * Tokens are issued after a successful password login in AuthController::actionLogin()
 * and verified on every authenticated request by UserAuthenticator::userId().
 *
 * Claims:
 *  - sub: user public_id (string)
 *  - iat: issued at (int, unix seconds)
 *  - exp: expires at (int, unix seconds)
 */
final class JwtToken
{
    private const ALGORITHM = 'HS256';
    private const TTL_SECONDS = 8 * 60 * 60; // 8 hours

    /**
     * Sign a token for the given public ID.
     */
    public static function sign(string $publicId): string
    {
        $secret = self::secret();
        $now = (int) time();

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES);
        $payload = json_encode([
            'sub' => $publicId,
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ], JSON_UNESCAPED_SLASHES);

        $signingInput = self::base64UrlEncode($header) . '.' . self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Verify and decode a token. Returns the public ID (sub) claim.
     * Throws ForbiddenHttpException if the token is missing, malformed, expired or has an invalid signature.
     */
    public static function verify(string $token): string
    {
        if ($token === '') {
            throw new ForbiddenHttpException('A valid session token is required');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ForbiddenHttpException('Session token encoding is invalid');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = self::decodeJson($encodedHeader);
        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw new ForbiddenHttpException('Session token algorithm is invalid');
        }

        $claims = self::decodeJson($encodedPayload);
        if (!isset($claims['sub'], $claims['iat'], $claims['exp'])
            || !is_string($claims['sub'])
            || !is_int($claims['iat'])
            || !is_int($claims['exp'])) {
            throw new ForbiddenHttpException('Session token claims are invalid');
        }

        if ($claims['exp'] <= time()) {
            throw new ForbiddenHttpException('Session token has expired');
        }

        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $expected = hash_hmac('sha256', $signingInput, self::secret(), true);
        $actual = self::base64UrlDecode($encodedSignature);

        if (!hash_equals($expected, $actual)) {
            throw new ForbiddenHttpException('Session token signature is invalid');
        }

        return $claims['sub'];
    }

    private static function secret(): string
    {
        $secret = trim((string) getenv('SEDERG_JWT_SECRET'));
        if ($secret === '') {
            // Fall back to the cookie key so deployments that already set it keep working.
            $secret = trim((string) getenv('SEDERG_COOKIE_KEY'));
        }
        if ($secret === '') {
            throw new InvalidConfigException('SEDERG_JWT_SECRET or SEDERG_COOKIE_KEY is required for development auth');
        }
        return $secret;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    private static function decodeJson(string $value): array
    {
        $decoded = json_decode(self::base64UrlDecode($value), true);
        if (!is_array($decoded)) {
            throw new ForbiddenHttpException('Session token encoding is invalid');
        }
        return $decoded;
    }
}
