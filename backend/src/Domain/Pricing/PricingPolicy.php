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

        if (bccomp($minimumSendAmount, $maximumSendAmount, 10) > 0) {
            throw new DomainException('Minimum send amount cannot exceed maximum send amount');
        }
    }

    /**
     * The configured limits are denominated in RUB. For RUB-origin corridors
     * the principal is already in RUB; for CUP -> RUB it is converted first.
     */
    public function assertSendAmountAllowed(string $sendAmount): void
    {
        $this->assertLimitAmountAllowed($sendAmount);
    }

    public function assertLimitAmountAllowed(string $amountInRub): void
    {
        if (!preg_match('/^\d+(?:\.\d{1,10})?$/', $amountInRub)) {
            throw new DomainException('The RUB equivalent must be a positive decimal');
        }

        if (bccomp($amountInRub, $this->minimumSendAmount, 10) < 0) {
            throw new DomainException('The RUB equivalent is below the configured minimum of ' . $this->minimumSendAmount . ' RUB');
        }

        if (bccomp($amountInRub, $this->maximumSendAmount, 10) > 0) {
            throw new DomainException('The RUB equivalent exceeds the configured maximum of ' . $this->maximumSendAmount . ' RUB');
        }
    }

    public function feeRatePercent(): string
    {
        return $this->feeRatePercent;
    }

    public function minimumSendAmount(): string
    {
        return bcadd($this->minimumSendAmount, '0', 2);
    }

    public function maximumSendAmount(): string
    {
        return bcadd($this->maximumSendAmount, '0', 2);
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
