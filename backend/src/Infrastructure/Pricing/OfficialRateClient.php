<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use RuntimeException;

final class OfficialRateClient
{
    public const BCC_SOURCE_URL = 'https://api.bc.gob.cu/v1/tasas-de-cambio/activas';
    public const CBR_SOURCE_URL = 'https://www.cbr.ru/scripts/XML_daily_eng.asp';

    /**
     * Returns CUP per one RUB from an official Cuban source, with Russian fallback.
     *
     * @return array{rate: string, effective_date: string, source_url: string}
     */
    public function fetchRubCup(): array
    {
        try {
            return $this->fetchFromBcc();
        } catch (RuntimeException $bccException) {
            try {
                return $this->fetchFromCbr();
            } catch (RuntimeException $cbrException) {
                throw new RuntimeException(
                    'Official exchange-rate sources are unavailable',
                    0,
                    $cbrException
                );
            }
        }
    }

    /**
     * The BCC special segment is the official rate for population and FGNE.
     *
     * @return array{rate: string, effective_date: string, source_url: string, source: string}
     */
    private function fetchFromBcc(): array
    {
        $payload = $this->fetchJson(self::BCC_SOURCE_URL);
        if (!isset($payload['tasas']) || !is_array($payload['tasas'])) {
            throw new RuntimeException('BCC exchange-rate response is invalid');
        }

        foreach ($payload['tasas'] as $currency) {
            if (!is_array($currency) || ($currency['codigoMoneda'] ?? '') !== 'RUB') {
                continue;
            }
            $rate = $this->decimal((string) ($currency['tasaEspecial'] ?? ''), 'BCC rate');
            $date = substr((string) ($currency['fechaDia'] ?? ''), 0, 10);
            if ($date === '' || !\DateTimeImmutable::createFromFormat('Y-m-d', $date)) {
                throw new RuntimeException('BCC exchange-rate date is invalid');
            }

            return [
                'rate' => $rate,
                'effective_date' => $date,
                'source_url' => self::BCC_SOURCE_URL,
                'source' => 'bcc_official',
            ];
        }

        throw new RuntimeException('BCC exchange-rate source does not publish RUB');
    }

    /**
     * CBR publishes RUB per CUP, so the value is inverted to CUP per RUB.
     *
     * @return array{rate: string, effective_date: string, source_url: string, source: string}
     */
    private function fetchFromCbr(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => "Accept: application/xml\r\nUser-Agent: SEDERG_PAY/1.0\r\n",
            ],
        ]);
        $xml = @file_get_contents(self::CBR_SOURCE_URL, false, $context);
        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException('Official exchange-rate source is unavailable');
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        if ($document === false) {
            libxml_clear_errors();
            throw new RuntimeException('Official exchange-rate source returned invalid XML');
        }
        libxml_clear_errors();

        $date = trim((string) $document['Date']);
        $cup = null;
        foreach ($document->Valute as $currency) {
            if (strtoupper(trim((string) $currency->CharCode)) === 'CUP') {
                $cup = $currency;
                break;
            }
        }
        if ($cup === null || $date === '') {
            throw new RuntimeException('Official exchange-rate source does not publish CUP');
        }

        $nominal = $this->decimal((string) $cup->Nominal, 'nominal');
        $value = $this->decimal((string) $cup->Value, 'value');
        if (bccomp($value, '0', 10) <= 0) {
            throw new RuntimeException('Official CUP exchange rate is invalid');
        }

        $effectiveDate = \DateTimeImmutable::createFromFormat('d.m.Y', $date);
        if ($effectiveDate === false) {
            throw new RuntimeException('Official exchange-rate date is invalid');
        }

        return [
            'rate' => bcdiv($nominal, $value, 10),
            'effective_date' => $effectiveDate->format('Y-m-d'),
            'source_url' => self::CBR_SOURCE_URL,
            'source' => 'cbr_official',
        ];
    }

    private function fetchJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: SEDERG_PAY/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || trim($body) === '') {
            throw new RuntimeException('Official JSON source is unavailable');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Official JSON source returned invalid data');
        }

        return $decoded;
    }

    private function decimal(string $value, string $field): string
    {
        $normalized = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            throw new RuntimeException("Official exchange-rate {$field} is invalid");
        }

        return $normalized;
    }
}
