<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use app\Infrastructure\Compliance\KycDailyLimit;
use app\Infrastructure\Identity\VerificationMailer;
use app\Infrastructure\Kyc\KycDocumentSecurity;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\TooManyRequestsHttpException;

final class KycController extends Controller
{
    /** Tamaño máximo admitido para un documento KYC (5 MB). */
    public const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    private function throttle(string $action, int $limit, int $windowSeconds): void
    {
        \app\Infrastructure\Security\RateLimiter::enforce($action, $limit, $windowSeconds);
    }

    private function userId(): int
    {
        return (new UserAuthenticator(Yii::$app->db))->userId();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function actionView(): array
    {
        $userId = $this->userId();
        $case = Yii::$app->db->createCommand(
            'SELECT public_id,status,submitted_at,reviewed_at,review_reason,created_at,updated_at
             FROM {{%kyc_cases}} WHERE user_id=:id LIMIT 1',
            [':id' => $userId]
        )->queryOne();

        // Limite diario de envios mientras el KYC no este verificado.
        $dailyLimit = (new KycDailyLimit(Yii::$app->db))->status($userId);

        if ($case === false) {
            return ['status' => 'not_started', 'documents' => [], 'daily_limit' => $dailyLimit];
        }

        $case['documents'] = Yii::$app->db->createCommand(
            'SELECT public_id,document_type,original_name,mime_type,file_size,created_at
             FROM {{%kyc_documents}}
             WHERE kyc_case_id=(SELECT id FROM {{%kyc_cases}} WHERE user_id=:id)
             ORDER BY created_at',
            [':id' => $userId]
        )->queryAll();
        $case['daily_limit'] = $dailyLimit;

        return $case;
    }

    public function actionUploadDocument(): array
    {
        $this->throttle('kyc-upload', 20, 600);
        $userId = $this->userId();
        $type = (string) Yii::$app->request->post('document_type', 'identity');
        if (!in_array($type, ['identity', 'address', 'selfie'], true)) {
            throw new UnprocessableEntityHttpException('Unsupported document type');
        }

        $file = UploadedFile::getInstanceByName('file');
        if ($file === null || $file->error !== UPLOAD_ERR_OK || !is_file($file->tempName)) {
            // PHP descarta la subida cuando el fichero supera upload_max_filesize
            // o el cuerpo entero supera post_max_size. En ese caso hay que
            // explicarlo al cliente en lugar de devolver un error generico.
            $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $tooLarge = $declared > self::MAX_DOCUMENT_BYTES
                || ($file !== null && in_array((int) $file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true));
            if ($tooLarge) {
                throw new UnprocessableEntityHttpException(
                    'The document exceeds the maximum allowed size of 5 MB'
                );
            }
            throw new UnprocessableEntityHttpException('A valid document upload is required');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->tempName);
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!isset($extensions[$mime])) {
            throw new UnprocessableEntityHttpException('Only PDF, JPG and PNG documents are accepted');
        }

        KycDocumentSecurity::validateAndScan($file->tempName, $mime);

        $encryptionKey = (string) (getenv('SEDERG_PII_KEY') ?: '');
        if (strlen($encryptionKey) < 32) {
            throw new ServerErrorHttpException('A dedicated KYC encryption key of at least 32 characters is required');
        }
        $originalContent = file_get_contents($file->tempName);
        if ($originalContent === false) {
            throw new ServerErrorHttpException('Cannot read uploaded document');
        }
        $encryptedContent = Yii::$app->security->encryptByKey($originalContent, $encryptionKey);

        $rootSetting = trim((string) getenv('SEDERG_PRIVATE_STORAGE_PATH'));
        if ($rootSetting === '') {
            throw new ServerErrorHttpException('Private document storage is not configured');
        }
        if (!is_dir($rootSetting) && !mkdir($rootSetting, 0700, true) && !is_dir($rootSetting)) {
            throw new ServerErrorHttpException('Cannot initialize private document storage');
        }
        $root = realpath($rootSetting);
        if ($root === false || !is_writable($root)) {
            throw new ServerErrorHttpException('Private document storage is unavailable');
        }

        $relativeKey = 'kyc/' . $userId . '/' . bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        $folder = $root . DIRECTORY_SEPARATOR . 'kyc' . DIRECTORY_SEPARATOR . $userId;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new ServerErrorHttpException('Cannot create private document directory');
        }
        $folderReal = realpath($folder);
        if ($folderReal === false || !str_starts_with($folderReal, $root . DIRECTORY_SEPARATOR)) {
            throw new ServerErrorHttpException('Invalid private document directory');
        }

