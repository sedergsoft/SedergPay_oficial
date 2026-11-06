<?php

declare(strict_types=1);

/**
 * Integration smoke test for the Ballerine KYC adapter and the local kyc-mock
 * simulator.
 *
 * It exercises the real HTTP surface (session creation, signed webhook,
 * idempotency, quarantine, admin decision) instead of the unit-level classes.
 *
 * Requirements:
 *   - MySQL migrated and seeded (countries catalog includes CU).
 *   - Web backend listening on 127.0.0.1:8080.
 *   - `kyc-mock` reachable at http://kyc-mock:3000 (Compose profile "kyc-mock").
 *   - SEDERG_AUTH_MODE=development, SEDERG_BALLERINE_ENABLED=1 and a webhook
 *     secret configured in the environment.
 *
 * Run it inside the backend container so that every hostname resolves:
 *
 *   docker compose --profile kyc-mock up -d
 *   docker compose exec -T backend php tests/integration/ballerine_kyc_smoke.php
 *
 * The test is repeatable: it resets the CI customer's KYC case before running.
 */

$dsn = getenv('SEDERG_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=sederg_pay';
$pdo = new PDO($dsn, getenv('SEDERG_DB_USER') ?: 'root', getenv('SEDERG_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$baseUrl = rtrim((string) (getenv('SEDERG_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$mockUrl = rtrim((string) (getenv('KYC_MOCK_BASE_URL') ?: 'http://kyc-mock:3000'), '/');
$collectionBase = rtrim((string) (getenv('SEDERG_BALLERINE_COLLECTION_URL') ?: ''), '/');
$webhookSecret = trim((string) (getenv('SEDERG_BALLERINE_WEBHOOK_SECRET') ?: ''));

$customerPublicId = '33333333-3333-4333-8333-333333333333';
$adminPublicId = '44444444-4444-4444-8444-444444444444';

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

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * @param list<string> $headers
 * @return array{0:int,1:mixed,2:string}
 */
function httpRequest(string $method, string $url, array $headers, ?string $body = null): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 30,
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
 * @param array<string,mixed>|null $payload
 * @return array{0:int,1:mixed,2:string}
 */
function apiJson(string $method, string $path, string $userPublicId, ?array $payload = null): array
{
    global $baseUrl;
    $headers = ['Accept: application/json', 'X-User-Public-Id: ' . $userPublicId];
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    return httpRequest($method, $baseUrl . $path, $headers, $body);
}

/**
 * @param array<string,mixed> $payload
 * @param list<string> $extraHeaders
 * @return array{0:int,1:mixed,2:string}
 */
function postJson(string $url, array $payload, array $extraHeaders = []): array
{
    $headers = array_merge(
        ['Accept: application/json', 'Content-Type: application/json'],
        $extraHeaders
    );

    return httpRequest('POST', $url, $headers, json_encode($payload, JSON_THROW_ON_ERROR));
}

/**
 * @param array{0:int,1:mixed,2:string} $response
 * @return array<string,mixed>
 */
function expectStatus(int $expected, array $response, string $label): array
{
    [$status, $body, $raw] = $response;
    if ($status !== $expected) {
        fail($label . ": expected HTTP {$expected}, got {$status}: " . substr($raw, 0, 600));
    }
    echo "PASS: {$label}\n";

    return is_array($body) ? $body : [];
}

function upsertUser(PDO $pdo, string $publicId, string $email, string $role): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE public_id = ?');
    $stmt->execute([$publicId]);
    $userId = $stmt->fetchColumn();
    $now = gmdate('Y-m-d H:i:s');

    if ($userId === false) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (public_id, email, password_hash, status, role, preferred_language, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $publicId,
            $email,
            password_hash('CI-only-password', PASSWORD_DEFAULT),
            'active',
            $role,
            'es',
            $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        "UPDATE users SET status='active', role=?, email_verified_at=? WHERE id=?"
    )->execute([$role, $now, (int) $userId]);

    return (int) $userId;
}

function upsertProfile(PDO $pdo, int $userId, int $countryId): void
{
    $stmt = $pdo->prepare('SELECT user_id FROM user_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);

    $values = ['CI', 'Customer', '1990-01-01', $countryId, 'Calle de Prueba 123', 'La Habana'];
    if ($stmt->fetchColumn() === false) {
        $pdo->prepare(
            'INSERT INTO user_profiles
                (user_id, first_name, last_name, date_of_birth, residence_country_id, address_line, city)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge([$userId], $values));

        return;
    }

    $pdo->prepare(
        'UPDATE user_profiles
         SET first_name=?, last_name=?, date_of_birth=?, residence_country_id=?, address_line=?, city=?
         WHERE user_id=?'
    )->execute(array_merge($values, [$userId]));
}

assertTrue($webhookSecret !== '', 'SEDERG_BALLERINE_WEBHOOK_SECRET is configured for the test');
assertTrue($collectionBase !== '', 'SEDERG_BALLERINE_COLLECTION_URL is configured for the test');
assertSame('development', (string) getenv('SEDERG_AUTH_MODE'), 'the backend runs in development auth mode');

$countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CU' LIMIT 1")->fetchColumn();
assertTrue($countryId > 0, 'the countries catalog includes Cuba');

$customerId = upsertUser($pdo, $customerPublicId, 'ci-ballerine-customer@sederg.invalid', 'customer');
$adminId = upsertUser($pdo, $adminPublicId, 'ci-ballerine-admin@sederg.invalid', 'admin');
upsertProfile($pdo, $customerId, $countryId);
upsertProfile($pdo, $adminId, $countryId);

$previousCase = $pdo->prepare('SELECT id FROM kyc_cases WHERE user_id = ?');
$previousCase->execute([$customerId]);
$previousCaseId = $previousCase->fetchColumn();
if ($previousCaseId !== false) {
    $pdo->prepare('DELETE FROM kyc_provider_events WHERE kyc_case_id = ?')->execute([(int) $previousCaseId]);
    $pdo->prepare('DELETE FROM kyc_documents WHERE kyc_case_id = ?')->execute([(int) $previousCaseId]);
    $pdo->prepare('DELETE FROM kyc_cases WHERE id = ?')->execute([(int) $previousCaseId]);
}
echo "PASS: previous CI KYC case reset\n";

$session = expectStatus(
    200,
    apiJson('POST', '/api/v1/kyc/session', $customerPublicId, ['locale' => 'es']),
    'POST /api/v1/kyc/session starts a provider session'
);

$runtimeId = (string) ($session['provider_session_id'] ?? '');
$casePublicId = (string) ($session['case_public_id'] ?? '');
$collectionUrl = (string) ($session['collection_url'] ?? '');

assertTrue(
    preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $runtimeId) === 1,
    'the provider runtime identifier has the expected shape'
);
assertTrue(
    preg_match('/^[0-9a-f-]{36}$/i', $casePublicId) === 1,
    'the KYC case identifier has the expected shape'
);
$collectionParts = parse_url($collectionUrl);
$baseParts = parse_url($collectionBase);
parse_str((string) ($collectionParts['query'] ?? ''), $collectionQuery);
assertSame(
    ($baseParts['host'] ?? '') . ':' . ($baseParts['port'] ?? ''),
    ($collectionParts['host'] ?? '') . ':' . ($collectionParts['port'] ?? ''),
    'the collection URL uses the collection host, not the API host (' . $collectionUrl . ')'
);
assertSame($runtimeId, $collectionQuery['workflowRuntimeId'] ?? null, 'the collection URL carries the provider runtime identifier');

$caseRow = $pdo->prepare('SELECT id FROM kyc_cases WHERE public_id = ?');
$caseRow->execute([$casePublicId]);
$caseId = (int) $caseRow->fetchColumn();
assertTrue($caseId > 0, 'the KYC case exists in MySQL');

$view = expectStatus(200, apiJson('GET', '/api/v1/kyc', $customerPublicId), 'GET /api/v1/kyc exposes the provider session');
assertSame('in_progress', $view['status'] ?? null, 'the case is in_progress after the session starts');
assertSame('created', $view['provider']['status'] ?? null, 'the provider observation is "created"');
assertSame($collectionUrl, $view['provider']['collection_url'] ?? null, 'the stored collection URL matches the response');

$mockBase = $mockUrl . '/api/sessions/' . rawurlencode($runtimeId);

$emit = expectStatus(
    200,
    postJson($mockBase . '/events', ['action' => 'approved']),
    'kyc-mock delivers the approved event to the backend webhook'
);
assertTrue(($emit['ok'] ?? false) === true, 'the backend accepted the signed webhook');
assertSame(200, $emit['event']['delivery']['http_status'] ?? null, 'the webhook responded with HTTP 200');
assertSame(false, $emit['event']['delivery']['body']['duplicate'] ?? null, 'the first delivery is not a duplicate');

$view = expectStatus(200, apiJson('GET', '/api/v1/kyc', $customerPublicId), 'the provider observation is visible to the customer');
assertSame('pending_verification', $view['status'] ?? null, 'a provider approval sends the case to manual review, never to approved');
assertSame('approved', $view['provider']['status'] ?? null, 'the provider status is stored as evidence');
assertTrue(!empty($view['provider']['last_event_at']), 'the provider event timestamp is recorded');

$countEvents = static function (PDO $pdo, int $caseId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM kyc_provider_events WHERE kyc_case_id = ?');
    $stmt->execute([$caseId]);

    return (int) $stmt->fetchColumn();
};
$countAudits = static function (PDO $pdo, string $casePublicId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE action = 'kyc.provider.event_processed' AND entity_type = 'kyc_case' AND entity_id = ?"
    );
    $stmt->execute([$casePublicId]);

    return (int) $stmt->fetchColumn();
};

$eventsBefore = $countEvents($pdo, $caseId);
$auditsBefore = $countAudits($pdo, $casePublicId);

$resend = expectStatus(
    200,
    postJson($mockBase . '/events', ['action' => 'resend_last']),
    'kyc-mock replays the last event with its original identifier'
);
assertSame(true, $resend['event']['delivery']['body']['duplicate'] ?? null, 'the replay is reported as a duplicate');
assertSame($eventsBefore, $countEvents($pdo, $caseId), 'a duplicate does not create a second provider event row');
assertSame($auditsBefore, $countAudits($pdo, $casePublicId), 'a duplicate does not create a second audit entry');

$quarantine = expectStatus(
    200,
    postJson($mockBase . '/events', ['action' => 'unknown_state']),
    'kyc-mock emits an unsupported provider state'
);
assertTrue(($quarantine['event']['delivery']['ok'] ?? false) === true, 'an unsupported state is acknowledged, not rejected');
$quarantinedEventId = (string) ($quarantine['event']['id'] ?? '');
$outcome = $pdo->prepare('SELECT outcome FROM kyc_provider_events WHERE provider_event_id = ?');
$outcome->execute([$quarantinedEventId]);
assertSame('quarantined', $outcome->fetchColumn(), 'the unsupported state is quarantined as an observation');

$view = expectStatus(200, apiJson('GET', '/api/v1/kyc', $customerPublicId), 'a quarantined event does not change the case');
assertSame('pending_verification', $view['status'] ?? null, 'the case keeps its internal status');

$directBody = json_encode([
    'id' => uuid(),
    'eventName' => 'workflow.completed',
    'workflowRuntimeId' => $runtimeId,
    'timestamp' => gmdate('c'),
    'state' => 'approved',
    'data' => ['decision' => ['status' => 'approved']],
], JSON_THROW_ON_ERROR);
$signature = 'sha256=' . hash_hmac('sha256', $directBody, $webhookSecret);

$direct = expectStatus(
    200,
    httpRequest('POST', $baseUrl . '/api/v1/kyc/webhooks/ballerine', [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Authorization: ' . $webhookSecret,
        'X-Ballerine-Signature: ' . $signature,
    ], $directBody),
    'a directly signed webhook is accepted (HMAC path)'
);
assertSame(true, $direct['received'] ?? null, 'the direct webhook is processed');
assertSame(false, $direct['duplicate'] ?? null, 'the direct webhook is new');

$directReplay = expectStatus(
    200,
    httpRequest('POST', $baseUrl . '/api/v1/kyc/webhooks/ballerine', [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Authorization: ' . $webhookSecret,
        'X-Ballerine-Signature: ' . $signature,
    ], $directBody),
    'the same signed body is replayed at the HTTP level'
);
assertSame(true, $directReplay['duplicate'] ?? null, 'the replayed body is idempotent at the webhook boundary');

expectStatus(
    403,
    httpRequest('POST', $baseUrl . '/api/v1/kyc/webhooks/ballerine', [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Authorization: ' . $webhookSecret,
        'X-Ballerine-Signature: sha256=' . str_repeat('0', 64),
    ], $directBody),
    'a tampered signature is rejected'
);

expectStatus(
    403,
    httpRequest('POST', $baseUrl . '/api/v1/kyc/webhooks/ballerine', [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Authorization: not-the-shared-secret',
    ], $directBody),
    'a wrong shared secret is rejected'
);

$staleBody = json_encode([
    'id' => uuid(),
    'eventName' => 'workflow.state_changed',
    'workflowRuntimeId' => $runtimeId,
    'timestamp' => gmdate('c', time() - 86400),
    'state' => 'in_progress',
], JSON_THROW_ON_ERROR);
expectStatus(
    403,
    httpRequest('POST', $baseUrl . '/api/v1/kyc/webhooks/ballerine', [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Authorization: ' . $webhookSecret,
        'X-Ballerine-Signature: sha256=' . hash_hmac('sha256', $staleBody, $webhookSecret),
    ], $staleBody),
    'a stale webhook timestamp is rejected'
);

$adminList = expectStatus(200, apiJson('GET', '/api/v1/admin/kyc', $adminPublicId), 'the admin can list KYC cases');
$adminCase = null;
foreach ($adminList['cases'] ?? [] as $case) {
    if (($case['public_id'] ?? null) === $casePublicId) {
        $adminCase = $case;
        break;
    }
}
assertTrue($adminCase !== null, 'the admin list contains the CI case');
assertSame('approved', $adminCase['provider_status'] ?? null, 'the admin sees the provider evidence');
assertSame(0, (int) ($adminCase['document_count'] ?? -1), 'the case has no local documents: approval relies on provider evidence');

$review = expectStatus(
    200,
    apiJson('POST', '/api/v1/admin/kyc/' . $casePublicId . '/review', $adminPublicId, ['decision' => 'approved']),
    'the admin approves the case using provider evidence only'
);
assertSame('approved', $review['status'] ?? null, 'the final decision is approved');

$revision = expectStatus(
    200,
    postJson($mockBase . '/events', ['action' => 'revision']),
    'kyc-mock emits a provider event after the final decision'
);
assertTrue(($revision['event']['delivery']['ok'] ?? false) === true, 'the post-decision event is still acknowledged');

$view = expectStatus(200, apiJson('GET', '/api/v1/kyc', $customerPublicId), 'the finalized case is still readable');
assertSame('approved', $view['status'] ?? null, 'a provider event never reopens an approved case');
assertSame('revision', $view['provider']['status'] ?? null, 'the newer provider observation is stored as evidence');

expectStatus(
    422,
    apiJson('POST', '/api/v1/kyc/session', $customerPublicId, ['locale' => 'es']),
    'a finalized case cannot start a new provider session'
);

echo "\nAll Ballerine KYC smoke checks passed.\n";
