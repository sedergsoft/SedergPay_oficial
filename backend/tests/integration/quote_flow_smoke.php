<?php

declare(strict_types=1);

/**
 * MySQL + HTTP integration smoke test for quote and remittance creation.
 * Run after migrations, with the Yii development server listening on :8080.
 */

$dsn = getenv('SEDERG_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=sederg_pay';
$pdo = new PDO($dsn, getenv('SEDERG_DB_USER') ?: 'root', getenv('SEDERG_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$userPublicId = '11111111-1111-4111-8111-111111111111';
$beneficiaryPublicId = '22222222-2222-4222-8222-222222222222';
$now = gmdate('Y-m-d H:i:s');

$countryIds = [];
foreach ($pdo->query("SELECT id, iso2 FROM countries WHERE iso2 IN ('RU','CU')") as $row) {
    $countryIds[$row['iso2']] = (int) $row['id'];
}
assertTrue(isset($countryIds['RU'], $countryIds['CU']), 'Russia and Cuba catalogs exist');

$stmt = $pdo->prepare('SELECT id FROM users WHERE public_id = ?');
$stmt->execute([$userPublicId]);
$userId = $stmt->fetchColumn();
if (!$userId) {
    $stmt = $pdo->prepare(
        'INSERT INTO users (public_id,email,password_hash,status,preferred_language,email_verified_at)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$userPublicId, 'ci-quote-flow@sederg.invalid', password_hash('CI-only-password', PASSWORD_DEFAULT), 'active', 'es', $now]);
    $userId = (int) $pdo->lastInsertId();
} else {
    $userId = (int) $userId;
    $pdo->prepare("UPDATE users SET status='active', email_verified_at=? WHERE id=?")->execute([$now, $userId]);
}

$stmt = $pdo->prepare('SELECT id FROM beneficiaries WHERE public_id = ?');
$stmt->execute([$beneficiaryPublicId]);
$beneficiaryId = $stmt->fetchColumn();
if (!$beneficiaryId) {
    $stmt = $pdo->prepare(
        'INSERT INTO beneficiaries
         (public_id,sender_user_id,country_id,first_name,last_name,payout_method_code,payout_provider,payout_account_last4,status)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$beneficiaryPublicId, $userId, $countryIds['RU'], 'CI', 'Beneficiary', 'bank_account', 'Test Russian Bank', '4321', 'active']);
} else {
    $pdo->prepare(
        'UPDATE beneficiaries SET sender_user_id=?,country_id=?,payout_method_code=?,payout_provider=?,payout_account_last4=?,status=? WHERE id=?'
    )->execute([$userId, $countryIds['RU'], 'bank_account', 'Test Russian Bank', '4321', 'active', $beneficiaryId]);
}

$pdo->prepare(
    "INSERT INTO exchange_rates (base_currency,quote_currency,rate,source,valid_from,created_by_user_id)
     VALUES ('RUB','CUP','10.0000000000','bcc_official',?,NULL)"
)->execute([$now]);

function apiRequest(string $path, array $payload, string $key): array
{
    global $userPublicId;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-User-Public-Id: ' . $userPublicId,
                'Idempotency-Key: ' . $key,
            ]),
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 20,
        ],
    ]);
    $body = file_get_contents('http://127.0.0.1:8080' . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, is_string($body) ? json_decode($body, true) : null, $body];
}

function quotePayload(string $amount, string $mode = 'add_to_send'): array
{
    global $beneficiaryPublicId;
    return [
        'source_country_code' => 'CU',
        'destination_country_code' => 'RU',
        'send_amount' => $amount,
        'send_currency' => 'CUP',
        'receive_currency' => 'RUB',
        'payout_method_code' => 'bank_account',
        'fee_mode' => $mode,
        'beneficiary_public_id' => $beneficiaryPublicId,
    ];
}

$counter = 0;
$createQuote = static function (string $amount, string $mode = 'add_to_send') use (&$counter): array {
    [$status, $body, $raw] = apiRequest('/api/v1/quotes', quotePayload($amount, $mode), 'ci-quote-' . (++$counter) . '-20261103');
    return [$status, $body, $raw];
};

