<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Payment\SbpPersonalQrProvider;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SbpPersonalQrProviderTest extends TestCase
{
    public function testCreatesPersonalDynamicQrPayloadWithPayeeAmountPurposeAndReference(): void
    {
        $result = (new SbpPersonalQrProvider())->createPersonalQr(
            '1500.50',
            'RUB',
            'REM-20261112-ABC123',
            '2026-11-13 12:00:00',
            'SEDERG_PAY',
            '+79001234567',
            'Préstamo personal a compañero'
        );

        self::assertStringStartsWith('sedergpay-sandbox://sbp-c2p?phone=', $result['payload']);
        self::assertStringContainsString('amount=1500.50', $result['payload']);
        self::assertStringContainsString('currency=RUB', $result['payload']);
        self::assertStringContainsString('purpose=', $result['payload']);
        self::assertStringContainsString('reference=', $result['payload']);
        self::assertSame('79001234567', $result['payee_phone']);
        self::assertSame('sandbox', $result['environment']);
        self::assertSame('2026-11-13 12:00:00', $result['expires_at']);
        self::assertStringStartsWith('SBP-C2P-', (string) $result['provider_payment_id']);
    }

    public function testNormalizesPayeePhoneDigitsOnly(): void
    {
        $result = (new SbpPersonalQrProvider())->createPersonalQr('100.00', 'RUB', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY', '+7 900 123 45 67', '');
        self::assertSame('79001234567', $result['payee_phone']);
        self::assertStringContainsString('phone=79001234567', $result['payload']);
    }

    public function testRejectsNonRubCurrency(): void
    {
        $this->expectException(DomainException::class);
        (new SbpPersonalQrProvider())->createPersonalQr('100.00', 'CUP', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY', '79001234567');
    }

    public function testRejectsInvalidAmount(): void
    {
        $this->expectException(DomainException::class);
        (new SbpPersonalQrProvider())->createPersonalQr('0', 'RUB', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY', '79001234567');
    }

    public function testRejectsMissingPayeePhone(): void
    {
        $this->expectException(DomainException::class);
        (new SbpPersonalQrProvider())->createPersonalQr('100.00', 'RUB', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY', '');
    }

    public function testRejectsOverlongPurpose(): void
    {
        $this->expectException(DomainException::class);
        (new SbpPersonalQrProvider())->createPersonalQr('100.00', 'RUB', 'PAY-1', '2026-11-04 12:00:00', 'SEDERG_PAY', '79001234567', str_repeat('a', 161));
    }
}
