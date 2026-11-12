<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

use DomainException;

/**
 * Sandbox person-to-person (C2P) dynamic QR provider for SEDERG_PAY.
 *
 * The payload carries the payee phone number, the amount, the transfer purpose
 * and the platform payment reference, so a payer scanning the QR with any
 * СБП-enabled bank app (Sberbank, Tinkoff, Alfa, VTB, ...) opens a transfer with
 * the data pre-filled. This is the personal-amount variant used for informal
 * settlements (e.g. a flatmate repaying a personal loan); it does not require a
 * merchant registration with an INN because the rail is person-to-person.
 *
 * Production is not implemented until a bank/acquirer contract is validated.
 */
final class SbpPersonalQrProvider implements PersonalQrProvider
{
    public function createPersonalQr(
        string $amount,
        string $currency,
        string $paymentReference,
        string $expiresAt,
        string $merchantReference,
        string $payeePhone,
        string $purpose = ''
    ): array {
        if ($currency !== 'RUB') {
            throw new DomainException('SBP C2P sandbox accepts RUB only');
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            throw new DomainException('SBP C2P amount must be a positive RUB amount');
        }
        if ($merchantReference !== 'SEDERG_PAY') {
            throw new DomainException('Invalid SBP C2P merchant reference');
        }
        $phone = $this->normalizePhone($payeePhone);
        if ($phone === null) {
            throw new DomainException('A valid payee phone number is required for SBP C2P instructions');
        }
        if ($paymentReference === '') {
            throw new DomainException('Invalid SBP C2P payment reference');
        }
        if (mb_strlen($purpose) > 160) {
            throw new DomainException('SBP C2P purpose must not exceed 160 characters');
        }

        $payload = 'sedergpay-sandbox://sbp-c2p?phone=' . rawurlencode($phone)
            . '&amount=' . rawurlencode($amount)
            . '&currency=RUB&purpose=' . rawurlencode($purpose)
            . '&reference=' . rawurlencode($paymentReference);

        return [
            'payload' => $payload,
            'provider_payment_id' => 'SBP-C2P-' . strtoupper(bin2hex(random_bytes(8))),
            'expires_at' => $expiresAt,
            'environment' => 'sandbox',
            'payee_phone' => $phone,
        ];
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if ($digits === '' || !preg_match('/^\d{8,15}$/', $digits)) {
            return null;
        }
        return $digits;
    }
}
