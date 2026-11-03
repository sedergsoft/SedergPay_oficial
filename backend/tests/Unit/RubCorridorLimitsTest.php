<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Domain\Pricing\PricingPolicy;
use app\Domain\Pricing\QuoteCalculator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RubCorridorLimitsTest extends TestCase
{
    private function policy(): PricingPolicy
    {
        return new PricingPolicy('3.00', '15000.00', '50000.00');
    }

    public function testCubaToRussiaEvaluatesPrincipalConvertedToRubBeforeCommission(): void
    {
        $calculator = new QuoteCalculator();
        foreach (['add_to_send', 'deduct_from_receive'] as $feeMode) {
            $quote = $calculator->calculateWithPolicy([
                'send_amount' => '250000.00',
                'exchange_rate' => '0.1000000000',
                'fee_mode' => $feeMode,
                'send_currency' => 'CUP',
                'receive_currency' => 'RUB',
            ], $this->policy());

            self::assertSame('25000.00', $quote['limits']['evaluated_amount']);
            self::assertSame('RUB', $quote['limits']['currency']);
            self::assertSame('15000.00', $quote['limits']['minimum']);
            self::assertSame('50000.00', $quote['limits']['maximum']);
            self::assertTrue($quote['limits']['valid']);
            self::assertSame('25000.00', $quote['gross_receive_amount']);
        }
    }

    public function testCubaToRussiaAcceptsExactRubBoundaries(): void
    {
        $calculator = new QuoteCalculator();
        foreach ([
            ['150000.00', '15000.00'],
            ['500000.00', '50000.00'],
        ] as [$cupAmount, $expectedRub]) {
            $quote = $calculator->calculateWithPolicy([
                'send_amount' => $cupAmount,
                'exchange_rate' => '0.1000000000',
                'send_currency' => 'CUP',
                'receive_currency' => 'RUB',
            ], $this->policy());

            self::assertSame($expectedRub, $quote['limits']['evaluated_amount']);
        }
    }

    public function testCubaToRussiaRejectsAmountBelowRubMinimum(): void
    {
        $this->expectException(DomainException::class);
        (new QuoteCalculator())->calculateWithPolicy([
            'send_amount' => '149999.99',
            'exchange_rate' => '0.1000000000',
            'send_currency' => 'CUP',
            'receive_currency' => 'RUB',
        ], $this->policy());
    }

    public function testCubaToRussiaRejectsAmountAboveRubMaximum(): void
    {
        $this->expectException(DomainException::class);
        (new QuoteCalculator())->calculateWithPolicy([
            'send_amount' => '500000.01',
            'exchange_rate' => '0.1000000000',
            'send_currency' => 'CUP',
            'receive_currency' => 'RUB',
        ], $this->policy());
    }

    public function testRussiaToCubaStillEvaluatesSendPrincipalInRub(): void
    {
        $quote = (new QuoteCalculator())->calculateWithPolicy([
            'send_amount' => '25000.00',
            'exchange_rate' => '10.0000000000',
            'send_currency' => 'RUB',
            'receive_currency' => 'CUP',
        ], $this->policy());

        self::assertSame('25000.00', $quote['limits']['evaluated_amount']);
        self::assertSame('250000.00', $quote['gross_receive_amount']);
    }
}
