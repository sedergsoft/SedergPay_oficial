<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use DateTimeImmutable;
use DomainException;
use app\\Infrastructure\\Configuration\\PlatformSettings;
use yii\db\Connection;
use yii\db\Transaction;

final class ExchangeRateService
{
    private const ADMIN_SOURCE = 'admin_override';

    public function __construct(
        private readonly Connection $db,
        private readonly OfficialRateClient $officialClient = new OfficialRateClient()
    ) {
    }

    /**
     * @return array{
     *   base_currency: string,
     *   quote_currency: string,
     *   rate: string,
     *   source: string,
     *   source_label: string,
     *   effective_date: string,
     *   source_url: ?string,
     *   manual_override: bool
     * }
     */
    public function resolve(string $baseCurrency, string $quoteCurrency): array
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $quoteCurrency = strtoupper(trim($quoteCurrency));
        if ($baseCurrency === $quoteCurrency
            || !in_array($baseCurrency, ['RUB', 'CUP'], true)
            || !in_array($quoteCurrency, ['RUB', 'CUP'], true)) {
            throw new DomainException('Only RUB/CUP exchange rates are supported');
        }

        $activeSource = PlatformSettings::get($this->db, 'pricing.exchange_rate_source');
        $direct = $activeSource === 'admin_override'
            ? $this->findEffective('RUB', 'CUP', self::ADMIN_SOURCE)
            : $this->findEffectiveOfficial();
        if ($direct === null && $activeSource === 'admin_override') {
            throw new DomainException('No existe una tasa manual activa. Seleccione la tasa oficial o defina una tasa manual.');
        }
        if ($direct === null) {
            $official = $this->officialClient->fetchRubCup();
            $this->saveOfficial($official);
            $direct = [
                'rate' => $official['rate'],
                'source' => $official['source'],
                'effective_date' => $official['effective_date'],
                'source_url' => $official['source_url'],
                'manual_override' => false,
            ];
        }

        if ($baseCurrency === 'RUB') {
            return $this->format($baseCurrency, $quoteCurrency, $direct);
        }

