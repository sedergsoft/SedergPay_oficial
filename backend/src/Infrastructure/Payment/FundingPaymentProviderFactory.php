<?php

declare(strict_types=1);

namespace app\\Infrastructure\\Payment;

use DomainException;

/** Selects a payment provider explicitly by code and environment. */
final class FundingPaymentProviderFactory
{
    public function for(string $providerCode, string $environment): SbpC2bQrProvider
    {
        if ($environment !== 'sandbox') {
            throw new DomainException('Production payment providers are not configured until the bank contract is validated');
        }

        return match ($providerCode) {
            'sbp_c2b_sandbox' => new SbpSandboxQrProvider(),
            'sedergpay_sandbox' => new GenericSandboxQrProvider(),
            default => throw new DomainException('Unsupported or unconfigured funding provider'),
        };
    }
}
