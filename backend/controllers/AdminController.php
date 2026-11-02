<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Configuration\PlatformSettings;
use app\Infrastructure\Pricing\ExchangeRateService;
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

        return ['rates' => Yii::$app->db->createCommand(
            'SELECT e.rate, e.source, e.valid_from, e.valid_until, e.created_at,
                    u.email AS created_by_email
             FROM {{%exchange_rates}} e
             LEFT JOIN {{%users}} u ON u.id = e.created_by_user_id
             WHERE e.base_currency = :base AND e.quote_currency = :quote
             ORDER BY e.valid_from DESC, e.id DESC LIMIT 500',
            [':base' => 'RUB', ':quote' => 'CUP']
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
            throw new BadRequestHttpException('A RUB/CUP rate is required');
        }

        try {
            return (new ExchangeRateService(Yii::$app->db))->saveAdminOverride($payload['rate'], $userId);
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
        try {
            $rate = (new ExchangeRateService(Yii::$app->db))->setActiveSource($payload['source'], $userId);
            Yii::$app->db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'exchange_rate.source.changed',
                'entity_type' => 'exchange_rate',
                'entity_id' => 'RUB/CUP',
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
                'after_data' => new JsonExpression(['source' => $payload['source'], 'rate' => $rate['rate']]),
            ])->execute();
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
             ORDER BY FIELD(k.status, "pending_review", "draft", "rejected", "approved"), k.updated_at DESC
             LIMIT 300'
        )->queryAll()];
    }

    public function actionKycReview(string $publicId): array
    {
        $auth = new UserAuthenticator(Yii::$app->db);
        $reviewerId = $auth->userId();
        $auth->requireRole('admin');
        $payload = Yii::$app->request->bodyParams;
        $decision = $payload['decision'] ?? null;
        $reason = trim((string)($payload['reason'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected', 'more_information'], true)) {
            throw new BadRequestHttpException('Invalid KYC decision');
        }
        if ($decision !== 'approved' && $reason === '') {
            throw new BadRequestHttpException('A reason is required for this decision');
        }
        $case = Yii::$app->db->createCommand('SELECT id,status FROM {{%kyc_cases}} WHERE public_id=:id LIMIT 1', [':id'=>$publicId])->queryOne();
        if ($case === false || $case['status'] !== 'pending_review') {
            throw new UnprocessableEntityHttpException('KYC case is not awaiting review');
        }
        $status = $decision === 'more_information' ? 'requires_action' : $decision;
        Yii::$app->db->createCommand()->update('{{%kyc_cases}}', [
            'status'=>$status, 'reviewed_at'=>gmdate('Y-m-d H:i:s'),
            'reviewed_by_user_id'=>$reviewerId, 'review_reason'=>$reason !== '' ? mb_substr($reason,0,1000) : null,
        ], ['id'=>$case['id']])->execute();
        Yii::$app->db->createCommand()->insert('{{%audit_logs}}', [
            'actor_user_id'=>$reviewerId, 'action'=>'kyc.case.reviewed', 'entity_type'=>'kyc_case',
            'entity_id'=>$publicId, 'ip_address'=>Yii::$app->request->userIP,
            'user_agent'=>substr((string)Yii::$app->request->userAgent,0,500),
            'after_data'=>new JsonExpression(['status'=>$status,'reason'=>$reason]),
        ])->execute();
        return ['public_id'=>$publicId,'status'=>$status,'review_reason'=>$reason];
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
            Yii::$app->db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'platform.settings.updated',
                'entity_type' => 'platform_settings',
                'entity_id' => 'platform',
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
                'before_data' => new JsonExpression($result['before']),
                'after_data' => new JsonExpression($result['after']),
            ])->execute();
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
