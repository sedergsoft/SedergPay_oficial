<?php

declare(strict_types=1);

namespace app\Infrastructure\Compliance;

use DomainException;
use yii\db\Connection;

/**
 * Limite diario de envios para usuarios cuyo KYC no esta verificado.
 *
 * Mientras el KYC no aprobado, el usuario puede enviar como maximo
 * 500 RUB diarios (o su equivalente nominal en CUP en el corridor inverso).
 * El control se aplica en el servidor: el frontend solo informa.
 */
final class KycDailyLimit
{
    public const DEFAULT_LIMIT = '500.00';

    public function __construct(
        private readonly Connection $db,
        private readonly string $limit = self::DEFAULT_LIMIT
    ) {
    }

    /**
     * @return array{
     *   limited: bool,
     *   limit: string,
     *   used: string,
     *   remaining: string,
     *   kyc_status: string
     * }
     */
    public function status(int $userId, string $currency = 'RUB'): array
    {
        $currency = strtoupper($currency);
        $kycStatus = (string) ($this->db->createCommand(
            'SELECT status FROM {{%kyc_cases}} WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1',
            [':user_id' => $userId]
        )->queryScalar() ?? 'none');

        if ($kycStatus === 'approved') {
            return [
                'limited' => false,
                'limit' => $this->limit,
                'used' => '0.00',
                'remaining' => $this->limit,
                'kyc_status' => $kycStatus,
            ];
        }

        $used = (string) $this->db->createCommand(
            "SELECT COALESCE(SUM(send_amount), 0)
               FROM {{%remittances}}
              WHERE user_id = :user_id
                AND send_currency = :currency
                AND created_at >= CURRENT_DATE
                AND status <> 'cancelled'",
            [':user_id' => $userId, ':currency' => $currency]
        )->queryScalar();

        $used = bcadd((string) $used, '0', 2);
        $remaining = bcsub($this->limit, $used, 2);
        if (bccomp($remaining, '0', 2) < 0) {
            $remaining = '0.00';
        }

        return [
            'limited' => true,
            'limit' => $this->limit,
            'used' => $used,
            'remaining' => $remaining,
            'kyc_status' => $kycStatus,
        ];
    }

    /**
     * Lanza excepcion si la nueva operacion supera el limite diario.
     */
    public function assertWithinLimit(int $userId, string $sendAmount, string $currency = 'RUB'): void
    {
        $currency = strtoupper($currency);
        $status = $this->status($userId, $currency);
        if ($status['limited'] === false) {
            return;
        }

        $total = bcadd($status['used'], bcadd((string) $sendAmount, '0', 2), 2);
        if (bccomp($total, $status['limit'], 2) <= 0) {
            return;
        }

        throw new DomainException(sprintf(
            'Daily limit exceeded: without verified KYC you can send up to %s %s per day (used %s %s today). Complete the KYC verification to send more.',
            $status['limit'],
            $currency,
            $status['used'],
            $currency
        ));
    }
}
