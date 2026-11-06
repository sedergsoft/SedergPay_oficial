<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc\Provider;

/**
 * Maps provider lifecycle values to SEDERG_PAY's stable internal KYC states.
 * Unknown provider values fail closed and must be investigated.
 */
final class BallerineStatusMapper
{
    private const MAP = [
        'created' => 'in_progress',
        'started' => 'in_progress',
        'in_progress' => 'in_progress',
        'pending_documents' => 'pending_documents',
        'documents_required' => 'pending_documents',
        'pending' => 'pending_verification',
        'pending_review' => 'pending_verification',
        'review' => 'manual_review',
        'manual_review' => 'manual_review',
        'approved' => 'pending_verification',
        'rejected' => 'pending_verification',
        'revision' => 'resubmission_required',
        'resubmission_required' => 'resubmission_required',
        'expired' => 'expired',
        'processing' => 'in_progress',
        'failed' => 'error',
    ];

    public static function map(string $providerStatus): string
    {
        $key = strtolower(trim($providerStatus));
        if (!isset(self::MAP[$key])) {
            throw new \UnexpectedValueException('Unrecognized KYC provider status');
        }

        return self::MAP[$key];
    }
}