        $path = $folderReal . DIRECTORY_SEPARATOR . basename($relativeKey);
        $written = file_put_contents($path, $encryptedContent, LOCK_EX);
        if ($written === false || $written !== strlen($encryptedContent)) {
            @unlink($path);
            throw new ServerErrorHttpException('Cannot store encrypted KYC document');
        }
        @chmod($path, 0600);

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            $case = $db->createCommand(
                'SELECT id,status FROM {{%kyc_cases}} WHERE user_id=:id LIMIT 1 FOR UPDATE',
                [':id' => $userId]
            )->queryOne();

            if ($case !== false && in_array($case['status'], ['approved', 'pending_review'], true)) {
                throw new ForbiddenHttpException('KYC documents cannot be changed while approved or awaiting review');
            }

            if ($case === false) {
                $db->createCommand()->insert('{{%kyc_cases}}', [
                    'public_id' => $this->uuid(),
                    'user_id' => $userId,
                    'status' => 'draft',
                ])->execute();
                $caseId = (int) $db->createCommand("SELECT currval(pg_get_serial_sequence('kyc_cases', 'id'))")->queryScalar();
            } else {
                $caseId = (int) $case['id'];
            }

            $usage = $db->createCommand(
                'SELECT COUNT(*) AS document_count, COALESCE(SUM(file_size),0) AS total_bytes
                 FROM {{%kyc_documents}} WHERE kyc_case_id=:id',
                [':id' => $caseId]
            )->queryOne();
            if ((int) $usage['document_count'] >= 10 || (int) $usage['total_bytes'] + strlen($originalContent) > 25 * 1024 * 1024) {
                throw new UnprocessableEntityHttpException('A KYC case is limited to 10 documents and 25 MB in total');
            }

            $db->createCommand()->insert('{{%kyc_documents}}', [
                'public_id' => $this->uuid(),
                'kyc_case_id' => $caseId,
                'document_type' => $type,
                'storage_key' => $relativeKey,
                'original_name' => mb_substr(basename($file->name), 0, 190),
                'mime_type' => $mime,
                'file_size' => strlen($originalContent),
                'sha256' => hash('sha256', $originalContent),
            ])->execute();

            $db->createCommand()->update('{{%kyc_cases}}', [
                'status' => 'draft',
                'review_reason' => null,
                'reviewed_at' => null,
                'reviewed_by_user_id' => null,
            ], ['id' => $caseId])->execute();

