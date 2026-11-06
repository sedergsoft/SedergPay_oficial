<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Payment\IdempotencyConflictException;
use app\Infrastructure\Payment\PaymentIntentService;
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
             WHERE c.iso2=:country AND m.is_active=1
               AND EXISTS (
                 SELECT 1 FROM {{%funding_account_methods}} fam
                 INNER JOIN {{%platform_funding_accounts}} a ON a.id=fam.funding_account_id
                 WHERE fam.payment_method_code=m.code AND a.status=:active
                   AND a.environment=:environment AND a.account_holder_name=:holder AND a.currency_code=m.currency_code AND a.country_id=m.country_id
                   AND (a.valid_from IS NULL OR a.valid_from<=UTC_TIMESTAMP())
                   AND (a.valid_until IS NULL OR a.valid_until>UTC_TIMESTAMP())
               )
             ORDER BY m.name',
            [':country' => $country, ':active' => 'active', ':environment' => 'sandbox', ':holder' => 'SEDERG_PAY']
        )->queryAll();
        return ['methods' => $rows, 'environment' => 'sandbox'];
    }

    public function actionAdminMethods(): array
    {
        $this->requireAdmin();
        return ['methods' => Yii::$app->db->createCommand(
            'SELECT m.code,m.name,m.method_type,m.provider_code,m.supports_dynamic_qr,m.requires_reference,m.is_active,
                    c.iso2 AS country_code,m.currency_code
             FROM {{%funding_payment_methods}} m
             INNER JOIN {{%countries}} c ON c.id=m.country_id ORDER BY c.iso2,m.name'
        )->queryAll()];
    }

    public function actionAdminUpdateMethod(string $code): array
    {
        $userId = $this->requireAdmin();
        $payload = Yii::$app->request->bodyParams;
        if (!array_key_exists('is_active', $payload) || !is_bool($payload['is_active'])) {
            throw new BadRequestHttpException('is_active must be a boolean');
        }
        $db = Yii::$app->db;
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
        $db->createCommand()->update('{{%funding_payment_methods}}', ['is_active' => (int) $payload['is_active']], ['code' => $code])->execute();
        $this->audit($userId, 'funding_method.updated', 'funding_method', $code, ['is_active' => $payload['is_active']]);
        return ['code' => $code, 'is_active' => $payload['is_active']];
    }

    public function actionAdminAccounts(): array
    {
        $this->requireAdmin();
        return ['accounts' => Yii::$app->db->createCommand(
            'SELECT a.public_id,a.country_id,c.iso2 AS country_code,a.currency_code,a.bank_code,a.bank_name,
                    a.account_holder_name,a.legal_entity_name,a.account_type,a.account_mask,a.routing_mask,
                    a.environment,a.status,a.valid_from,a.valid_until,a.notes,a.created_at,a.updated_at,
                    GROUP_CONCAT(fam.payment_method_code ORDER BY fam.payment_method_code) AS methods
             FROM {{%platform_funding_accounts}} a
             INNER JOIN {{%countries}} c ON c.id=a.country_id
             LEFT JOIN {{%funding_account_methods}} fam ON fam.funding_account_id=a.id
             GROUP BY a.id ORDER BY a.created_at DESC'
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
        $countryId = $db->createCommand('SELECT id FROM {{%countries}} WHERE iso2=:code AND is_active=1', [':code' => $countryCode])->queryScalar();
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
            $id = (int) $db->getLastInsertID();
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
        $scope = "funding:deposit:user:{$userId}";
        $hash = hash('sha256', $publicId . '|' . $methodCode);

        // Fast path: the client retried the same request. The authoritative
        // check is repeated inside PaymentIntentService under the row lock.
        $existing = $db->createCommand(
            'SELECT request_hash,response_body FROM {{%idempotency_keys}}
             WHERE scope=:scope AND idempotency_key=:key
               AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())',
            [':scope' => $scope, ':key' => $key]
        )->queryOne();
        if ($existing) {
            if (!hash_equals($existing['request_hash'], $hash)) throw new ConflictHttpException('Idempotency-Key was already used with another request');
            return json_decode($existing['response_body'], true, 512, JSON_THROW_ON_ERROR);
        }

        if ($remittance['status'] !== 'awaiting_payment') throw new UnprocessableEntityHttpException('Remittance is not awaiting payment');
        $account = $db->createCommand(
            'SELECT a.id,a.public_id,a.bank_code,a.bank_name,a.account_holder_name,a.account_mask,a.routing_mask,a.currency_code,a.environment,
                    m.code,m.name,m.method_type,m.supports_dynamic_qr,m.provider_code
             FROM {{%funding_payment_methods}} m
             INNER JOIN {{%funding_account_methods}} fam ON fam.payment_method_code=m.code
             INNER JOIN {{%platform_funding_accounts}} a ON a.id=fam.funding_account_id
             WHERE m.code=:method AND m.is_active=1 AND a.status=:status AND a.environment=:env
               AND a.account_holder_name=:holder AND a.currency_code=:currency
               AND (a.valid_from IS NULL OR a.valid_from<=UTC_TIMESTAMP())
               AND (a.valid_until IS NULL OR a.valid_until>UTC_TIMESTAMP())
             ORDER BY a.updated_at DESC LIMIT 1',
            [':method' => $methodCode, ':status' => 'active', ':env' => 'sandbox', ':holder' => 'SEDERG_PAY', ':currency' => $remittance['send_currency']]
        )->queryOne();
        if (!$account) throw new UnprocessableEntityHttpException('No active SEDERG_PAY account is configured for this payment method and currency');

        try {
            $response = (new PaymentIntentService($db))->createIntent(
                $remittance,
                $account,
                $methodCode,
                ['scope' => $scope, 'key' => $key, 'hash' => $hash]
            );
        } catch (IdempotencyConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), 0, $exception);
        } catch (\DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
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
