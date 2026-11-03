<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

use DomainException;

/**
 * Non-routable local sandbox QR. This is intentionally NOT an NSPK/SBP QR.
 */
final class SbpSandboxQrProvider implements SbpC2bQrProvider
{
    public function createDynamicQr(
        string $amount,
        string $currency,
        string $paymentReference,
        string $expiresAt,
        string $merchantReference
    ): array {
        if ($currency !== 'RUB') {
            throw new DomainException('SBP C2B sandbox accepts RUB only');
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            throw new DomainException('SBP amount must be a positive RUB amount');
        }
        if ($paymentReference === '' || $merchantReference !== 'SEDERG_PAY') {
            throw new DomainException('Invalid SBP payment or merchant reference');
        }

        $payload = 'sedergpay-sandbox://sbp-c2b?reference=' . rawurlencode($paymentReference)
            . '&amount=' . rawurlencode($amount)
            . '&currency=RUB&merchant=SEDERG_PAY';

        return [
            'payload' => $payload,
            'provider_payment_id' => 'SBP-SANDBOX-' . strtoupper(bin2hex(random_bytes(8))),
            'expires_at' => $expiresAt,
            'environment' => 'sandbox',
        ];
    }
}
