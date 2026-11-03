<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Payment\FundingPaymentProviderFactory;
use app\Infrastructure\Payment\GenericSandboxQrProvider;
use app\Infrastructure\Payment\SbpSandboxQrProvider;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FundingPaymentProviderFactoryTest extends TestCase
{
    public function testResolvesOnlyKnownSandboxProviders(): void
    {
        $factory = new FundingPaymentProviderFactory();
        self::assertInstanceOf(SbpSandboxQrProvider::class, $factory->for('sbp_c2b_sandbox', 'sandbox'));
        self::assertInstanceOf(GenericSandboxQrProvider::class, $factory->for('sedergpay_sandbox', 'sandbox'));
    }

    public function testRejectsProductionUntilContractAdapterExists(): void
    {
        $this->expectException(DomainException::class);
        (new FundingPaymentProviderFactory())->for('sbp_c2b_sandbox', 'production');
    }

    public function testRejectsUnknownProvider(): void
    {
        $this->expectException(DomainException::class);
        (new FundingPaymentProviderFactory())->for('unknown', 'sandbox');
    }

    public function testCubaSandboxPayloadRemainsNonRoutable(): void
    {
        $result = (new GenericSandboxQrProvider())->createDynamicQr('2500.00', 'CUP', 'PAY-CU-001', '2026-11-04 12:00:00', 'SEDERG_PAY');
        self::assertStringStartsWith('sedergpay-sandbox://cuba-instant-qr?', $result['payload']);
        self::assertSame('sandbox', $result['environment']);
    }
}
