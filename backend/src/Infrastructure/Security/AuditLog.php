<?php

declare(strict_types=1);

namespace app\Infrastructure\Security;

use Yii;
use yii\db\Connection;
use yii\db\JsonExpression;

/**
 * M-02: Registro de auditoría con encadenamiento de hash (hash chain).
 *
 * Cada entrada almacena el hash SHA-256 de la entrada anterior, formando una
 * cadena criptográfica inmutable. Cualquier alteración de un registro rompe
 * la cadena y es detectable al ejecutar verifyIntegrity().
 */
final class AuditLog
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function insert(
        string $action,
        string $entityType,
        ?int $actorUserId,
        ?string $entityId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
        array $beforeData = [],
        array $afterData = [],
    ): void {
        $previousHash = $this->getLastHash();
        $payload = [
            'action' => $action,
            'entity_type' => $entityType,
            'actor_user_id' => $actorUserId,
            'entity_id' => $entityId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'correlation_id' => $correlationId,
            'before_data' => $beforeData,
            'after_data' => $afterData,
            'previous_hash' => $previousHash,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
        $payload['hash_chain'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->db->createCommand()->insert('{{%audit_logs}}', [
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'correlation_id' => $correlationId,
            'before_data' => !empty($beforeData) ? new JsonExpression($beforeData) : null,
            'after_data' => !empty($afterData) ? new JsonExpression($afterData) : null,
            'previous_hash' => $previousHash,
            'hash_chain' => $payload['hash_chain'],
            'created_at' => $payload['created_at'],
        ])->execute();
    }

    /**
     * Verifica la integridad de la cadena de hash de todos los registros.
     * Retorna true si la cadena está completa y no tiene roturas.
     */
    public function verifyIntegrity(): bool
    {
        $rows = $this->db->createCommand(
            'SELECT id, previous_hash, hash_chain, action, entity_type, entity_id,
                    actor_user_id, ip_address, user_agent, correlation_id,
                    before_data, after_data, created_at
             FROM {{%audit_logs}}
             ORDER BY id ASC'
        )->queryAll();

        $expectedPrevious = null;
        foreach ($rows as $row) {
            $payload = [
                'action' => $row['action'],
                'entity_type' => $row['entity_type'],
                'actor_user_id' => $row['actor_user_id'],
                'entity_id' => $row['entity_id'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'correlation_id' => $row['correlation_id'],
                'before_data' => $row['before_data'] === null ? [] : $row['before_data'],
                'after_data' => $row['after_data'] === null ? [] : $row['after_data'],
                'previous_hash' => $row['previous_hash'],
                'created_at' => $row['created_at'],
            ];
            $expectedHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            if ($row['previous_hash'] !== $expectedPrevious) {
                return false;
            }
            if ($row['hash_chain'] !== $expectedHash) {
                return false;
            }
            $expectedPrevious = $row['hash_chain'];
        }

        return true;
    }

    private function getLastHash(): ?string
    {
        $hash = $this->db->createCommand(
            'SELECT hash_chain FROM {{%audit_logs}} ORDER BY id DESC LIMIT 1'
        )->queryScalar();
        return is_string($hash) ? $hash : null;
    }
}
