<?php

declare(strict_types=1);

namespace app\Domain\Ledger;

use DomainException;

final class DoubleEntryValidator
{
    /**
     * @param list<array{direction: string, amount: string, currency: string}> $entries
     */
    public function assertBalanced(array $entries): void
    {
        if (count($entries) < 2) {
            throw new DomainException('A ledger transaction requires at least two entries');
        }

        /** @var array<string, array{debit: string, credit: string}> $totals */
        $totals = [];
        foreach ($entries as $entry) {
            if (!in_array($entry['direction'], ['debit', 'credit'], true)) {
                throw new DomainException('Ledger direction must be debit or credit');
            }

            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $entry['amount'])) {
                throw new DomainException('Ledger amounts must be positive decimal strings');
            }

            if (bccomp($entry['amount'], '0', 2) <= 0) {
                throw new DomainException('Ledger amounts must be greater than zero');
            }

            $currency = strtoupper($entry['currency']);
            $totals[$currency] ??= ['debit' => '0.00', 'credit' => '0.00'];
            $totals[$currency][$entry['direction']] = bcadd(
                $totals[$currency][$entry['direction']],
                $entry['amount'],
                2
            );
        }

        foreach ($totals as $currency => $total) {
            if (bccomp($total['debit'], $total['credit'], 2) !== 0) {
                throw new DomainException("Ledger transaction is not balanced in {$currency}");
            }
        }
    }
}
