<?php

declare(strict_types=1);

namespace app\Domain\Pricing;

use DomainException;
use DateTimeImmutable;
use DateTimeInterface;

final class QuoteCalculator
{
    public function calculateWithPolicy(
        array $input,
        PricingPolicy $policy,
        ?DateTimeInterface $calculatedAt = null
    ): array {
        $input['fee_rate'] = $policy->feeRatePercent();

        return $this->calculate($input, $calculatedAt, $policy);
    }

    /**
     * @param array{
     *   send_amount: string,
     *   exchange_rate: string,
     *   fee_rate: string,
     *   send_currency: string,
     *   receive_currency: string,
     *   fee_currency?: string
     * } $input
     * @return array<string, mixed>
     */
    public function calculate(
        array $input,
        ?DateTimeInterface $calculatedAt = null,
        ?PricingPolicy $policy = null
    ): array {
        $sendAmount = $this->decimal($input['send_amount'] ?? '', 'send_amount');
        $exchangeRate = $this->decimal($input['exchange_rate'] ?? '', 'exchange_rate');
        $feeRate = $this->decimal($input['fee_rate'] ?? '', 'fee_rate');
        $sendCurrency = strtoupper(trim($input['send_currency'] ?? ''));
        $receiveCurrency = strtoupper(trim($input['receive_currency'] ?? ''));
        $feeMode = trim((string) ($input['fee_mode'] ?? 'add_to_send'));
        if (!in_array($feeMode, ['add_to_send', 'deduct_from_receive'], true)) {
            throw new DomainException('fee_mode must be add_to_send or deduct_from_receive');
        }
        $feeCurrency = strtoupper(trim($input['fee_currency'] ?? ($feeMode === 'deduct_from_receive' ? $receiveCurrency : $sendCurrency)));

        if ($sendCurrency === '' || $receiveCurrency === '' || $feeCurrency === '') {
            throw new DomainException('Quote currencies are required');
        }

        // exchange_rate is expressed as receive currency per one send currency.
        // Validate the principal in RUB, before applying either commission mode.
        $grossReceiveAmount = bcmul($sendAmount, $exchangeRate, 10);
        if ($policy !== null) {
            $rubPrincipal = match (true) {
                $sendCurrency === 'RUB' => $sendAmount,
                $receiveCurrency === 'RUB' => $grossReceiveAmount,
                default => throw new DomainException('The send limits can only be evaluated for RUB/CUP corridors'),
            };
            $policy->assertLimitAmountAllowed($rubPrincipal);
        }

        $grossReceiveAmount = bcadd($grossReceiveAmount, '0', 2);
        if ($feeMode === 'deduct_from_receive') {
            $feeAmount = bcmul($grossReceiveAmount, bcdiv($feeRate, '100', 10), 2);
            $receiveAmount = bcsub($grossReceiveAmount, $feeAmount, 2);
            $totalDebitAmount = $sendAmount;
            $feeCurrency = $receiveCurrency;
        } else {
            $feeAmount = bcmul($sendAmount, bcdiv($feeRate, '100', 10), 2);
            $receiveAmount = $grossReceiveAmount;
            $totalDebitAmount = bcadd($sendAmount, $feeAmount, 2);
            $feeCurrency = $sendCurrency;
        }

        $result = [
            'send_amount' => $sendAmount,
            'receive_amount' => $receiveAmount,
            'gross_receive_amount' => $grossReceiveAmount,
            'exchange_rate' => bcadd($exchangeRate, '0', 10),
            'fee_amount' => $feeAmount,
            'fee_currency' => $feeCurrency,
            'fee_mode' => $feeMode,
            'total_debit_amount' => $totalDebitAmount,
            'calculated_at' => ($calculatedAt ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        if ($policy !== null) {
            $evaluatedAmount = $sendCurrency === 'RUB' ? $sendAmount : $grossReceiveAmount;
            $result['limits'] = [
                'currency' => 'RUB',
                'minimum' => $policy->minimumSendAmount(),
                'maximum' => $policy->maximumSendAmount(),
                'evaluated_amount' => bcadd($evaluatedAmount, '0', 2),
                'valid' => true,
            ];
        }

        return $result;
    }

    private function decimal(string $value, string $field): string
    {
        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d{1,10})?$/', $value) || bccomp($value, '0', 10) <= 0) {
            throw new DomainException("{$field} must be a positive decimal");
        }

        return $value;
    }
}
