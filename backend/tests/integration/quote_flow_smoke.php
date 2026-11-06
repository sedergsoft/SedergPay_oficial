<?php

declare(strict_types=1);

/**
 * PostgreSQL + HTTP integration smoke test for quote and remittance creation.
 * Run after migrations, with the Yii development server listening on :8080.
 */

$dsn = getenv('SEDERG_DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=sederg_pay';
$pdo = new PDO($dsn, getenv('SEDERG_DB_USER') ?: 'sederg_app', getenv('SEDERG_DB_PASSWORD') ?: '', [
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
         VALUES (?,?,?,?,?,?) RETURNING id'
    );
    $stmt->execute([$userPublicId, 'ci-quote-flow@sederg.invalid', password_hash('CI-only-password', PASSWORD_DEFAULT), 'active', 'es', $now]);
    $userId = (int) $stmt->fetchColumn();
} else {
    $userId = (int) $userId;
    $pdo->prepare("UPDATE users SET status='active', email_verified_at=? WHERE id=?")->execute([$now, $userId]);
}

$pdo->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$userId]);

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


function apiGet(string $path): array
{
    global $userPublicId;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Accept: */*',
                'X-User-Public-Id: ' . $userPublicId,
            ]),
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
    return [$status, is_string($body) ? json_decode($body, true) : null];
}


function apiPut(string $path, array $payload): array
{
    global $userPublicId;
    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-User-Public-Id: ' . $userPublicId,
            ]),
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 20,
        ],
    ]);
    $body = file_get_contents('http://127.0.0.1:8080' . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) $status = (int) $m[1];
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
[$viewStatus, $savedQuote] = apiGet('/api/v1/quotes/' . $quote['public_id']);
if ($viewStatus !== 200 || ($savedQuote['fee_mode'] ?? null) !== 'add_to_send') {
    fwrite(STDERR, 'Quote view diagnostic: HTTP ' . $viewStatus . ' body=' . json_encode($savedQuote) . "\\n");
}
assertTrue($viewStatus === 200 && ($savedQuote['fee_mode'] ?? null) === 'add_to_send', 'GET quote returns the persisted commission mode');
assertTrue(($savedQuote['receive_amount'] ?? null) === '25000.00', 'GET quote returns persisted RUB receive amount');

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
[$detailStatus, $remittanceDetail] = apiGet('/api/v1/remittances/' . $remittance['public_id']);
assertTrue($detailStatus === 200 && ($remittanceDetail['direction'] ?? null) === 'cu_to_ru', 'GET remittance detail returns the persisted corridor');
assertTrue(($remittanceDetail['fee_amount'] ?? null) === '7500.00', 'remittance detail preserves the calculated commission snapshot');

[$status, $reused] = apiRequest('/api/v1/remittances', [
    'quote_public_id' => $quote['public_id'],
    'beneficiary_public_id' => $beneficiaryPublicId,
], 'ci-remittance-create-20261103');
assertTrue($status === 200 && ($reused['public_id'] ?? null) === ($remittance['public_id'] ?? null), 'idempotent retry returns the same remittance without duplicating it');


[$accountStatus, $account] = apiRequest('/api/v1/admin/funding-accounts', [
    'country_code' => 'CU',
    'currency_code' => 'CUP',
    'bank_code' => 'banco_metropolitano',
    'bank_name' => 'Banco Metropolitano (sandbox)',
    'account_holder_name' => 'SEDERG_PAY',
    'account_identifier' => '0123456789012345',
    'routing_identifier' => '123456789',
    'environment' => 'sandbox',
    'methods' => ['cu_bank_transfer', 'cu_instant_qr'],
], 'ci-funding-account-create-20261103');
assertTrue($accountStatus === 201, 'admin can create a SEDERG_PAY Cuba sandbox receiving account');
assertTrue(($account['account_holder_name'] ?? null) === 'SEDERG_PAY', 'receiving account holder is fixed to SEDERG_PAY');
assertTrue(($account['account_mask'] ?? '') !== '0123456789012345', 'API returns a masked account identifier');
$accountRow = $pdo->query("SELECT account_identifier_ciphertext,account_mask,routing_identifier_ciphertext,routing_mask FROM platform_funding_accounts WHERE public_id=" . $pdo->quote($account['public_id']))->fetch();
assertTrue($accountRow && $accountRow['account_identifier_ciphertext'] !== '0123456789012345' && $accountRow['account_mask'] !== '0123456789012345', 'account identifier is encrypted at rest and masked');
assertTrue($accountRow['routing_identifier_ciphertext'] !== '123456789' && $accountRow['routing_mask'] !== '123456789', 'bank routing identifier is encrypted at rest and masked');

[$methodStatus] = apiPut('/api/v1/admin/funding-methods/cu_bank_transfer', ['is_active' => true]);
assertTrue($methodStatus === 200, 'admin can activate bank transfer after account setup');
[$qrMethodStatus] = apiPut('/api/v1/admin/funding-methods/cu_instant_qr', ['is_active' => true]);
assertTrue($qrMethodStatus === 200, 'admin can activate sandbox QR after account setup');
[$methodsStatus, $availableMethods] = apiGet('/api/v1/funding-methods/CU');
assertTrue($methodsStatus === 200 && count($availableMethods['methods'] ?? []) === 2, 'sender sees only enabled funding methods with an active matching account');

[$depositStatus, $deposit] = apiRequest('/api/v1/remittances/' . $remittance['public_id'] . '/deposit', ['method_code' => 'cu_bank_transfer'], 'ci-deposit-bank-transfer-20261103');
assertTrue($depositStatus === 201, 'sender can create a bank-transfer deposit instruction');
assertTrue(($deposit['qr_payload'] ?? null) === null && ($deposit['bank_name'] ?? null) === 'Banco Metropolitano (sandbox)', 'bank transfer returns bank details without an invalid generic QR');
[$detailStatus, $paidInstructions] = apiGet('/api/v1/remittances/' . $remittance['public_id']);
assertTrue($detailStatus === 200 && ($paidInstructions['payment_instructions']['account_holder_name'] ?? null) === 'SEDERG_PAY', 'remittance detail preserves SEDERG_PAY account snapshot');
assertTrue(($paidInstructions['payment_instructions']['reference'] ?? null) === $deposit['payment_reference'], 'remittance detail exposes the unique payment reference');

[$retryDepositStatus, $retryDeposit] = apiRequest('/api/v1/remittances/' . $remittance['public_id'] . '/deposit', ['method_code' => 'cu_bank_transfer'], 'ci-deposit-bank-transfer-20261103');
assertTrue($retryDepositStatus === 200 && ($retryDeposit['public_id'] ?? null) === $deposit['public_id'], 'deposit idempotency retry returns the same payment instruction');

[$deactivateStatus] = apiPut('/api/v1/admin/funding-accounts/' . $account['public_id'], ['status' => 'inactive']);
assertTrue($deactivateStatus === 200, 'admin can deactivate an old receiving account');
[$noMethodsStatus, $noMethods] = apiGet('/api/v1/funding-methods/CU');
assertTrue($noMethodsStatus === 200 && count($noMethods['methods'] ?? []) === 0, 'inactive receiving account is excluded from new payment choices');
[$historicStatus, $historicDetail] = apiGet('/api/v1/remittances/' . $remittance['public_id']);
assertTrue($historicStatus === 200 && ($historicDetail['payment_instructions']['account_mask'] ?? null) === $account['account_mask'], 'deactivating an account does not alter historical payment instructions');
[$reactivateStatus] = apiPut('/api/v1/admin/funding-accounts/' . $account['public_id'], ['status' => 'active']);
assertTrue($reactivateStatus === 200, 'admin can reactivate a sandbox receiving account');

[$qrQuoteStatus, $qrQuote] = $createQuote('250000.00');
assertTrue($qrQuoteStatus === 201, 'second quote is available for QR payment test');
[$qrRemittanceStatus, $qrRemittance] = apiRequest('/api/v1/remittances', [
    'quote_public_id' => $qrQuote['public_id'],
    'beneficiary_public_id' => $beneficiaryPublicId,
], 'ci-remittance-qr-create-20261103');
assertTrue($qrRemittanceStatus === 201, 'second remittance created for QR test');
[$qrDepositStatus, $qrDeposit] = apiRequest('/api/v1/remittances/' . $qrRemittance['public_id'] . '/deposit', ['method_code' => 'cu_instant_qr'], 'ci-deposit-qr-20261103');
assertTrue($qrDepositStatus === 201, 'sender can create QR payment instruction for the QR method');
assertTrue(($qrDeposit['qr_type'] ?? null) === 'sedergpay_sandbox' && str_starts_with((string) ($qrDeposit['qr_payload'] ?? ''), 'sedergpay-sandbox://cuba-instant-qr?'), 'QR payload is generated by the selected sandbox QR method only');
[$qrDetailStatus, $qrDetail] = apiGet('/api/v1/remittances/' . $qrRemittance['public_id']);
assertTrue($qrDetailStatus === 200 && str_starts_with((string) ($qrDetail['payment_qr_payload'] ?? ''), 'sedergpay-sandbox://cuba-instant-qr?'), 'remittance detail returns selected method QR payload');


$cuBeneficiaryPublicId = '33333333-3333-4333-8333-333333333333';
$stmt = $pdo->prepare('SELECT id FROM beneficiaries WHERE public_id=?');
$stmt->execute([$cuBeneficiaryPublicId]);
$cuBeneficiaryId = $stmt->fetchColumn();
if (!$cuBeneficiaryId) {
    $stmt = $pdo->prepare(
        'INSERT INTO beneficiaries (public_id,sender_user_id,country_id,first_name,last_name,payout_method_code,status)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([$cuBeneficiaryPublicId, $userId, $countryIds['CU'], 'CI', 'Cuba Beneficiary', 'cash_pickup', 'active']);
}
[$ruAccountStatus, $ruAccount] = apiRequest('/api/v1/admin/funding-accounts', [
    'country_code' => 'RU',
    'currency_code' => 'RUB',
    'bank_code' => 'sberbank',
    'bank_name' => 'Sberbank (sandbox)',
    'account_holder_name' => 'SEDERG_PAY',
    'account_identifier' => '40702810900000000001',
    'routing_identifier' => '044525225',
    'environment' => 'sandbox',
    'methods' => ['ru_sbp_qr'],
], 'ci-ru-sbp-account-create-20261103');
assertTrue($ruAccountStatus === 201, 'admin can create a SEDERG_PAY Russian sandbox account for SBP C2B');
[$ruMethodStatus] = apiPut('/api/v1/admin/funding-methods/ru_sbp_qr', ['is_active' => true]);
assertTrue($ruMethodStatus === 200, 'admin can activate SBP C2B sandbox QR after account setup');
[$ruMethodsStatus, $ruMethods] = apiGet('/api/v1/funding-methods/RU');
assertTrue($ruMethodsStatus === 200 && count($ruMethods['methods'] ?? []) === 1 && ($ruMethods['methods'][0]['code'] ?? null) === 'ru_sbp_qr', 'Russia payment catalogue exposes SBP C2B separately from bank transfer');

[$ruRemittanceStatus, $ruRemittance] = apiRequest('/api/v1/remittances', [
    'quote_public_id' => $ruToCu['public_id'],
    'beneficiary_public_id' => $cuBeneficiaryPublicId,
], 'ci-ru-cu-sbp-remittance-20261103');
assertTrue($ruRemittanceStatus === 201, 'RU→CU remittance can be created for SBP payment');
[$sbpDepositStatus, $sbpDeposit] = apiRequest('/api/v1/remittances/' . $ruRemittance['public_id'] . '/deposit', ['method_code' => 'ru_sbp_qr'], 'ci-ru-sbp-deposit-20261103');
assertTrue($sbpDepositStatus === 201, 'RU sender can create SBP C2B QR payment instruction');
assertTrue(($sbpDeposit['qr_type'] ?? null) === 'sbp_c2b_sandbox' && str_starts_with((string) ($sbpDeposit['qr_payload'] ?? ''), 'sedergpay-sandbox://sbp-c2b?'), 'SBP QR is generated by the SBP-specific sandbox provider');
assertTrue(str_starts_with((string) ($sbpDeposit['provider_payment_id'] ?? ''), 'SBP-SANDBOX-'), 'SBP sandbox provider returns a distinct provider payment identifier');

echo "All quote/remittance HTTP integration checks passed.\n";
