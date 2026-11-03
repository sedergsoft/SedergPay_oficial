<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

/**
 * Contract for a bank-acquirer integration that issues SBP C2B dynamic QR codes.
 * Implementations must return the payload supplied by the acquirer, not construct
 * a production SBP payload themselves.
 */
interface SbpC2bQrProvider
{
    /** @return array{payload:string,provider_payment_id:string,expires_at:string,environment:string} */
    public function createDynamicQr(
        string $amount,
        string $currency,
        string $paymentReference,
        string $expiresAt,
        string $merchantReference
    ): array;
}