        $direct['rate'] = bcdiv('1', $direct['rate'], 10);
        return $this->format($baseCurrency, $quoteCurrency, $direct);
    }

    /**
     * @return array{
     *   base_currency: string,
     *   quote_currency: string,
     *   rate: string,
     *   source: string,
     *   source_label: string,
     *   effective_date: string,
     *   source_url: ?string,
     *   manual_override: bool
     * }
     */
    public function refreshOfficial(): array
    {
        $official = $this->officialClient->fetchRubCup();
        $this->saveOfficial($official);

        return $this->resolve('RUB', 'CUP');
    }

    public function saveAdminOverride(string $rate, int $userId): array
    {
        $rate = trim($rate);
        if (!preg_match('/^\d+(?:\.\d{1,10})?$/', $rate) || bccomp($rate, '0', 10) <= 0) {
            throw new DomainException('Exchange rate must be a positive decimal');
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $transaction = $this->db->beginTransaction();
        try {
            $this->db->createCommand()->update(
                '{{%exchange_rates}}',
                ['valid_until' => $now->format('Y-m-d H:i:s')],
                [
                    'base_currency' => 'RUB',
                    'quote_currency' => 'CUP',
                    'source' => self::ADMIN_SOURCE,
                    'valid_until' => null,
                ]
            )->execute();
            $this->db->createCommand()->insert('{{%exchange_rates}}', [
                'base_currency' => 'RUB',
                'quote_currency' => 'CUP',
                'rate' => $rate,
                'source' => self::ADMIN_SOURCE,
                'valid_from' => $now->format('Y-m-d H:i:s'),
                'created_by_user_id' => $userId,
            ])->execute();
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        $this->db->createCommand()->upsert('{{%platform_settings}}', [
            'setting_key' => 'pricing.exchange_rate_source',
            'setting_value' => 'admin_override',
            'value_type' => 'enum',
            'updated_by_user_id' => $userId,
        ], [
            'setting_value' => 'admin_override',
            'value_type' => 'enum',
            'updated_by_user_id' => $userId,
        ])->execute();

        return $this->resolve('RUB', 'CUP');
    }

    public function setActiveSource(string $source, int $userId): array
    {
        if (!in_array($source, ['official', 'admin_override'], true)) {
            throw new DomainException('La fuente debe ser official o admin_override.');
        }
        if ($source === 'admin_override' && $this->findEffective('RUB', 'CUP', self::ADMIN_SOURCE) === null) {
            throw new DomainException('No hay una tasa manual guardada para activar.');
        }
        $this->db->createCommand()->upsert('{{%platform_settings}}', [
            'setting_key' => 'pricing.exchange_rate_source',
            'setting_value' => $source,
            'value_type' => 'enum',
            'updated_by_user_id' => $userId,
        ], [
            'setting_value' => $source,
            'value_type' => 'enum',
            'updated_by_user_id' => $userId,
        ])->execute();

        return $this->resolve('RUB', 'CUP');
    }

    private function findEffective(string $base, string $quote, string $source): ?array
    {
        $row = $this->db->createCommand(
            'SELECT rate, source, valid_from, created_at
             FROM {{%exchange_rates}}
             WHERE base_currency = :base
               AND quote_currency = :quote
               AND source = :source
               AND valid_from <= UTC_TIMESTAMP()
               AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())
             ORDER BY valid_from DESC, id DESC
             LIMIT 1',
            [':base' => $base, ':quote' => $quote, ':source' => $source]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return [
            'rate' => (string) $row['rate'],
            'source' => (string) $row['source'],
            'effective_date' => (new DateTimeImmutable((string) $row['valid_from']))->format('Y-m-d'),
            'source_url' => $source === self::ADMIN_SOURCE
                ? null
                : ($source === 'bcc_official'
                    ? OfficialRateClient::BCC_SOURCE_URL
                    : OfficialRateClient::CBR_SOURCE_URL),
            'manual_override' => $source === self::ADMIN_SOURCE,
        ];
    }

    private function findEffectiveOfficial(): ?array
    {
        $rows = $this->db->createCommand(
            'SELECT rate, source, valid_from
             FROM {{%exchange_rates}}
             WHERE base_currency = :base
               AND quote_currency = :quote
               AND source IN (:bcc, :cbr)
               AND valid_from <= UTC_TIMESTAMP()
               AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())
             ORDER BY CASE source WHEN :bcc_order THEN 0 ELSE 1 END,
                      valid_from DESC, id DESC
             LIMIT 1',
            [
                ':base' => 'RUB',
                ':quote' => 'CUP',
                ':bcc' => 'bcc_official',
                ':cbr' => 'cbr_official',
                ':bcc_order' => 'bcc_official',
            ]
        )->queryOne();
        if ($rows === false) {
            return null;
        }

        $source = (string) $rows['source'];
        return [
            'rate' => (string) $rows['rate'],
            'source' => $source,
            'effective_date' => (new DateTimeImmutable((string) $rows['valid_from']))->format('Y-m-d'),
            'source_url' => $source === 'bcc_official'
                ? OfficialRateClient::BCC_SOURCE_URL
                : OfficialRateClient::CBR_SOURCE_URL,
            'manual_override' => false,
        ];
    }

    private function saveOfficial(array $official): void
    {
        $existing = $this->db->createCommand(
            'SELECT id FROM {{%exchange_rates}}
             WHERE base_currency = :base AND quote_currency = :quote
               AND source = :source AND DATE(valid_from) = :date LIMIT 1',
            [
                ':base' => 'RUB',
                ':quote' => 'CUP',
                ':source' => $official['source'],
                ':date' => $official['effective_date'],
            ]
        )->queryScalar();
        if ($existing !== false) {
            $this->db->createCommand()->update('{{%exchange_rates}}', [
                'rate' => $official['rate'],
                'valid_until' => null,
            ], ['id' => $existing])->execute();
            return;
        }

        $this->db->createCommand()->insert('{{%exchange_rates}}', [
            'base_currency' => 'RUB',
            'quote_currency' => 'CUP',
            'rate' => $official['rate'],
            'source' => $official['source'],
            'valid_from' => $official['effective_date'] . ' 00:00:00',
            'created_by_user_id' => null,
        ])->execute();
    }

    private function format(string $base, string $quote, array $rate): array
    {
        $rate['base_currency'] = $base;
        $rate['quote_currency'] = $quote;
        $rate['source_label'] = $rate['manual_override']
            ? 'Definida por administrador'
            : ($rate['source'] === 'bcc_official'
                ? 'Banco Central de Cuba · segmento población/FGNE'
                : 'Banco de Rusia · respaldo oficial');

        return $rate;
    }
}
