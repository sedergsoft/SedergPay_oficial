<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

use RuntimeException;

/**
 * Minimal HTTP JSON client based on PHP streams.
 *
 * The backend image does not require ext-curl, and the request target is always
 * operator-controlled configuration rather than request input.
 */
final class StreamBallerineTransport implements BallerineTransportInterface
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Cannot encode the Ballerine request', 0, $exception);
        }

        $headerLines = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SEDERG_PAY/1.0',
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $body,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Ballerine service is unavailable');
        }
        if (strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Ballerine response exceeds the allowed size');
        }

        $status = $this->statusCode($http_response_header ?? []);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Ballerine service rejected the request with HTTP ' . $status);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ballerine service returned an invalid response');
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})\b#', (string) $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
