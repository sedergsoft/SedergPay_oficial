<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use app\Domain\Pricing\PricingPolicy;
use app\Domain\Pricing\QuoteCalculator;
use app\Domain\Pricing\QuoteService;

final class QuoteFactory
{
    public static function create(): QuoteService
    {
        return new QuoteService(
            new QuoteCalculator(),
            new PricingPolicy(
                getenv('SEDERG_FEE_RATE_PERCENT') ?: '2.50',
                getenv('SEDERG_MIN_SEND_AMOUNT') ?: '15000.00',
                getenv('SEDERG_MAX_SEND_AMOUNT') ?: '50000.00',
            )
        );
    }
}
