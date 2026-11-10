<?php

declare(strict_types=1);

namespace app\Infrastructure\Security;

use RuntimeException;

final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function provisioningUri(string $secret, string $accountName, string $issuer = 'SEDERG_PAY'): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return "otpauth://totp/{$label}?{$params}";
    }

    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($code === null || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{plain: list<string>, hashed: list<string>} */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $formatted = substr($code, 0, 5) . '-' . substr($code, 5, 5);
            $plain[] = $formatted;
            $hashed[] = hash('sha256', $formatted);
        }
        return ['plain' => $plain, 'hashed' => $hashed];
    }

    public function consumeRecoveryCode(string $input, array $hashedCodes): ?array
    {
        $input = strtoupper(trim($input));
        $hash = hash('sha256', $input);
        foreach ($hashedCodes as $index => $stored) {
            if (is_string($stored) && hash_equals($stored, $hash)) {
                unset($hashedCodes[$index]);
                return array_values($hashedCodes);
            }
        }
        return null;
    }

    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** self::DIGITS);
        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::ALPHABET[bindec($chunk)];
        }
        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            throw new RuntimeException('Invalid TOTP secret');
        }
        $binary = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $data = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $data .= chr(bindec($chunk));
            }
        }
        return $data;
    }
}
