<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Kyc\Provider\BallerineStatusMapper;
use PHPUnit\Framework\TestCase;

final class BallerineStatusMapperTest extends TestCase
{
    public function testProviderApprovalDoesNotBecomeInternalApproval(): void
    {
        self::assertSame('pending_verification', BallerineStatusMapper::map('approved'));
    }

    public function testManualReviewMapsToManualReview(): void
    {
        self::assertSame('manual_review', BallerineStatusMapper::map('manual_review'));
    }

    public function testRevisionMapsToResubmissionRequired(): void
    {
        self::assertSame('resubmission_required', BallerineStatusMapper::map('revision'));
    }

    public function testUnknownProviderStatusFailsClosed(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        BallerineStatusMapper::map('unknown_vendor_decision');
    }
}
