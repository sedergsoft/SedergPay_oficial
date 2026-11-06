<?php

declare(strict_types=1);

/**
 * End-to-end regression test for KYC document uploads.
 *
 * It sends a 3 MB PNG through the portal (nginx -> backend) and verifies that
 * the infrastructure accepts files inside the documented 5 MB limit, then sends
 * a 6 MB PNG and verifies the application rejects it with a clear message.
 *
 * This catches the two defaults that used to break valid uploads:
 *   - nginx client_max_body_size (1 MB)
 *   - PHP upload_max_filesize (2 MB)
 *
 * Run it inside the backend container after recreating the stack:
 *
 *   docker compose up -d
 *   docker compose exec -T backend php tests/integration/kyc_upload_smoke.php
 *
 * The test resets the CI customer's KYC case before and after running.
 */

$dsn = getenv('SEDERG_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=sederg_pay';
$pdo = new PDO($dsn, getenv('SEDERG_DB_USER') ?: 'root', getenv('SEDERG_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$portalUrl = rtrim((string) (getenv('SEDERG_PORTAL_URL') ?: 'http://frontend'), '/');
$storageRoot = rtrim((string) (getenv('SEDERG_PRIVATE_STORAGE_PATH') ?: '/var/lib/sederg-private'), '/');
$customerPublicId = '33333333-3333-4333-8333-333333333333';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
    echo "PASS: {$message}\n";
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
    echo "PASS: {$message}\n";
}

/**
 * @param list<string> $headers
 * @return array{0:int,1:mixed,2:string}
 */
function httpRequest(string $url, array $headers, string $body): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 60,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return [$status, is_string($raw) ? json_decode($raw, true) : null, is_string($raw) ? $raw : ''];
}

/**
 * Builds a synthetic PNG with a valid header and the requested size.
 */
function pngPayload(int $bytes): string
{
    // 1x1 transparent PNG; padding keeps finfo and the signature check happy.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    );
    if ($png === false) {
        fail('cannot decode the PNG fixture');
    }

    return $png . str_repeat("\0", max(0, $bytes - strlen($png)));
}

/**
 * @return array{0:int,1:mixed,2:string}
 */
function upload(string $portalUrl, string $userPublicId, string $fileName, string $payload): array
{
    $boundary = '----sedergKycTest' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"document_type\"\r\n\r\n"
        . "identity\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n"
        . "Content-Type: image/png\r\n\r\n"
        . $payload . "\r\n"
        . "--{$boundary}--\r\n";

    return httpRequest(
        $portalUrl . '/api/v1/kyc/documents',
        [
            'Accept: application/json',
            'X-User-Public-Id: ' . $userPublicId,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
        $body
    );
}

function resetCase(PDO $pdo, string $storageRoot, string $userPublicId): void
{
    $case = $pdo->prepare(
        'SELECT k.id FROM kyc_cases k INNER JOIN users u ON u.id = k.user_id WHERE u.public_id = ?'
    );
    $case->execute([$userPublicId]);
    $caseId = $case->fetchColumn();
    if ($caseId === false) {
        return;
    }

    $documents = $pdo->prepare('SELECT storage_key FROM kyc_documents WHERE kyc_case_id = ?');
    $documents->execute([(int) $caseId]);
    foreach ($documents->fetchAll(PDO::FETCH_COLUMN) as $storageKey) {
        $path = $storageRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $storageKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $pdo->prepare('DELETE FROM kyc_documents WHERE kyc_case_id = ?')->execute([(int) $caseId]);
    $pdo->prepare('DELETE FROM kyc_cases WHERE id = ?')->execute([(int) $caseId]);
}

$phpLimit = (int) ini_get('upload_max_filesize') * 1024 * 1024;
assertTrue($phpLimit >= 6 * 1024 * 1024, 'PHP upload_max_filesize allows files up to at least 6 MB');

resetCase($pdo, $storageRoot, $customerPublicId);
echo "PASS: previous CI KYC case reset\n";

try {
    $payload = pngPayload(3 * 1024 * 1024);
    [$status, $body] = upload($portalUrl, $customerPublicId, 'kyc-3mb.png', $payload);
    assertSame(200, $status, 'a 3 MB PNG is accepted through nginx and PHP');
    assertSame(true, $body['uploaded'] ?? null, 'the upload endpoint confirms the document');

    $payload = pngPayload(6 * 1024 * 1024);
    [$status, $body] = upload($portalUrl, $customerPublicId, 'kyc-6mb.png', $payload);
    assertSame(422, $status, 'a 6 MB PNG is rejected by the application limit');
    assertTrue(
        str_contains((string) ($body['message'] ?? ''), '5 MB'),
        'the rejection explains the 5 MB application limit'
    );
} finally {
    resetCase($pdo, $storageRoot, $customerPublicId);
    echo "PASS: CI KYC case and uploaded test files cleaned up\n";
}

echo "\nAll KYC upload checks passed.\n";
