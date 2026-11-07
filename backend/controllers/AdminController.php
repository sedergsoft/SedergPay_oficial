<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Configuration\PlatformSettings;
use app\Infrastructure\Pricing\ExchangeRateService;
use app\Infrastructure\Security\AuditLog;
use DomainException;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\UnprocessableEntityHttpException;
use yii\web\Response;
use yii\db\JsonExpression;

final class AdminController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionOperations(): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');

        return Yii::$app->db->createCommand(
            'SELECT r.public_id, r.reference, r.direction,
                    r.send_amount, r.send_currency, r.receive_amount,
                    r.receive_currency, r.status, r.compliance_status,
                    r.created_at, r.updated_at, u.email AS customer_email,
                    CONCAT(COALESCE(p.first_name, \'\'), \' \', COALESCE(p.last_name, \'\')) AS customer_name
             FROM {{%remittances}} r
             INNER JOIN {{%users}} u ON u.id = r.sender_user_id
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             ORDER BY r.created_at DESC
             LIMIT 200'
        )->queryAll();
    }

    public function actionUsers(): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');

        return Yii::$app->db->createCommand(
            'SELECT u.public_id, u.email, u.phone_e164, u.role, u.status,
                    u.email_verified_at, u.created_at, u.last_login_at,
                    CONCAT(COALESCE(p.first_name, \'\'), \' \', COALESCE(p.last_name, \'\')) AS full_name,
                    COUNT(DISTINCT r.id) AS remittance_count
             FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id = u.id
             LEFT JOIN {{%remittances}} r ON r.sender_user_id = u.id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.public_id, u.email, u.phone_e164, u.role, u.status,
                      u.email_verified_at, u.created_at, u.last_login_at, p.first_name, p.last_name
             ORDER BY u.created_at DESC LIMIT 500'
        )->queryAll();
    }

    public function actionRateHistory(): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');
        $request = Yii::$app->request;
        $base = strtoupper(trim($request->get('base', 'RUB')));
        $quote = strtoupper(trim($request->get('quote', 'CUP')));
        if (!in_array($base, ['RUB', 'CUP'], true) || !in_array($quote, ['RUB', 'CUP'], true) || $base === $quote) {
            throw new BadRequestHttpException('Par de monedas no soportado.');
        }

        return ['rates' => Yii::$app->db->createCommand(
            'SELECT e.rate, e.source, e.valid_from, e.valid_until, e.created_at,
                    u.email AS created_by_email
             FROM {{%exchange_rates}} e
             LEFT JOIN {{%users}} u ON u.id = e.created_by_user_id
             WHERE e.base_currency = :base AND e.quote_currency = :quote
             ORDER BY e.valid_from DESC, e.id DESC LIMIT 500',
            [':base' => $base, ':quote' => $quote]
        )->queryAll()];
    }

    public function actionDashboard(): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');

        $directions = Yii::$app->db->createCommand(
            'SELECT direction, send_currency AS currency, COUNT(*) AS operations,
                    COALESCE(SUM(send_amount), 0) AS sent_amount,
                    receive_currency, COALESCE(SUM(receive_amount), 0) AS received_amount
             FROM {{%remittances}}
             GROUP BY direction, send_currency, receive_currency
             ORDER BY direction'
        )->queryAll();
        $statuses = Yii::$app->db->createCommand(
            'SELECT status, COUNT(*) AS operations,
                    COALESCE(SUM(send_amount), 0) AS send_amount,
                    send_currency AS currency
             FROM {{%remittances}}
             GROUP BY status, send_currency
             ORDER BY status, send_currency'
        )->queryAll();

        return [
            'directions' => $directions,
            'statuses' => $statuses,
            'total_users' => (int) Yii::$app->db->createCommand(
                'SELECT COUNT(*) FROM {{%users}} WHERE deleted_at IS NULL'
            )->queryScalar(),
            'total_remittances' => (int) Yii::$app->db->createCommand(
                'SELECT COUNT(*) FROM {{%remittances}}'
            )->queryScalar(),
        ];
    }

    public function actionSetExchangeRate(): array
    {
        $authenticator = new UserAuthenticator(Yii::$app->db);
        $userId = $authenticator->userId();
        $authenticator->requireRole('admin');
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload) || !isset($payload['rate']) || !is_string($payload['rate'])) {
            throw new BadRequestHttpException('La tasa es requerida.');
        }
        $base = strtoupper(trim($payload['base'] ?? 'RUB'));
        $quote = strtoupper(trim($payload['quote'] ?? 'CUP'));
        if (!in_array($base, ['RUB', 'CUP'], true) || !in_array($quote, ['RUB', 'CUP'], true) || $base === $quote) {
            throw new BadRequestHttpException('Par de monedas no soportado.');
        }

        try {
            return (new ExchangeRateService(Yii::$app->db))->saveAdminOverride($payload['rate'], $userId, $base, $quote);
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
        }
    }

    public function actionSetExchangeRateSource(): array
    {
        $authenticator = new UserAuthenticator(Yii::$app->db);
        $userId = $authenticator->userId();
        $authenticator->requireRole('admin');
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload) || !is_string($payload['source'] ?? null)) {
            throw new BadRequestHttpException('La fuente de tasa es obligatoria.');
        }
        $base = strtoupper(trim($payload['base'] ?? 'RUB'));
        $quote = strtoupper(trim($payload['quote'] ?? 'CUP'));
        if (!in_array($base, ['RUB', 'CUP'], true) || !in_array($quote, ['RUB', 'CUP'], true) || $base === $quote) {
            throw new BadRequestHttpException('Par de monedas no soportado.');
        }
        $pairKey = "{$base}/{$quote}";
        try {
            $rate = (new ExchangeRateService(Yii::$app->db))->setActiveSource($payload['source'], $userId, $base, $quote);
            (new AuditLog(Yii::$app->db))->insert(
                action: 'exchange_rate.source.changed',
                entityType: 'exchange_rate',
                actorUserId: $userId,
                entityId: $pairKey,
                ipAddress: Yii::$app->request->userIP,
                userAgent: substr((string) Yii::$app->request->userAgent, 0, 500),
                afterData: ['source' => $payload['source'], 'rate' => $rate['rate']],
            );
            return $rate;
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
        }
    }

    public function actionKycIndex(): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');
        return ['cases' => Yii::$app->db->createCommand(
            'SELECT k.public_id, k.status, k.submitted_at, k.reviewed_at, k.review_reason,
                    u.public_id AS user_public_id, u.email,
                    p.first_name, p.last_name,
                    (SELECT COUNT(*) FROM {{%kyc_documents}} d WHERE d.kyc_case_id=k.id) AS document_count
             FROM {{%kyc_cases}} k
             INNER JOIN {{%users}} u ON u.id=k.user_id
             LEFT JOIN {{%user_profiles}} p ON p.user_id=u.id
             ORDER BY CASE k.status
                        WHEN \'pending_review\' THEN 0
                        WHEN \'draft\' THEN 1
                        WHEN \'rejected\' THEN 2
                        WHEN \'approved\' THEN 3
                        ELSE 4
                      END, k.updated_at DESC
             LIMIT 300'
        )->queryAll()];
    }

    public function actionKycReview(string $publicId): array
    {
        $auth = new UserAuthenticator(Yii::$app->db);
        $reviewerId = $auth->userId();
        $auth->requireRole('admin');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new BadRequestHttpException('Invalid KYC case identifier');
        }

        $payload = Yii::$app->request->bodyParams;
        $decision = $payload['decision'] ?? null;
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected', 'more_information'], true)) {
            throw new BadRequestHttpException('Invalid KYC decision');
        }
        if ($decision !== 'approved' && ($reason === '' || mb_strlen($reason) > 1000)) {
            throw new BadRequestHttpException('A reason of at most 1000 characters is required for this decision');
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            $case = $db->createCommand(
                'SELECT id,user_id,status FROM {{%kyc_cases}} WHERE public_id=:id LIMIT 1 FOR UPDATE',
                [':id' => $publicId]
            )->queryOne();
            if ($case === false || $case['status'] !== 'pending_review') {
                throw new UnprocessableEntityHttpException('KYC case is not awaiting review');
            }

            if ($decision === 'approved') {
                $profile = $db->createCommand(
                    'SELECT date_of_birth,address_line,city,residence_country_id FROM {{%user_profiles}} WHERE user_id=:id LIMIT 1',
                    [':id' => $case['user_id']]
                )->queryOne();
                $types = $db->createCommand(
                    'SELECT DISTINCT document_type FROM {{%kyc_documents}} WHERE kyc_case_id=:id',
                    [':id' => $case['id']]
                )->queryColumn();
                if ($profile === false || empty($profile['date_of_birth']) || empty($profile['address_line'])
                    || empty($profile['city']) || empty($profile['residence_country_id'])
                    || !in_array('identity', $types, true) || !in_array('address', $types, true)) {
                    throw new UnprocessableEntityHttpException('KYC requirements are incomplete; approval is blocked');
                }
            }

            $status = $decision === 'more_information' ? 'requires_action' : $decision;
            $db->createCommand()->update('{{%kyc_cases}}', [
                'status' => $status,
                'reviewed_at' => gmdate('Y-m-d H:i:s'),
                'reviewed_by_user_id' => $reviewerId,
                'review_reason' => $reason !== '' ? $reason : null,
            ], ['id' => $case['id'], 'status' => 'pending_review'])->execute();

            (new AuditLog(Yii::$app->db))->insert(
                action: 'kyc.case.reviewed',
                entityType: 'kyc_case',
                actorUserId: $reviewerId,
                entityId: $publicId,
                ipAddress: Yii::$app->request->userIP,
                userAgent: substr((string) Yii::$app->request->userAgent, 0, 500),
                afterData: ['status' => $status, 'reason' => $reason],
            );
            $tx->commit();
        } catch (\Throwable $exception) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            }
            throw $exception;
        }

        return ['public_id' => $publicId, 'status' => $status, 'review_reason' => $reason];
    }

    public function actionKycDocuments(string $publicId): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new BadRequestHttpException('Invalid KYC case identifier');
        }
        $case = Yii::$app->db->createCommand(
            'SELECT id FROM {{%kyc_cases}} WHERE public_id=:id LIMIT 1',
            [':id' => $publicId]
        )->queryOne();
        if ($case === false) {
            throw new BadRequestHttpException('KYC case not found');
        }
        return ['documents' => Yii::$app->db->createCommand(
            'SELECT public_id,document_type,original_name,mime_type,file_size,created_at
             FROM {{%kyc_documents}} WHERE kyc_case_id=:id ORDER BY created_at',
            [':id' => $case['id']]
        )->queryAll()];
    }

    public function actionKycDocument(string $publicId)
    {
        $auth = new UserAuthenticator(Yii::$app->db);
        $reviewerId = $auth->userId();
        $auth->requireRole('admin');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new BadRequestHttpException('Invalid KYC document identifier');
        }

        $doc = Yii::$app->db->createCommand(
            'SELECT d.id,d.storage_key,d.original_name,d.mime_type,d.file_size,d.sha256,k.public_id AS case_public_id
             FROM {{%kyc_documents}} d
             INNER JOIN {{%kyc_cases}} k ON k.id=d.kyc_case_id
             WHERE d.public_id=:id LIMIT 1',
            [':id' => $publicId]
        )->queryOne();
        if ($doc === false) {
            throw new BadRequestHttpException('KYC document not found');
        }

        $root = realpath((string) getenv('SEDERG_PRIVATE_STORAGE_PATH'));
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $doc['storage_key']);
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new BadRequestHttpException('KYC file is unavailable');
        }

        $key = (string) getenv('SEDERG_PII_KEY');
        if (strlen($key) < 32) {
            throw new \yii\web\ServerErrorHttpException('KYC encryption key is not configured');
        }
        $encrypted = file_get_contents($path);
        $content = $encrypted === false ? false : Yii::$app->security->decryptByKey($encrypted, $key);
        if ($content === false || strlen($content) !== (int) $doc['file_size'] || !hash_equals((string) $doc['sha256'], hash('sha256', $content))) {
            throw new \yii\web\ServerErrorHttpException('KYC document integrity or decryption check failed');
        }

        (new AuditLog(Yii::$app->db))->insert(
            action: 'kyc.document.downloaded',
            entityType: 'kyc_document',
            actorUserId: $reviewerId,
            entityId: $publicId,
            ipAddress: Yii::$app->request->userIP,
            userAgent: substr((string) Yii::$app->request->userAgent, 0, 500),
            afterData: ['case_public_id' => $doc['case_public_id']],
        );

        Yii::$app->response->headers->set('X-Content-Type-Options', 'nosniff');
        Yii::$app->response->headers->set('Cache-Control', 'no-store, private');
        return Yii::$app->response->sendContentAsFile(
            $content,
            basename((string) $doc['original_name']),
            ['mimeType' => $doc['mime_type'], 'inline' => false]
        );
    }

        public function actionSettings(): array
    {
        (new UserAuthenticator(Yii::$app->db))->requireRole('admin');

        return ['settings' => PlatformSettings::all(Yii::$app->db)];
    }

    public function actionUpdateSettings(): array
    {
        $authenticator = new UserAuthenticator(Yii::$app->db);
        $userId = $authenticator->userId();
        $authenticator->requireRole('admin');
        $payload = Yii::$app->request->bodyParams;
        if (!is_array($payload) || !is_array($payload['settings'] ?? null)) {
            throw new BadRequestHttpException('Settings object is required');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $result = PlatformSettings::save(Yii::$app->db, $userId, $payload['settings']);
            (new AuditLog(Yii::$app->db))->insert(
                action: 'platform.settings.updated',
                entityType: 'platform_settings',
                actorUserId: $userId,
                entityId: 'platform',
                ipAddress: Yii::$app->request->userIP,
                userAgent: substr((string) Yii::$app->request->userAgent, 0, 500),
                beforeData: $result['before'],
                afterData: $result['after'],
            );
            $transaction->commit();
        } catch (DomainException $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw new UnprocessableEntityHttpException($exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        return ['settings' => $result['settings']];
    }
}
