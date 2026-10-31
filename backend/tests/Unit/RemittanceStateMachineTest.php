<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Domain\Remittance\RemittanceStateMachine;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RemittanceStateMachineTest extends TestCase
{
    public function testAllowsComplianceReviewAfterPaymentRequest(): void
    {
        (new RemittanceStateMachine())->assertCanTransition(
            'awaiting_payment',
            'compliance_review'
        );

        self::assertTrue(true);
    }

    public function testRejectsDirectCompletion(): void
    {
        $this->expectException(DomainException::class);

        (new RemittanceStateMachine())->assertCanTransition(
            'awaiting_payment',
            'completed'
        );
    }
}
