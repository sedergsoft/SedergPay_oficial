<?php

declare(strict_types=1);

namespace app\Infrastructure\Remittance;

use app\Infrastructure\Configuration\PlatformSettings;
use app\Infrastructure\Notification\NotificationService;
use Throwable;
use Yii;
use yii\db\Connection;

/**
 * Cancela automáticamente las remesas que permanecen en `awaiting_payment`
 * (esperando el depósito) más allá de la ventana configurada (6 horas por
 * defecto) y cierra sus órdenes de pago pendientes.
 *
 * La cancelación se aplica de dos formas complementarias:
 *  - job programado (php yii remittances/expire), y
 *  - comprobación perezosa al listar/ver el detalle o al intentar generar una
 *    nueva orden de pago, para que la regla se cumpla aunque el cron falle.
 */
final class RemittanceExpiryService
{
    public const DEFAULT_WINDOW_HOURS = 6;
    public const FAILURE_REASON = 'payment_window_expired';
    public const NOTIFICATION_TYPE = 'remittance.cancelled.payment_timeout';

    public function __construct(private readonly Connection $db)
    {
    }

    public function windowHours(): int
    {
        try {
            $hours = (int) PlatformSettings::get($this->db, 'operations.payment_window_hours');
        } catch (Throwable) {
            $hours = (int) (getenv('SEDERG_PAYMENT_WINDOW_HOURS') ?: self::DEFAULT_WINDOW_HOURS);
        }

        return $hours > 0 ? $hours : self::DEFAULT_WINDOW_HOURS;
    }

    /**
     * Cancela todas las remesas vencidas (o solo las de un usuario si se
     * indica). Devuelve cuántas se cancelaron en esta ejecución.
     */
    public function expireOverdue(?int $userId = null, int $limit = 500): int
    {
        $hours = $this->windowHours();
        $conditions = [
            'r.status = :status',
            'r.created_at <= CURRENT_TIMESTAMP - ((:hours)::int * INTERVAL \'1 hour\')',
        ];
        $params = [':status' => 'awaiting_payment', ':hours' => $hours, ':limit' => $limit];
        if ($userId !== null) {
            $conditions[] = 'r.sender_user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $rows = $this->db->createCommand(
            'SELECT r.id FROM {{%remittances}} r
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY r.created_at ASC
             LIMIT (:limit)::int',
            $params
        )->queryAll();

        $cancelled = 0;
        foreach ($rows as $row) {
            try {
                if ($this->cancelOverdue((int) $row['id'], $hours)) {
                    $cancelled++;
                }
            } catch (Throwable $exception) {
                Yii::error(
                    ['message' => 'Remittance expiry failed', 'remittance_id' => $row['id'], 'error' => $exception->getMessage()],
                    __METHOD__
                );
            }
        }

        return $cancelled;
    }

    /**
     * Cancela una remesa si sigue en `awaiting_payment` y ya superó la ventana.
     */
    public function cancelIfOverdue(int $remittanceId): bool
    {
        return $this->cancelOverdue($remittanceId, $this->windowHours());
    }

    public function isOverdue(string $createdAt): bool
    {
        try {
            $result = $this->db->createCommand(
                'SELECT (CURRENT_TIMESTAMP - ((:hours)::int * INTERVAL \'1 hour\')) >= (:created_at)::timestamp',
                [':hours' => $this->windowHours(), ':created_at' => $createdAt]
            )->queryScalar();
        } catch (Throwable) {
            return false;
        }

        return $result === true || $result === 't' || $result === 1 || $result === '1';
    }

    private function cancelOverdue(int $remittanceId, int $hours): bool
    {
        $transaction = $this->db->beginTransaction();
        try {
            $remittance = $this->db->createCommand(
                'SELECT id, public_id, reference, sender_user_id, status, created_at
                 FROM {{%remittances}} WHERE id = :id FOR UPDATE',
                [':id' => $remittanceId]
            )->queryOne();
            if ($remittance === null || $remittance['status'] !== 'awaiting_payment') {
                $transaction->rollBack();

                return false;
            }
            $overdue = $this->db->createCommand(
                'SELECT (CURRENT_TIMESTAMP - ((:hours)::int * INTERVAL \'1 hour\')) >= :created_at',
                [':hours' => $hours, ':created_at' => $remittance['created_at']]
            )->queryScalar();
            if ($overdue !== true && $overdue !== 't' && $overdue !== 1 && $overdue !== '1') {
                $transaction->rollBack();

                return false;
            }

            $this->db->createCommand()->update(
                '{{%remittances}}',
                [
                    'status' => 'cancelled',
                    'cancelled_at' => new \yii\db\Expression('CURRENT_TIMESTAMP'),
                    'failure_reason_code' => self::FAILURE_REASON,
                    'updated_at' => new \yii\db\Expression('CURRENT_TIMESTAMP'),
                ],
                ['id' => $remittanceId, 'status' => 'awaiting_payment']
            )->execute();

            $this->db->createCommand()->insert('{{%remittance_state_events}}', [
                'remittance_id' => $remittanceId,
                'from_status' => 'awaiting_payment',
                'to_status' => 'cancelled',
                'actor_user_id' => null,
                'reason' => "Cancelación automática: no se recibió el depósito dentro de las {$hours} horas de la ventana de pago",
            ])->execute();

            // Cerrar las órdenes de pago para que no puedan usarse ni
            // regenerarse para esta remesa.
            $this->db->createCommand(
                "UPDATE {{%deposit_requests}}
                 SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
                 WHERE remittance_id = :id AND status IN ('pending','confirmed')",
                [':id' => $remittanceId]
            )->execute();

            $transaction->commit();
        } catch (Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        $this->notify((int) $remittance['sender_user_id'], $remittance, $hours);

        return true;
    }

    private function notify(int $userId, array $remittance, int $hours): void
    {
        try {
            (new NotificationService($this->db))->notifyUser(
                $userId,
                self::NOTIFICATION_TYPE,
                'Remesa cancelada',
                'Tu remesa ' . $remittance['reference']
                    . " fue cancelada automáticamente porque no se recibió el depósito dentro de las {$hours} horas de la ventana de pago.",
                [
                    'remittance_public_id' => $remittance['public_id'],
                    'reference' => $remittance['reference'],
                    'status' => 'cancelled',
                    'reason' => self::FAILURE_REASON,
                ]
            );
        } catch (Throwable $exception) {
            Yii::warning(
                'Remittance cancellation notification failed: ' . $exception->getMessage(),
                __METHOD__
            );
        }
    }
}
