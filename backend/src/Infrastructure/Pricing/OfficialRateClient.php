<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use RuntimeException;

final class OfficialRateClient
{
    public const BCC_SOURCE_URL = 'https://api.bc.gob.cu/v1/tasas-de-cambio/activas';
    public const CBR_SOURCE_URL = 'https://www.cbr.ru/scripts/XML_daily_eng.asp';

    /**
     * Returns both RUB/CUP (buy/compra) and CUP/RUB (sell/venta) rates from an official source.
     *
     * BCC publishes:
     *   - tasaEspecial: CUP per RUB  (buy rate: how many CUP you get for 1 RUB)
     *   - tasaOficial:  RUB per CUP  (sell rate: how many RUB you get for 1 CUP)
     *
     * @return array{
     *   rate_rub_cup: string,
     *   rate_cup_rub: string,
     *   effective_date: string,
     *   source: string
     * }
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
     * The BCC special segment publishes CUP per RUB (tasaEspecial) and RUB per CUP (tasaOficial).
     *
     * @return array{rate_rub_cup: string, rate_cup_rub: string, effective_date: string, source: string}
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

            $rateRubCup = $this->decimal((string) ($currency['tasaEspecial'] ?? ''), 'BCC tasaEspecial (RUB/CUP buy)');
            $rateCupRub = $this->decimal((string) ($currency['tasaOficial'] ?? ''), 'BCC tasaOficial (CUP/RUB sell)');
            $date = substr((string) ($currency['fechaDia'] ?? ''), 0, 10);
            if ($date === '' || !\DateTimeImmutable::createFromFormat('Y-m-d', $date)) {
                throw new RuntimeException('BCC exchange-rate date is invalid');
            }

            return [
                'rate_rub_cup' => $rateRubCup,
                'rate_cup_rub' => $rateCupRub,
                'effective_date' => $date,
                'source' => 'bcc_official',
            ];
        }

        throw new RuntimeException('BCC exchange-rate source does not publish RUB');
    }

    /**
     * CBR publishes RUB per CUP. We invert to get CUP per RUB.
     *
     * @return array{rate_rub_cup: string, rate_cup_rub: string, effective_date: string, source: string}
     */
    private function fetchFromCbr(): array
    {
        $xml = $this->fetchXml(self::CBR_SOURCE_URL);

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

        if ($cup === null) {
            throw new RuntimeException('Official exchange-rate source does not publish CUP');
        }

        $nominal = $this->decimal((string) $cup->Nominal, 'nominal');
        $value = $this->decimal((string) $cup->Value, 'value');
        if ($nominal === '' || $value === '') {
            throw new RuntimeException('Official CUP exchange rate is invalid');
        }
        if ($date === '' || !\DateTimeImmutable::createFromFormat('d/m/Y', $date)) {
            throw new RuntimeException('Official exchange-rate date is invalid');
        }

        // CBR Value/Nominal = RUB per CUP (sell rate: how many RUB for 1 CUP)
        $rateCupRub = bcdiv($value, $nominal, 10);
        // Derive CUP per RUB (buy rate: how many CUP for 1 RUB)
        $rateRubCup = bcdiv('1', $rateCupRub, 10);

        $isoDate = \DateTimeImmutable::createFromFormat('d/m/Y', $date)->format('Y-m-d');

        return [
            'rate_rub_cup' => $rateRubCup,
            'rate_cup_rub' => $rateCupRub,
            'effective_date' => $isoDate,
            'source' => 'cbr_official',
        ];
    }

    private function fetchJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: SEDERG_PAY/1.0',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $json = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($json === false || trim($json) === '' || $httpCode >= 400) {
            throw new RuntimeException('Official exchange-rate source is unavailable (HTTP ' . $httpCode . ')');
        }
        $data = json_decode($json, true);
        if ($data === null || !is_array($data)) {
            throw new RuntimeException('Official exchange-rate source returned invalid JSON');
        }
        return $data;
    }

    private function fetchXml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/xml',
            'User-Agent: SEDERG_PAY/1.0',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $xml = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($xml === false || trim($xml) === '' || $httpCode >= 400) {
            throw new RuntimeException('Official exchange-rate source is unavailable (HTTP ' . $httpCode . ')');
        }
        return $xml;
    }

    private function decimal(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            throw new RuntimeException("Invalid {$field}");
        }
        return (string) bcadd($trimmed, '0', 10);
    }
}
