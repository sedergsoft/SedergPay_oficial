<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

/** Common contract for sandbox or bank-issued dynamic payment QR providers. */
interface DynamicQrProvider
{
    /** @return array{payload:string,provider_payment_id:string,expires_at:string,environment:string} */
    public function createDynamicQr(string $amount, string $currency, string $paymentReference, string $expiresAt, string $merchantReference): array;
}
