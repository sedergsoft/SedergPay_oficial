<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use app\Domain\Pricing\PricingPolicy;
use app\Domain\Pricing\QuoteCalculator;
use app\Domain\Pricing\QuoteService;
use app\Infrastructure\Configuration\PlatformSettings;
use yii\db\Connection;

final class QuoteFactory
{
    public static function create(?Connection $db = null): QuoteService
    {
        $feeRate = getenv('SEDERG_FEE_RATE_PERCENT') ?: '2.50';
        $minimum = getenv('SEDERG_MIN_SEND_AMOUNT') ?: '15000.00';
        $maximum = getenv('SEDERG_MAX_SEND_AMOUNT') ?: '50000.00';
        $validitySeconds = 900;
        if ($db !== null) {
            $feeRate = PlatformSettings::get($db, 'pricing.fee_rate_percent');
            $minimum = PlatformSettings::get($db, 'pricing.minimum_send_amount');
            $maximum = PlatformSettings::get($db, 'pricing.maximum_send_amount');
            $validitySeconds = (int) PlatformSettings::get($db, 'pricing.quote_validity_seconds');
        }

        return new QuoteService(
            new QuoteCalculator(),
            new PricingPolicy(
                $feeRate,
                $minimum,
                $maximum,
            ),
            $validitySeconds
        );
    }
}