[$status, $quote] = $createQuote('250000.00', 'add_to_send');
assertTrue($status === 201, 'CU→RU quote with add_to_send returns HTTP 201');
assertTrue(($quote['limits']['currency'] ?? null) === 'RUB', 'CU→RU quote reports RUB as limit currency');
assertTrue(($quote['limits']['evaluated_amount'] ?? null) === '25000.00', '250000 CUP is evaluated as 25000 RUB');
assertTrue(($quote['receive_amount'] ?? null) === '25000.00', 'gross receive amount is 25000 RUB');
assertTrue(($quote['total_debit_amount'] ?? null) === '257500.00', 'add_to_send commission is included in sender debit');

[$status, $deductQuote] = $createQuote('250000.00', 'deduct_from_receive');
assertTrue($status === 201, 'CU→RU quote with deduct_from_receive returns HTTP 201');
assertTrue(($deductQuote['limits']['evaluated_amount'] ?? null) === '25000.00', 'deduct_from_receive uses the same pre-commission RUB principal');
assertTrue(($deductQuote['receive_amount'] ?? null) === '24250.00', 'deduct_from_receive deducts 3 percent from beneficiary amount');
assertTrue(($deductQuote['total_debit_amount'] ?? null) === '250000.00', 'deduct_from_receive leaves sender debit unchanged');

[$status, $minimumQuote] = $createQuote('150000.00');
assertTrue($status === 201 && ($minimumQuote['limits']['evaluated_amount'] ?? null) === '15000.00', 'exact minimum of 15000 RUB is accepted');
[$status, $maximumQuote] = $createQuote('500000.00');
assertTrue($status === 201 && ($maximumQuote['limits']['evaluated_amount'] ?? null) === '50000.00', 'exact maximum of 50000 RUB is accepted');

[$status] = $createQuote('149999.99');
assertTrue($status === 422, 'amount below 15000 RUB is rejected with HTTP 422');
[$status] = $createQuote('500000.01');
assertTrue($status === 422, 'amount above 50000 RUB is rejected with HTTP 422');

[$status] = apiRequest('/api/v1/quotes', [
    'source_country_code' => 'CU',
    'destination_country_code' => 'RU',
    'send_amount' => '250000.00',
    'send_currency' => 'RUB',
    'receive_currency' => 'CUP',
    'payout_method_code' => 'bank_account',
], 'ci-quote-currency-mismatch-20261103');
assertTrue($status === 422, 'currency pair inconsistent with CU→RU is rejected');

[$status, $ruToCu] = apiRequest('/api/v1/quotes', [
    'source_country_code' => 'RU',
    'destination_country_code' => 'CU',
    'send_amount' => '25000.00',
    'send_currency' => 'RUB',
    'receive_currency' => 'CUP',
    'payout_method_code' => 'cash_pickup',
    'fee_mode' => 'add_to_send',
], 'ci-quote-ru-cu-20261103');
assertTrue($status === 201, 'RU→CU quote remains available');
assertTrue(($ruToCu['limits']['evaluated_amount'] ?? null) === '25000.00', 'RU→CU continues to evaluate sent principal in RUB');

[$status, $remittance] = apiRequest('/api/v1/remittances', [
    'quote_public_id' => $quote['public_id'],
    'beneficiary_public_id' => $beneficiaryPublicId,
], 'ci-remittance-create-20261103');
assertTrue($status === 201, 'valid CU→RU quote can be converted into a remittance');
assertTrue(($remittance['direction'] ?? null) === 'cu_to_ru', 'created remittance direction is cu_to_ru');
assertTrue(($remittance['send_currency'] ?? null) === 'CUP' && ($remittance['receive_currency'] ?? null) === 'RUB', 'remittance persists CUP→RUB currency pair');

[$status, $reused] = apiRequest('/api/v1/remittances', [
    'quote_public_id' => $quote['public_id'],
    'beneficiary_public_id' => $beneficiaryPublicId,
], 'ci-remittance-create-20261103');
assertTrue($status === 201 && ($reused['public_id'] ?? null) === ($remittance['public_id'] ?? null), 'idempotent retry returns the same remittance');

echo "All quote/remittance HTTP integration checks passed.\n";
