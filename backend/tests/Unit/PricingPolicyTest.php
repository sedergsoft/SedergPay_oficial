<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Domain\Pricing\PricingPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PricingPolicyTest extends TestCase
{
    public function testAcceptsConfiguredRange(): void
    {
        $policy = new PricingPolicy('2.50', '15000.00', '50000.00');

        $policy->assertSendAmountAllowed('25000.00');
        self::assertSame('2.50', $policy->feeRatePercent());
    }

    public function testRejectsAmountOutsideConfiguredRange(): void
    {
        $this->expectException(DomainException::class);

        (new PricingPolicy('2.50', '15000.00', '50000.00'))
            ->assertSendAmountAllowed('50000.01');
    }
}
