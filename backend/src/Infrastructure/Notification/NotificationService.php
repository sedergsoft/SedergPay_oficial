<?php

declare(strict_types=1);

namespace app\Infrastructure\Notification;

use Yii;
use yii\db\Connection;
use yii\db\JsonExpression;

final class NotificationService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function notifyUser(int $userId, string $type, string $title, string $body, ?array $data = null): ?string
    {
        try {
            $publicId = $this->uuid();
            $this->db->createCommand()->insert('{{%notifications}}', [
                'public_id' => $publicId,
                'user_id' => $userId,
                'type' => mb_substr($type, 0, 64),
                'title' => mb_substr($title, 0, 190),
                'body' => $body,
                'data' => $data === null ? null : new JsonExpression($data),
                'is_read' => false,
                'read_at' => null,
            ])->execute();
            return $publicId;
        } catch (\Throwable $exception) {
            Yii::warning('Notification insert failed: ' . $exception->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * @param array<int, int> $userIds
     * @param array<string, mixed>|null $data
     */
    public function notifyUsers(array $userIds, string $type, string $title, string $body, ?array $data = null): void
    {
        $seen = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0 || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;
            $this->notifyUser($userId, $type, $title, $body, $data);
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
