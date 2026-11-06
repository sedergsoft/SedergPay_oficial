<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc;

use app\Infrastructure\Kyc\Provider\BallerineStatusMapper;
use yii\db\Connection;
use yii\db\JsonExpression;

/**
 * Persists authenticated provider observations exactly once and applies only
 * non-final status transitions. A provider event can never approve a case.
 */
final class KycWebhookService
{
    private const PROVIDER = 'ballerine';

    public function __construct(
        private readonly Connection $db,
        private readonly string $providerName = self::PROVIDER,
    ) {
    }

    /**
     * @param array{event_id:string,provider_session_id:string,event_type:string,occurred_at:string,payload:array} $event
     * @return array{duplicate:bool,matched:bool,outcome:string,internal_status:?string,status_changed:bool}
     */
    public function record(array $event): array
    {
        $payload = $event['payload'];
        $providerStatus = $this->resolveProviderStatus($payload);
        $internalStatus = null;
        $outcome = 'observed';

        if ($providerStatus !== null) {
            try {
                $internalStatus = BallerineStatusMapper::map($providerStatus);
            } catch (\UnexpectedValueException) {
                // Unknown provider states are retained as observations only.
                $outcome = 'quarantined';
            }
        }

        $summary = $this->summarize($payload, $providerStatus, $internalStatus);
        $payloadJson = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        $transaction = $this->db->beginTransaction();
        try {
            $case = $this->db->createCommand(
                'SELECT id, public_id, status, provider_entity_id, provider_last_event_at
                 FROM {{%kyc_cases}}
                 WHERE provider_name = :provider AND provider_session_id = :session
                 LIMIT 1 FOR UPDATE',
                [':provider' => $this->providerName, ':session' => $event['provider_session_id']]
            )->queryOne();

            $eventPublicId = $this->uuid();
            $inserted = $this->db->createCommand(
                'INSERT INTO {{%kyc_provider_events}}
                    (public_id, kyc_case_id, provider_name, provider_event_id, provider_session_id,
                     event_type, provider_status, internal_status, outcome, occurred_at,
                     payload_sha256, payload_summary)
                 VALUES
                    (:public_id, :case_id, :provider, :event_id, :session_id,
                     :event_type, :provider_status, :internal_status, :outcome, :occurred_at,
                     :payload_sha256, :payload_summary)
                 ON DUPLICATE KEY UPDATE provider_event_id = provider_event_id',
                [
                    ':public_id' => $eventPublicId,
                    ':case_id' => $case === false ? null : $case['id'],
                    ':provider' => $this->providerName,
                    ':event_id' => $event['event_id'],
                    ':session_id' => $event['provider_session_id'],
                    ':event_type' => $event['event_type'],
                    ':provider_status' => $providerStatus,
                    ':internal_status' => $internalStatus,
                    ':outcome' => $case === false ? 'unmatched' : $outcome,
                    ':occurred_at' => $event['occurred_at'],
                    ':payload_sha256' => $payloadHash,
                    ':payload_summary' => $payloadJson,
                ]
            )->execute();

            if ($inserted === 0) {
                $transaction->commit();

                return [
                    'duplicate' => true,
                    'matched' => $case !== false,
                    'outcome' => 'duplicate',
                    'internal_status' => null,
                    'status_changed' => false,
                ];
            }

            if ($case === false) {
                $this->finishEvent($event['event_id'], 'unmatched', null);
                $transaction->commit();

                return [
                    'duplicate' => false,
                    'matched' => false,
                    'outcome' => 'unmatched',
                    'internal_status' => null,
                    'status_changed' => false,
                ];
            }

            $lastEventAt = (string) ($case['provider_last_event_at'] ?? '');
            if ($lastEventAt !== '' && $event['occurred_at'] < $lastEventAt) {
                $this->finishEvent($event['event_id'], 'out_of_order', null);
                $transaction->commit();

                return [
                    'duplicate' => false,
                    'matched' => true,
                    'outcome' => 'out_of_order',
                    'internal_status' => $internalStatus,
                    'status_changed' => false,
                ];
            }

            $statusChanged = false;
            if ($internalStatus !== null && !in_array($case['status'], ['approved', 'rejected'], true)) {
                $statusChanged = $internalStatus !== $case['status'];
            }

            $update = [
                'provider_last_event_at' => $event['occurred_at'],
                'provider_last_event_id' => $event['event_id'],
            ];
            if ($providerStatus !== null) {
                $update['provider_status'] = $providerStatus;
            }
            $entityId = $payload['ballerineEntityId'] ?? null;
            if (is_string($entityId) && preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $entityId)) {
                $update['provider_entity_id'] = $entityId;
            }
            if ($statusChanged) {
                $update['status'] = $internalStatus;
            }
            $this->db->createCommand()->update(
                '{{%kyc_cases}}',
                $update,
                ['id' => $case['id']]
            )->execute();

            $finalOutcome = $outcome === 'quarantined' ? 'quarantined' : ($statusChanged ? 'applied' : 'observed');
            $this->finishEvent($event['event_id'], $finalOutcome, $internalStatus);

            $this->db->createCommand()->insert('{{%audit_logs}}', [
                'actor_user_id' => null,
                'action' => 'kyc.provider.event_processed',
                'entity_type' => 'kyc_case',
                'entity_id' => (string) $case['public_id'],
                'after_data' => new JsonExpression([
                    'provider' => $this->providerName,
                    'event_id' => $event['event_id'],
                    'event_type' => $event['event_type'],
                    'provider_status' => $providerStatus,
                    'internal_status' => $internalStatus,
                    'status_changed' => $statusChanged,
                    'outcome' => $finalOutcome,
                ]),
            ])->execute();

            $transaction->commit();

            return [
                'duplicate' => false,
                'matched' => true,
                'outcome' => $finalOutcome,
                'internal_status' => $internalStatus,
                'status_changed' => $statusChanged,
            ];
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function finishEvent(string $eventId, string $outcome, ?string $internalStatus): void
    {
        $this->db->createCommand()->update('{{%kyc_provider_events}}', [
            'outcome' => $outcome,
            'internal_status' => $internalStatus,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ], [
            'provider_name' => $this->providerName,
            'provider_event_id' => $eventId,
        ])->execute();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveProviderStatus(array $payload): ?string
    {
        $state = $this->normalizeStatus($payload['state'] ?? null);
        if ($state !== null) {
            return $state;
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $decision = $data['decision'] ?? null;
        if (is_array($decision) && ($status = $this->normalizeStatus($decision['status'] ?? null)) !== null) {
            return $status;
        }

        $documents = $data['documents'] ?? null;
        if (!is_array($documents) || $documents === []) {
            return null;
        }

        $statuses = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }
            $documentDecision = $document['decision'] ?? null;
            if (is_array($documentDecision) && ($status = $this->normalizeStatus($documentDecision['status'] ?? null)) !== null) {
                $statuses[] = $status;
            }
        }
        if ($statuses === []) {
            return null;
        }
        if (in_array('revision', $statuses, true)) {
            return 'revision';
        }
        if (in_array('rejected', $statuses, true)) {
            return 'rejected';
        }
        $unique = array_values(array_unique($statuses));

        return count($unique) === 1 && $unique[0] === 'approved' ? 'approved' : null;
    }

    /**
     * Provider statuses are observational data. Ignore values that cannot be
     * stored in the bounded column instead of breaking webhook delivery.
     */
    private function normalizeStatus(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 60) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function summarize(array $payload, ?string $providerStatus, ?string $internalStatus): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $decision = is_array($data['decision'] ?? null) ? $data['decision'] : [];
        $documents = [];
        if (is_array($data['documents'] ?? null)) {
            foreach ($data['documents'] as $document) {
                if (!is_array($document)) {
                    continue;
                }
                $documentDecision = is_array($document['decision'] ?? null) ? $document['decision'] : [];
                $documents[] = [
                    'id' => is_scalar($document['id'] ?? null) ? mb_substr((string) $document['id'], 0, 190) : null,
                    'status' => is_scalar($documentDecision['status'] ?? null) ? mb_substr((string) $documentDecision['status'], 0, 60) : null,
                ];
            }
        }

        return array_filter([
            'event_name' => is_scalar($payload['eventName'] ?? null) ? mb_substr((string) $payload['eventName'], 0, 190) : null,
            'state' => is_scalar($payload['state'] ?? null) ? mb_substr((string) $payload['state'], 0, 60) : null,
            'workflow_definition_id' => is_scalar($payload['workflowDefinitionId'] ?? null) ? mb_substr((string) $payload['workflowDefinitionId'], 0, 190) : null,
            'ballerine_entity_id' => is_scalar($payload['ballerineEntityId'] ?? null) ? mb_substr((string) $payload['ballerineEntityId'], 0, 190) : null,
            'correlation_id' => is_scalar($payload['correlationId'] ?? null) ? mb_substr((string) $payload['correlationId'], 0, 190) : null,
            'environment' => is_scalar($payload['environment'] ?? null) ? mb_substr((string) $payload['environment'], 0, 60) : null,
            'provider_status' => $providerStatus,
            'internal_status' => $internalStatus,
            'decision_reason' => is_scalar($decision['reason'] ?? null) ? mb_substr((string) $decision['reason'], 0, 500) : null,
            'documents' => $documents,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
