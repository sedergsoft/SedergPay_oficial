<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

/**
 * Issues dynamic person-to-person (C2P) payment instructions that embed the
 * payee identifier (phone), amount, purpose and platform payment reference into
 * a СБП-style QR payload.
 */
interface PersonalQrProvider
{
    /**
     * @return array{payload:string,provider_payment_id:string,expires_at:string,environment:string,payee_phone:string}
     */
    public function createPersonalQr(string $amount, string $currency, string $paymentReference, string $expiresAt, string $merchantReference, string $payeePhone, string $purpose = ''): array;
}
