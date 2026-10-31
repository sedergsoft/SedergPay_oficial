<?php

declare(strict_types=1);

namespace app\Domain\Pricing;

use DomainException;
use DateTimeImmutable;
use DateTimeInterface;

final class QuoteService
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly PricingPolicy $policy,
    ) {
    }

    /**
     * @param array{
     *   send_amount: string,
     *   exchange_rate: string,
     *   send_currency: string,
     *   receive_currency: string,
     *   fee_currency?: string
     * } $request
     * @return array<string, string>
     */
    public function createQuote(
        array $request,
        ?DateTimeInterface $now = null,
        int $validitySeconds = 900
    ): array {
        if ($validitySeconds < 60 || $validitySeconds > 86400) {
            throw new DomainException('Quote validity must be between 60 and 86400 seconds');
        }

        $calculatedAt = $now ?? new DateTimeImmutable();
        $expiresAt = $calculatedAt->modify("+{$validitySeconds} seconds");
        $quote = $this->calculator->calculateWithPolicy($request, $this->policy, $calculatedAt);
        $quote['expires_at'] = $expiresAt->format(DateTimeInterface::ATOM);
        $quote['status'] = 'valid';

        return $quote;
    }
}
