<?php

declare(strict_types=1);

namespace app\Domain\Pricing;

use DomainException;

final class PricingPolicy
{
    public function __construct(
        private readonly string $feeRatePercent,
        private readonly string $minimumSendAmount,
        private readonly string $maximumSendAmount,
    ) {
        $this->assertPositive($feeRatePercent, 'feeRatePercent', true);
        $this->assertPositive($minimumSendAmount, 'minimumSendAmount', false);
        $this->assertPositive($maximumSendAmount, 'maximumSendAmount', false);

        if (bccomp($minimumSendAmount, $maximumSendAmount, 2) > 0) {
            throw new DomainException('Minimum send amount cannot exceed maximum send amount');
        }
    }

    public function assertSendAmountAllowed(string $sendAmount): void
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $sendAmount)) {
            throw new DomainException('send_amount must be a positive decimal');
        }

        if (bccomp($sendAmount, $this->minimumSendAmount, 2) < 0) {
            throw new DomainException('send_amount is below the configured minimum');
        }

        if (bccomp($sendAmount, $this->maximumSendAmount, 2) > 0) {
            throw new DomainException('send_amount exceeds the configured maximum');
        }
    }

    public function feeRatePercent(): string
    {
        return $this->feeRatePercent;
    }

    private function assertPositive(string $value, string $field, bool $allowZero): void
    {
        $valid = preg_match('/^\d+(?:\.\d{1,10})?$/', $value) === 1;
        $comparison = $allowZero ? bccomp($value, '0', 10) >= 0 : bccomp($value, '0', 10) > 0;
        if (!$valid || !$comparison) {
            throw new DomainException("{$field} must be a valid positive decimal");
        }
    }
}
