<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Payment\SbpSandboxQrProvider;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SbpSandboxQrProviderTest extends TestCase
{
    public function testCreatesClearlyNonRoutableDynamicSandboxPayload(): void
    {
        $result = (new SbpSandboxQrProvider())->createDynamicQr(
            '15000.00',
            'RUB',
            'PAY-ABC123',
            '2026-11-04 12:00:00',
            'SEDERG_PAY'
        );

        self::assertStringStartsWith('sedergpay-sandbox://sbp-c2b?', $result['payload']);
        self::assertSame('sandbox', $result['environment']);
        self::assertSame('2026-11-04 12:00:00', $result['expires_at']);
        self::assertStringStartsWith('SBP-SANDBOX-', $result['provider_payment_id']);
    }

    public function testRejectsNonRubCurrency(): void
    {
        $this->expectException(DomainException::class);
        (new SbpSandboxQrProvider())->createDynamicQr('100.00', 'CUP', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY');
    }

    public function testRejectsInvalidAmount(): void
    {
        $this->expectException(DomainException::class);
        (new SbpSandboxQrProvider())->createDynamicQr('-10', 'RUB', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY');
    }
}
