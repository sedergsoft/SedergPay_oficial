<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Domain\Pricing\PricingPolicy;
use app\Domain\Pricing\QuoteCalculator;
use app\Domain\Pricing\QuoteService;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class QuoteServiceTest extends TestCase
{
    public function testCreatesExpiringQuoteUsingServerPolicy(): void
    {
        $service = new QuoteService(
            new QuoteCalculator(),
            new PricingPolicy('2.50', '15000.00', '50000.00')
        );

        $quote = $service->createQuote([
            'send_amount' => '15000.00',
            'exchange_rate' => '0.1200000000',
            'send_currency' => 'RUB',
            'receive_currency' => 'CUP',
        ], new DateTimeImmutable('2026-10-30T12:00:00+03:00'));

        self::assertSame('375.00', $quote['fee_amount']);
        self::assertSame('2026-10-30T12:15:00+03:00', $quote['expires_at']);
        self::assertSame('valid', $quote['status']);
    }

    public function testRejectsUnboundedValidity(): void
    {
        $this->expectException(DomainException::class);

        (new QuoteService(
            new QuoteCalculator(),
            new PricingPolicy('2.50', '15000.00', '50000.00')
        ))->createQuote([
            'send_amount' => '15000.00',
            'exchange_rate' => '0.12',
            'send_currency' => 'RUB',
            'receive_currency' => 'CUP',
        ], null, 30);
    }
}
