<?php

declare(strict_types=1);

namespace app\Domain\Remittance;

use DomainException;

final class RemittanceStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'awaiting_payment' => ['compliance_review', 'cancelled'],
        'compliance_review' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['funds_confirmed', 'cancelled'],
        'funds_confirmed' => ['payout_pending', 'cancelled'],
        'payout_pending' => ['paid', 'payout_failed'],
        'payout_failed' => ['payout_pending', 'cancelled'],
        'paid' => ['reconciliation_pending'],
        'reconciliation_pending' => ['completed', 'reconciliation_exception'],
        'reconciliation_exception' => ['reconciliation_pending', 'completed'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function assertCanTransition(string $from, string $to): void
    {
        $allowed = self::TRANSITIONS[$from] ?? null;
        if ($allowed === null) {
            throw new DomainException("Unknown remittance status: {$from}");
        }

        if (!in_array($to, $allowed, true)) {
            throw new DomainException("Transition from {$from} to {$to} is not allowed");
        }
    }
}