            $db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'kyc.document.uploaded',
                'entity_type' => 'kyc_case',
                'entity_id' => (string) $caseId,
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
                'after_data' => new \yii\db\JsonExpression([
                    'document_type' => $type,
                    'mime_type' => $mime,
                    'file_size' => strlen($originalContent),
                    'sha256' => hash('sha256', $originalContent),
                ]),
            ])->execute();

            $tx->commit();
        } catch (\Throwable $exception) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            }
            @unlink($path);
            throw $exception;
        }

        return ['uploaded' => true, 'document_type' => $type, 'file_name' => basename($file->name)];
    }

    /**
     * Avisa por correo al cliente de que su expediente entro en revision y
     * avisa a los administradores de que hay un expediente pendiente.
     * Un fallo del correo no debe romper el envio del expediente.
     */
    private function notifyKycSubmitted(int $userId): void
    {
        $customerName = '';
        $documents = 0;
        try {
            $user = Yii::$app->db->createCommand(
                'SELECT u.email, p.first_name, p.last_name FROM {{%users}} u
                 LEFT JOIN {{%user_profiles}} p ON p.user_id=u.id
                 WHERE u.id=:id LIMIT 1',
                [':id' => $userId]
            )->queryOne();
            if ($user === false || empty($user['email'])) {
                return;
            }
            $name = trim((string) ($user['first_name'] ?? ''));
            $customerName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            if ($customerName === '') {
                $customerName = (string) $user['email'];
            }
            $documents = (int) Yii::$app->db->createCommand(
                'SELECT COUNT(*) FROM {{%kyc_documents}} WHERE kyc_case_id=(SELECT id FROM {{%kyc_cases}} WHERE user_id=:id)',
                [':id' => $userId]
            )->queryScalar();

            (new VerificationMailer())->sendKycStatusChange(
                (string) $user['email'],
                $name !== '' ? $name : 'usuario',
                'pending_review'
            );
        } catch (\Throwable $exception) {
            Yii::warning('KYC submission notification failed: ' . $exception->getMessage(), __METHOD__);
        }

        try {
            if ($customerName !== '') {
                $this->notifyAdminsKycPending($customerName, $documents);
            }
        } catch (\Throwable $exception) {
            Yii::warning('KYC admin notification failed: ' . $exception->getMessage(), __METHOD__);
        }
    }

    /**
     * Avisa a los administradores de que hay un expediente en revision.
     */
    private function notifyAdminsKycPending(string $customerName, int $documentCount): void
    {
        $admins = Yii::$app->db->createCommand(
            'SELECT u.email, p.first_name FROM {{%users}} u
             LEFT JOIN {{%user_profiles}} p ON p.user_id=u.id
             WHERE u.role=:role ORDER BY u.id',
            [':role' => 'admin']
        )->queryAll();
        if ($admins === []) {
            return;
        }
        (new VerificationMailer())->sendKycReviewRequested($admins, $customerName, $documentCount);
    }

    public function actionSubmit(): array
    {
        $this->throttle('kyc-submit', 10, 600);
        $userId = $this->userId();
        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            $case = $db->createCommand(
                'SELECT id,status FROM {{%kyc_cases}} WHERE user_id=:id LIMIT 1 FOR UPDATE',
                [':id' => $userId]
            )->queryOne();
            if ($case === false) {
                throw new UnprocessableEntityHttpException('Upload the required documents first');
            }
            if (in_array($case['status'], ['approved', 'pending_review'], true)) {
                throw new ForbiddenHttpException('This KYC case cannot be submitted in its current state');
            }

            $profile = $db->createCommand(
                'SELECT date_of_birth,address_line,city,residence_country_id
                 FROM {{%user_profiles}} WHERE user_id=:id LIMIT 1',
                [':id' => $userId]
            )->queryOne();
            if ($profile === false || empty($profile['date_of_birth']) || empty($profile['address_line'])
                || empty($profile['city']) || empty($profile['residence_country_id'])) {
                throw new UnprocessableEntityHttpException('Complete your date of birth and residential address in your profile before submitting KYC');
            }

            $types = $db->createCommand(
                'SELECT DISTINCT document_type FROM {{%kyc_documents}} WHERE kyc_case_id=:id',
                [':id' => $case['id']]
            )->queryColumn();
            if (!in_array('identity', $types, true) || !in_array('address', $types, true)) {
                throw new UnprocessableEntityHttpException('Identity and proof-of-address documents are required');
            }

            $db->createCommand()->update('{{%kyc_cases}}', [
                'status' => 'pending_review',
                'submitted_at' => gmdate('Y-m-d H:i:s'),
                'review_reason' => null,
            ], ['id' => $case['id'], 'status' => $case['status']])->execute();

            $db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => $userId,
                'action' => 'kyc.case.submitted',
                'entity_type' => 'kyc_case',
                'entity_id' => (string) $case['id'],
                'ip_address' => Yii::$app->request->userIP,
                'user_agent' => substr((string) Yii::$app->request->userAgent, 0, 500),
            ])->execute();

            $tx->commit();
        } catch (\Throwable $exception) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            }
            throw $exception;
        }

        // Aviso al cliente de que su expediente ya esta en revision.
        $this->notifyKycSubmitted($userId);

        return ['status' => 'pending_review'];
    }
}
