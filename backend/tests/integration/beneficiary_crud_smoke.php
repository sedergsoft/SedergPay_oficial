<?php

declare(strict_types=1);

/**
 * Prueba de integracion HTTP del CRUD de beneficiarios.
 *
 * Comprueba el alta sin proveedor de pago (cash_pickup), la edicion y el
 * borrado logico. Se ejecuta contra la API en :8080 con la cabecera de
 * identidad de desarrollo.
 */

$base = getenv('SEDERG_API_BASE') ?: 'http://127.0.0.1:8080';
$userPublicId = getenv('SEDERG_TEST_USER_PUBLIC_ID') ?: '11111111-1111-4111-8111-111111111111';

function request(string $method, string $path, ?array $payload = null): array
{
    global $base, $userPublicId;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-User-Public-Id: ' . $userPublicId,
    ];
    if ($method === 'POST') {
        $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 20,
        ],
    ]);
    $body = @file_get_contents($base . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, is_string($body) ? json_decode($body, true) : null, $body];
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

[$status, $created, $raw] = request('POST', '/api/v1/beneficiaries', [
    'country_code' => 'CU',
    'first_name' => 'CI',
    'last_name' => 'Beneficiario Cash',
    'phone_e164' => '+53455555555',
    'email' => 'ci.cash@sederg.invalid',
    'payout_method_code' => 'cash_pickup',
]);
assertTrue($status === 201, 'beneficiary without payout provider is created (cash_pickup)');
assertTrue(($created['payout_provider'] ?? 'unset') === null, 'payout provider is stored as null when it is not provided');
$publicId = (string) ($created['public_id'] ?? '');

[$updateStatus, $updated] = request('PUT', '/api/v1/beneficiaries/' . $publicId, [
    'country_code' => 'CU',
    'first_name' => 'CI Editado',
    'last_name' => 'Beneficiario Cash',
    'phone_e164' => '+53455555555',
    'email' => 'ci.cash@sederg.invalid',
    'payout_method_code' => 'cash_pickup',
]);
assertTrue($updateStatus === 200, 'beneficiary can be updated');
assertTrue(($updated['first_name'] ?? '') === 'CI Editado', 'the update is persisted');

[$deleteStatus, $deleted] = request('DELETE', '/api/v1/beneficiaries/' . $publicId);
assertTrue($deleteStatus === 200, 'beneficiary can be deleted');
assertTrue(($deleted['status'] ?? '') === 'deleted', 'the delete is a soft delete');

[$listStatus, $list] = request('GET', '/api/v1/beneficiaries');
assertTrue($listStatus === 200, 'beneficiaries can be listed');
$ids = array_map(static fn (array $item): string => (string) ($item['public_id'] ?? ''), is_array($list) ? $list : []);
assertTrue(!in_array($publicId, $ids, true), 'a deleted beneficiary is no longer listed');

echo "All beneficiary HTTP integration checks passed.\n";
