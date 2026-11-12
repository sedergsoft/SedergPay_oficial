<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Payment\FundingPaymentProviderFactory;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServiceUnavailableHttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\db\JsonExpression;

final class FundingController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionMethods(string $countryCode): array
    {
        $this->authenticatedUserId();
        $country = strtoupper($countryCode);
        if (!in_array($country, ['RU', 'CU'], true)) {
            throw new BadRequestHttpException('Unsupported funding country');
        }
        $rows = Yii::$app->db->createCommand(
            'SELECT m.code,m.name,m.method_type,m.provider_code,m.supports_dynamic_qr,m.requires_reference
             FROM {{%funding_payment_methods}} m
             INNER JOIN {{%countries}} c ON c.id=m.country_id
             WHERE c.iso2=:country AND m.is_active = TRUE
               AND EXISTS (
                 SELECT 1 FROM {{%funding_account_methods}} fam
                 INNER JOIN {{%platform_funding_accounts}} a ON a.id=fam.funding_account_id
                 WHERE fam.payment_method_code=m.code AND a.status=:active
                   AND a.environment=:environment AND a.account_holder_name=:holder AND a.currency_code=m.currency_code AND a.country_id=m.country_id
                   AND (a.valid_from IS NULL OR a.valid_from<=CURRENT_TIMESTAMP)
                   AND (a.valid_until IS NULL OR a.valid_until>CURRENT_TIMESTAMP)
               )
             ORDER BY m.name',
            [':country' => $country, ':active' => 'active', ':environment' => 'sandbox', ':holder' => 'SEDERG_PAY']
        )->queryAll();
        return ['methods' => $rows, 'environment' => 'sandbox'];
    }

    public function actionAdminMethods(): array
    {
        $this->requireAdmin();
        $rows = Yii::$app->db->createCommand(
            'SELECT m.code,m.name,m.method_type,m.provider_code,m.supports_dynamic_qr,m.requires_reference,m.is_active,
                    c.iso2 AS country_code,m.currency_code,m.configuration
             FROM {{%funding_payment_methods}} m
             INNER JOIN {{%countries}} c ON c.id=m.country_id ORDER BY c.iso2,m.name'
        )->queryAll();
        foreach ($rows as &$row) {
            if (array_key_exists('configuration', $row)) {
                $cfg = $row['configuration'];
                if (is_string($cfg) && $cfg !== '') {
                    $decoded = json_decode($cfg, true);
                    if (is_string($decoded)) {
                        $decoded2 = json_decode($decoded, true);
                        $decoded = is_array($decoded2) ? $decoded2 : $decoded;
                    }
                    $row['configuration'] = is_array($decoded) ? $decoded : $cfg;
                } elseif ($cfg === null) {
                    $row['configuration'] = null;
                }
            }
        }
        unset($row);
        return ['methods' => $rows];
    }

    public function actionAdminUpdateMethod(string $code): array
    {
        $userId = $this->requireAdmin();
        $payload = Yii::$app->request->bodyParams;
        if (!array_key_exists('is_active', $payload) || !is_bool($payload['is_active'])) {
            throw new BadRequestHttpException('is_active must be a boolean');
        }
        $db = Yii::$app->db;
        $configuration = null;
        if (array_key_exists('configuration', $payload) && is_array($payload['configuration'])) {
            $configuration = $payload['configuration'];
        }
        $method = $db->createCommand('SELECT code,is_active FROM {{%funding_payment_methods}} WHERE code=:code', [':code' => $code])->queryOne();
        if (!$method) {
            throw new NotFoundHttpException('Funding method not found');
        }
        if ($payload['is_active']) {
            $accounts = (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%funding_account_methods}} fam
                 INNER JOIN {{%platform_funding_accounts}} a ON a.id=fam.funding_account_id
                 INNER JOIN {{%funding_payment_methods}} m ON m.code=fam.payment_method_code
                 WHERE fam.payment_method_code=:code AND a.status=:status AND a.environment=:env
                   AND a.account_holder_name=:holder AND a.currency_code=m.currency_code AND a.country_id=m.country_id',
                [':code' => $code, ':status' => 'active', ':env' => 'sandbox', ':holder' => 'SEDERG_PAY']
            )->queryScalar();
            if ($accounts < 1) {
                throw new UnprocessableEntityHttpException('Configure at least one active SEDERG_PAY sandbox account for this method first');
            }
        }
        $updateColumns = ['is_active' => $payload['is_active']];
        if ($configuration !== null) {
            $updateColumns['configuration'] = json_encode($configuration, JSON_THROW_ON_ERROR);
        }
        $db->createCommand()->update('{{%funding_payment_methods}}', $updateColumns, ['code' => $code])->execute();
        $this->audit($userId, 'funding_method.updated', 'funding_method', $code, ['is_active' => $payload['is_active'], 'configuration' => $configuration]);
        $response = ['code' => $code, 'is_active' => $payload['is_active']];
        if ($configuration !== null) {
            $response['configuration'] = $configuration;
        }
        return $response;
    }

    public function actionAdminAccounts(): array
    {
        $this->requireAdmin();
        return ['accounts' => Yii::$app->db->createCommand(
            "SELECT a.public_id,a.country_id,c.iso2 AS country_code,a.currency_code,a.bank_code,a.bank_name,
                    a.account_holder_name,a.legal_entity_name,a.account_type,a.account_mask,a.routing_mask,
                    a.environment,a.status,a.valid_from,a.valid_until,a.notes,a.created_at,a.updated_at,
                    STRING_AGG(fam.payment_method_code, ',' ORDER BY fam.payment_method_code) AS methods
             FROM {{%platform_funding_accounts}} a
             INNER JOIN {{%countries}} c ON c.id=a.country_id
             LEFT JOIN {{%funding_account_methods}} fam ON fam.funding_account_id=a.id
             GROUP BY a.id, c.iso2 ORDER BY a.created_at DESC"
        )->queryAll()];
    }

    public function actionAdminCreateAccount(): array
    {
        $userId = $this->requireAdmin();
        $p = Yii::$app->request->bodyParams;
        foreach (['country_code','currency_code','bank_code','bank_name','account_identifier','methods'] as $field) {
            if (!isset($p[$field])) throw new BadRequestHttpException("{$field} is required");
        }
        $countryCode = strtoupper((string) $p['country_code']);
        $currency = strtoupper((string) $p['currency_code']);
        $environment = (string) ($p['environment'] ?? 'sandbox');
        if (!in_array($countryCode, ['RU','CU'], true) || !in_array($environment, ['sandbox'], true)) {
            throw new UnprocessableEntityHttpException('Only RU/CU sandbox accounts can be configured in this phase');
        }
        if ($currency !== ($countryCode === 'RU' ? 'RUB' : 'CUP')) {
            throw new UnprocessableEntityHttpException('Currency does not match the selected country');
        }
        $holder = strtoupper(trim((string) ($p['account_holder_name'] ?? 'SEDERG_PAY')));
        if ($holder !== 'SEDERG_PAY') {
            throw new UnprocessableEntityHttpException('Receiving accounts must be registered under SEDERG_PAY');
        }
        if (!is_array($p['methods']) || count($p['methods']) < 1) {
            throw new BadRequestHttpException('Select at least one compatible funding method');
        }
        $identifier = preg_replace('/\s+/', '', (string) $p['account_identifier']);
        if (strlen($identifier) < 4 || strlen($identifier) > 160) {
            throw new UnprocessableEntityHttpException('Invalid account identifier');
        }
        $key = (string) getenv('SEDERG_ACCOUNT_ENCRYPTION_KEY');
        if (strlen($key) < 32) {
            throw new ServiceUnavailableHttpException('Configure SEDERG_ACCOUNT_ENCRYPTION_KEY (at least 32 characters) before saving bank account details');
        }
        $db = Yii::$app->db;
        $countryId = $db->createCommand('SELECT id FROM {{%countries}} WHERE iso2=:code AND is_active = TRUE', [':code' => $countryCode])->queryScalar();
        if (!$countryId) throw new UnprocessableEntityHttpException('Country is not configured');
        $methodParams = [':country' => $countryCode, ':currency' => $currency];
        $methodPlaceholders = [];
        foreach (array_values(array_unique($p['methods'])) as $index => $methodCode) {
            if (!is_string($methodCode) || !preg_match('/^[a-z0-9_]{2,40}$/', $methodCode)) {
                throw new BadRequestHttpException('Invalid funding method code');
            }
            $placeholder = ':method_' . $index;
            $methodPlaceholders[] = $placeholder;
            $methodParams[$placeholder] = $methodCode;
        }
        $methodRows = $db->createCommand(
            'SELECT m.code FROM {{%funding_payment_methods}} m INNER JOIN {{%countries}} c ON c.id=m.country_id
             WHERE c.iso2=:country AND m.currency_code=:currency AND m.code IN (' . implode(',', $methodPlaceholders) . ')',
            $methodParams
        )->queryColumn();
        if (count($methodRows) !== count(array_unique($p['methods']))) {
            throw new UnprocessableEntityHttpException('One or more methods do not match the selected country/currency');
        }
        $publicId = $this->uuid();
        $mask = '••••' . substr(preg_replace('/\D/', '', $identifier) ?: $identifier, -4);
        $encrypted = Yii::$app->security->encryptByKey($identifier, $key);
        $routingIdentifier = preg_replace('/\\s+/', '', (string) ($p['routing_identifier'] ?? ''));
        $routingCiphertext = null;
        $routingMask = null;
        if ($routingIdentifier !== '') {
            if (strlen($routingIdentifier) > 160) {
                throw new UnprocessableEntityHttpException('Invalid bank routing identifier');
            }
            $routingCiphertext = base64_encode(Yii::$app->security->encryptByKey($routingIdentifier, $key));
            $routingMask = '••••' . substr(preg_replace('/\\D/', '', $routingIdentifier) ?: $routingIdentifier, -4);
        }
        $tx = $db->beginTransaction();
        try {
            $db->createCommand()->insert('{{%platform_funding_accounts}}', [
                'public_id' => $publicId, 'country_id' => (int) $countryId, 'currency_code' => $currency,
                'bank_code' => trim((string) $p['bank_code']), 'bank_name' => trim((string) $p['bank_name']),
                'account_holder_name' => 'SEDERG_PAY', 'legal_entity_name' => trim((string) ($p['legal_entity_name'] ?? '')) ?: null,
                'account_type' => (string) ($p['account_type'] ?? 'bank_account'),
                'account_identifier_ciphertext' => base64_encode($encrypted), 'account_mask' => $mask,
                'routing_identifier_ciphertext' => $routingCiphertext, 'routing_mask' => $routingMask,
                'environment' => 'sandbox', 'status' => 'active', 'notes' => trim((string) ($p['notes'] ?? '')) ?: null,
                'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
            ])->execute();
            $id = (int) $db->createCommand("SELECT currval(pg_get_serial_sequence('platform_funding_accounts', 'id'))")->queryScalar();
            foreach (array_unique($methodRows) as $method) {
                $db->createCommand()->insert('{{%funding_account_methods}}', ['funding_account_id' => $id, 'payment_method_code' => $method])->execute();
            }
            $this->audit($userId, 'funding_account.created', 'funding_account', $publicId, ['country' => $countryCode, 'currency' => $currency, 'bank' => $p['bank_code'], 'mask' => $mask]);
            $tx->commit();
        } catch (\Throwable $e) {
            if ($tx->isActive) $tx->rollBack();
            throw $e;
        }
        Yii::$app->response->statusCode = 201;
        return ['public_id' => $publicId, 'account_mask' => $mask, 'routing_mask' => $routingMask, 'account_holder_name' => 'SEDERG_PAY', 'status' => 'active', 'environment' => 'sandbox'];
    }

    public function actionAdminUpdateAccount(string $publicId): array
    {
        $userId = $this->requireAdmin();
        $p = Yii::$app->request->bodyParams;
        $status = $p['status'] ?? null;
        if (!in_array($status, ['active','inactive'], true)) throw new BadRequestHttpException('status must be active or inactive');
        $db = Yii::$app->db;
        $account = $db->createCommand('SELECT id,status FROM {{%platform_funding_accounts}} WHERE public_id=:id', [':id' => $publicId])->queryOne();
        if (!$account) throw new NotFoundHttpException('Funding account not found');
        if ($status === 'inactive') {
            $db->createCommand()->update('{{%platform_funding_accounts}}', ['status' => 'inactive', 'updated_by_user_id' => $userId], ['id' => $account['id']])->execute();
        } else {
            $holder = $db->createCommand('SELECT account_holder_name FROM {{%platform_funding_accounts}} WHERE id=:id', [':id' => $account['id']])->queryScalar();
            if ($holder !== 'SEDERG_PAY') throw new UnprocessableEntityHttpException('Account holder is not SEDERG_PAY');
            $db->createCommand()->update('{{%platform_funding_accounts}}', ['status' => 'active', 'updated_by_user_id' => $userId], ['id' => $account['id']])->execute();
        }
        $this->audit($userId, 'funding_account.status_changed', 'funding_account', $publicId, ['status' => $status]);
        return ['public_id' => $publicId, 'status' => $status];
    }

    public function actionCreateDeposit(string $publicId): array
    {
        $userId = $this->authenticatedUserId();
        $p = Yii::$app->request->bodyParams;
        $methodCode = (string) ($p['method_code'] ?? '');
        if ($methodCode === '') throw new BadRequestHttpException('method_code is required');
        $key = trim((string) Yii::$app->request->headers->get('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $key)) throw new BadRequestHttpException('A valid Idempotency-Key is required');
        $db = Yii::$app->db;
        $remittance = $db->createCommand(
            'SELECT r.id,r.reference,r.total_debit_amount,r.send_currency,r.status
             FROM {{%remittances}} r WHERE r.public_id=:public_id AND r.sender_user_id=:user_id LIMIT 1',
            [':public_id' => $publicId, ':user_id' => $userId]
        )->queryOne();
        if (!$remittance) throw new NotFoundHttpException('Remittance not found');
        if ($remittance['status'] !== 'awaiting_payment') throw new UnprocessableEntityHttpException('Remittance is not awaiting payment');
        $scope = "funding:deposit:user:{$userId}";
        $hash = hash('sha256', $publicId . '|' . $methodCode);
        $existing = $db->createCommand('SELECT request_hash,response_body FROM {{%idempotency_keys}} WHERE scope=:scope AND idempotency_key=:key AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)', [':scope' => $scope, ':key' => $key])->queryOne();
        if ($existing) {
            if (!hash_equals($existing['request_hash'], $hash)) throw new ConflictHttpException('Idempotency-Key was already used with another request');
            return json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
        }
        $account = $db->createCommand(
            'SELECT a.id,a.public_id,a.bank_code,a.bank_name,a.account_holder_name,a.account_mask,a.routing_mask,a.currency_code,a.environment,
                    m.code,m.name,m.method_type,m.supports_dynamic_qr,m.provider_code,m.configuration
             FROM {{%funding_payment_methods}} m
             INNER JOIN {{%funding_account_methods}} fam ON fam.payment_method_code=m.code
             INNER JOIN {{%platform_funding_accounts}} a ON a.id=fam.funding_account_id
             WHERE m.code=:method AND m.is_active = TRUE AND a.status=:status AND a.environment=:env
               AND a.account_holder_name=:holder AND a.currency_code=:currency
               AND (a.valid_from IS NULL OR a.valid_from<=CURRENT_TIMESTAMP)
               AND (a.valid_until IS NULL OR a.valid_until>CURRENT_TIMESTAMP)
             ORDER BY a.updated_at DESC LIMIT 1',
            [':method' => $methodCode, ':status' => 'active', ':env' => 'sandbox', ':holder' => 'SEDERG_PAY', ':currency' => $remittance['send_currency']]
        )->queryOne();
        if (!$account) throw new UnprocessableEntityHttpException('No active SEDERG_PAY account is configured for this payment method and currency');
        $connectionId = $db->createCommand("SELECT id FROM {{%bank_connections}} WHERE bank_code='simulator' AND environment='sandbox' AND status='active' LIMIT 1")->queryScalar();
        if (!$connectionId) throw new ServiceUnavailableHttpException('Sandbox payment simulator is not configured');
        $reference = 'PAY-' . strtoupper(bin2hex(random_bytes(5)));
        $depositId = $this->uuid();
        $expires = (new \DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
        $qrPayload = null;
        $qrType = 'none';
        $providerPaymentId = null;
        $payeePhone = '';
        if (filter_var($account['supports_dynamic_qr'], FILTER_VALIDATE_BOOLEAN) && $account['method_type'] === 'instant_qr') {
            if ($account['provider_code'] === 'sbp_c2b_sandbox') {
                $issued = (new FundingPaymentProviderFactory())->create((string) $account['provider_code'], (string) $account['environment'])->createDynamicQr(
                    (string) $remittance['total_debit_amount'],
                    (string) $remittance['send_currency'],
                    $reference,
                    $expires,
                    'SEDERG_PAY'
                );
                $qrPayload = $issued['payload'];
                $providerPaymentId = $issued['provider_payment_id'];
                $qrType = 'sbp_c2b_sandbox';
            } elseif ($account['provider_code'] === 'sbp_c2p_sandbox') {
                $configuration = json_decode((string) ($account['configuration'] ?? '{}'), true);
                if (!is_array($configuration)) {
                    $configuration = [];
                }
                $payeePhone = (string) preg_replace('/\D/', '', (string) ($configuration['payee_phone'] ?? ''));
                $purpose = trim((string) ($configuration['purpose'] ?? 'Fondeo SEDERG_PAY'));
                if ($payeePhone === '') {
                    throw new UnprocessableEntityHttpException('SBP C2P payee phone is not configured for this funding method');
                }
                $issued = (new FundingPaymentProviderFactory())->createPersonalQr('sbp_c2p_sandbox', 'sandbox')->createPersonalQr(
                    (string) $remittance['total_debit_amount'],
                    (string) $remittance['send_currency'],
                    $reference,
                    $expires,
                    'SEDERG_PAY',
                    $payeePhone,
                    $purpose
                );
                $qrPayload = $issued['payload'];
                $providerPaymentId = $issued['provider_payment_id'];
                $qrType = 'sbp_c2p_sandbox';
            } else {
                $qrPayload = 'sedergpay-sandbox://cuba-instant-qr?reference=' . rawurlencode($reference)
                    . '&amount=' . rawurlencode((string) $remittance['total_debit_amount'])
                    . '&currency=' . rawurlencode((string) $remittance['send_currency'])
                    . '&account=' . rawurlencode((string) $account['account_mask']);
                $qrType = 'sedergpay_sandbox';
            }
        }
        $snapshot = [
            'platform' => 'SEDERG_PAY', 'bank_code' => $account['bank_code'], 'bank_name' => $account['bank_name'],
            'account_holder_name' => 'SEDERG_PAY', 'account_mask' => $account['account_mask'], 'routing_mask' => $account['routing_mask'],
            'currency_code' => $remittance['send_currency'], 'amount' => $remittance['total_debit_amount'],
            'method_code' => $methodCode, 'method_name' => $account['name'], 'reference' => $reference,
            'environment' => 'sandbox', 'qr_type' => $qrType, 'provider_payment_id' => $providerPaymentId,
            'payee_phone' => $account['provider_code'] === 'sbp_c2p_sandbox' ? $payeePhone : null,
        ];
        $response = [
            'public_id' => $depositId, 'provider' => 'simulator', 'status' => 'pending',
            'expected_amount' => $remittance['total_debit_amount'], 'currency_code' => $remittance['send_currency'],
            'payment_reference' => $reference, 'expires_at' => (new \DateTimeImmutable($expires))->format(DATE_ATOM),
            'method_code' => $methodCode, 'method_name' => $account['name'], 'bank_name' => $account['bank_name'],
            'account_holder_name' => 'SEDERG_PAY', 'account_mask' => $account['account_mask'], 'routing_mask' => $account['routing_mask'],
            'qr_payload' => $qrPayload, 'qr_type' => $qrType, 'provider_payment_id' => $providerPaymentId,
            'payee_phone' => $account['provider_code'] === 'sbp_c2p_sandbox' ? $payeePhone : null,
        ];
        $tx = $db->beginTransaction();
        try {
            $active = (int) $db->createCommand("SELECT COUNT(*) FROM {{%deposit_requests}} WHERE remittance_id=:id AND status IN ('pending','confirmed') AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)", [':id' => $remittance['id']])->queryScalar();
            if ($active > 0) throw new ConflictHttpException('An active payment instruction already exists for this remittance');
            $db->createCommand()->insert('{{%deposit_requests}}', [
                'public_id' => $depositId, 'remittance_id' => $remittance['id'], 'bank_connection_id' => (int) $connectionId,
                'funding_account_id' => $account['id'], 'funding_method_code' => $methodCode,
                'account_snapshot' => new JsonExpression($snapshot), 'qr_payload' => $qrPayload, 'qr_expires_at' => $expires, 'provider_payment_id' => $providerPaymentId,
                'expected_amount' => $remittance['total_debit_amount'], 'currency_code' => $remittance['send_currency'],
                'payment_reference' => $reference, 'status' => 'pending', 'expires_at' => $expires,
            ])->execute();
            $db->createCommand()->insert('{{%idempotency_keys}}', [
                'scope' => $scope, 'idempotency_key' => $key, 'request_hash' => $hash,
                'response_status' => 201, 'response_body' => new JsonExpression($response),
                'resource_type' => 'deposit_request', 'resource_id' => $depositId,
                'expires_at' => $expires,
            ])->execute();
            $tx->commit();
        } catch (\Throwable $e) {
            if ($tx->isActive) $tx->rollBack();
            throw $e;
        }
        Yii::$app->response->statusCode = 201;
        return $response;
    }

    private function requireAdmin(): int
    {
        $auth = new UserAuthenticator(Yii::$app->db);
        $id = $auth->userId();
        $auth->requireRole('admin');
        return $id;
    }

    private function authenticatedUserId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function audit(int $userId, string $action, string $type, string $id, array $data): void
    {
        Yii::$app->db->createCommand()->insert('{{%audit_logs}}', [
            'actor_user_id' => $userId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
            'ip_address' => Yii::$app->request->userIP,
            'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
            'after_data' => new JsonExpression($data),
        ])->execute();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
