<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Domain\Ledger\DoubleEntryValidator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class DoubleEntryValidatorTest extends TestCase
{
    public function testAcceptsBalancedEntriesByCurrency(): void
    {
        (new DoubleEntryValidator())->assertBalanced([
            ['direction' => 'debit', 'amount' => '100.00', 'currency' => 'RUB'],
            ['direction' => 'credit', 'amount' => '100.00', 'currency' => 'RUB'],
        ]);

        self::assertTrue(true);
    }

    public function testRejectsUnbalancedEntries(): void
    {
        $this->expectException(DomainException::class);

        (new DoubleEntryValidator())->assertBalanced([
            ['direction' => 'debit', 'amount' => '100.00', 'currency' => 'RUB'],
            ['direction' => 'credit', 'amount' => '99.99', 'currency' => 'RUB'],
        ]);
    }
}
