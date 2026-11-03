<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Domain\Pricing\QuoteCalculator;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class QuoteCalculatorTest extends TestCase
{
    public function testCalculatesAmountsWithoutFloatingPoint(): void
    {
        $result = (new QuoteCalculator())->calculate([
            'send_amount' => '15000.00',
            'exchange_rate' => '0.1200000000',
            'fee_rate' => '3.00',
            'send_currency' => 'RUB',
            'receive_currency' => 'CUP',
        ], new DateTimeImmutable('2026-10-30T12:00:00+03:00'));

        self::assertSame('1800.00', $result['receive_amount']);
        self::assertSame('450.00', $result['fee_amount']);
        self::assertSame('15450.00', $result['total_debit_amount']);
        self::assertSame('RUB', $result['fee_currency']);
        self::assertSame('add_to_send', $result['fee_mode']);
    }

    public function testDeductsCommissionFromBeneficiaryReceiveAmount(): void
    {
        $result = (new QuoteCalculator())->calculate([
            'send_amount' => '15000.00',
            'exchange_rate' => '0.1200000000',
            'fee_rate' => '3.00',
            'fee_mode' => 'deduct_from_receive',
            'send_currency' => 'RUB',
            'receive_currency' => 'CUP',
        ], new DateTimeImmutable('2026-10-30T12:00:00+03:00'));

        self::assertSame('1800.00', $result['gross_receive_amount']);
        self::assertSame('54.00', $result['fee_amount']);
        self::assertSame('1746.00', $result['receive_amount']);
        self::assertSame('15000.00', $result['total_debit_amount']);
        self::assertSame('CUP', $result['fee_currency']);
        self::assertSame('deduct_from_receive', $result['fee_mode']);
    }

    public function testRejectsInvalidAmount(): void
    {
        $this->expectException(DomainException::class);

        (new QuoteCalculator())->calculate([
            'send_amount' => '0',
            'exchange_rate' => '0.12',
            'fee_rate' => '2.5',
            'send_currency' => 'RUB',
            'receive_currency' => 'CUP',
        ]);
    }
}
