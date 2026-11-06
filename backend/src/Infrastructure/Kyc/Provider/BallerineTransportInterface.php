<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

interface BallerineTransportInterface
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array;
}
