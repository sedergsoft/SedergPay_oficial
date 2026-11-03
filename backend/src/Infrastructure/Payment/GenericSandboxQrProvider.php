<?php

declare(strict_types=1);

namespace app\\Infrastructure\\Payment;

use DomainException;

/** Local non-routable payment URI for sandbox only; never a bank QR. */
final class GenericSandboxQrProvider implements SbpC2bQrProvider
{
    public function createDynamicQr(string $amount, string $currency, string $paymentReference, string $expiresAt, string $merchantReference): array
    {
        if (!in_array($currency, ['RUB', 'CUP'], true) || !preg_match('/^\\d+(?:\\.\\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            throw new DomainException('Sandbox QR requires a positive amount and supported currency');
        }
        if ($paymentReference === '' || $merchantReference !== 'SEDERG_PAY') {
            throw new DomainException('Invalid sandbox payment or merchant reference');
        }
        $scheme = $currency === 'CUP' ? 'sedergpay-sandbox://cuba-instant-qr' : 'sedergpay-sandbox://payment';
        $payload = $scheme . '?reference=' . rawurlencode($paymentReference)
            . '&amount=' . rawurlencode($amount) . '&currency=' . rawurlencode($currency)
            . '&merchant=SEDERG_PAY';
        return ['payload' => $payload, 'provider_payment_id' => 'LOCAL-SANDBOX-' . strtoupper(bin2hex(random_bytes(8))), 'expires_at' => $expiresAt, 'environment' => 'sandbox'];
    }
}
