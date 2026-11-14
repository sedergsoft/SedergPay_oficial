<?php

declare(strict_types=1);

namespace app\controllers;

use app\Infrastructure\Auth\UserAuthenticator;
use Yii;
use yii\filters\ContentNegotiator;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class NotificationController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return ['contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => ['application/json' => Response::FORMAT_JSON],
        ]];
    }

    public function actionIndex(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $db = Yii::$app->db;
        $limit = (int) (Yii::$app->request->get('limit', 50));
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $items = $db->createCommand(
            'SELECT public_id, type, title, body, data, is_read, read_at, created_at
             FROM {{%notifications}}
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT :limit',
            [':user_id' => $userId, ':limit' => $limit]
        )->queryAll();

        foreach ($items as &$item) {
            $item['is_read'] = filter_var($item['is_read'], FILTER_VALIDATE_BOOLEAN);
            $item['data'] = $this->decodeData($item['data'] ?? null);
        }
        unset($item);

        $unread = (int) $db->createCommand(
            'SELECT COUNT(*) FROM {{%notifications}} WHERE user_id = :user_id AND is_read = FALSE',
            [':user_id' => $userId]
        )->queryScalar();

        return ['items' => $items, 'unread_count' => $unread];
    }

    public function actionUnreadCount(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        $unread = (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM {{%notifications}} WHERE user_id = :user_id AND is_read = FALSE',
            [':user_id' => $userId]
        )->queryScalar();

        return ['unread_count' => $unread];
    }

    public function actionRead(string $publicId): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        if (!preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new BadRequestHttpException('Invalid notification identifier');
        }
        $db = Yii::$app->db;
        $row = $db->createCommand(
            'SELECT id,is_read FROM {{%notifications}} WHERE public_id = :id AND user_id = :user_id LIMIT 1',
            [':id' => $publicId, ':user_id' => $userId]
        )->queryOne();
        if ($row === false) {
            throw new NotFoundHttpException('Notification not found');
        }
        if (!filter_var($row['is_read'], FILTER_VALIDATE_BOOLEAN)) {
            $db->createCommand()->update('{{%notifications}}', [
                'is_read' => true,
                'read_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $row['id'], 'user_id' => $userId])->execute();
        }
        return ['public_id' => $publicId, 'is_read' => true];
    }

    public function actionReadAll(): array
    {
        $userId = (new UserAuthenticator(Yii::$app->db))->userId();
        Yii::$app->db->createCommand(
            'UPDATE {{%notifications}} SET is_read = TRUE, read_at = :now
             WHERE user_id = :user_id AND is_read = FALSE',
            [':now' => gmdate('Y-m-d H:i:s'), ':user_id' => $userId]
        )->execute();
        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeData(mixed $data): ?array
    {
        if ($data === null || $data === '') {
            return null;
        }
        if (is_array($data)) {
            return $data;
        }
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
