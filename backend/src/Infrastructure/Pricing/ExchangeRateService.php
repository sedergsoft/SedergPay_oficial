<?php

declare(strict_types=1);

namespace app\Infrastructure\Pricing;

use DateTimeImmutable;
use DomainException;
use RuntimeException;
use app\Infrastructure\Configuration\PlatformSettings;
use yii\db\Connection;
use yii\db\Expression;
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
            throw new DomainException('Solo se admiten las tasas RUB/CUP y CUP/RUB');
        }

        $activeSource = PlatformSettings::get($this->db, 'pricing.exchange_rate_source');
        $direct = $activeSource === 'admin_override'
            ? $this->findEffective($baseCurrency, $quoteCurrency, self::ADMIN_SOURCE)
            : $this->findEffectiveOfficial($baseCurrency, $quoteCurrency);
        if ($direct === null && $activeSource === 'admin_override') {
            throw new DomainException('No existe una tasa manual activa para este par. Seleccione la tasa oficial o defina una tasa manual.');
        }
        if ($direct === null) {
            $official = $this->officialClient->fetchRubCup();
            // Save RUB/CUP (buy/compra: CUP per RUB from tasaEspecial)
            $this->saveOfficial('RUB', 'CUP', $official['rate_rub_cup'], $official['source'], $official['effective_date']);
            // Save CUP/RUB (sell/venta: RUB per CUP from tasaOficial)
            $this->saveOfficial('CUP', 'RUB', $official['rate_cup_rub'], $official['source'], $official['effective_date']);
            $direct = $baseCurrency === 'RUB'
                ? ['rate' => $official['rate_rub_cup'], 'source' => $official['source'], 'effective_date' => $official['effective_date'], 'manual_override' => false]
                : ['rate' => $official['rate_cup_rub'], 'source' => $official['source'], 'effective_date' => $official['effective_date'], 'manual_override' => false];
        }

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
     *   manual_override: bool
     * }
     */
    public function refreshOfficial(): array
    {
        $official = $this->officialClient->fetchRubCup();
        // Save RUB/CUP (buy/compra: CUP per RUB from tasaEspecial)
        $this->saveOfficial('RUB', 'CUP', $official['rate_rub_cup'], $official['source'], $official['effective_date']);
        // Save CUP/RUB (sell/venta: RUB per CUP from tasaOficial)
        $this->saveOfficial('CUP', 'RUB', $official['rate_cup_rub'], $official['source'], $official['effective_date']);

        return $this->format('RUB', 'CUP', [
            'rate' => $official['rate_rub_cup'],
            'source' => $official['source'],
            'effective_date' => $official['effective_date'],
            'manual_override' => false,
        ]);
    }

    public function saveAdminOverride(string $rate, int $userId, string $baseCurrency = 'RUB', string $quoteCurrency = 'CUP'): array
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $quoteCurrency = strtoupper(trim($quoteCurrency));
        if ($baseCurrency === $quoteCurrency
            || !in_array($baseCurrency, ['RUB', 'CUP'], true)
            || !in_array($quoteCurrency, ['RUB', 'CUP'], true)) {
            throw new DomainException('Solo se admiten las tasas RUB/CUP y CUP/RUB');
        }
        $rate = $this->decimal($rate, 'rate');
        if ($rate <= '0') {
            throw new DomainException('La tasa debe ser mayor a cero.');
        }

        $now = new DateTimeImmutable();
        $transaction = $this->db->beginTransaction();
        try {
            $this->db->createCommand(
                "UPDATE {{%exchange_rates}} SET valid_until = :valid_until
                 WHERE base_currency = :base AND quote_currency = :quote AND source = :source AND valid_until IS NULL",
                [
                    ':valid_until' => $now->format('Y-m-d H:i:s'),
                    ':base' => $baseCurrency,
                    ':quote' => $quoteCurrency,
                    ':source' => self::ADMIN_SOURCE,
                ]
            )->execute();

            $this->db->createCommand(
                "INSERT INTO {{%exchange_rates}}
                 (base_currency, quote_currency, rate, source, valid_from, valid_until, created_by_user_id)
                 VALUES (:base, :quote, :rate, :source, :valid_from, NULL, :user_id)",
                [
                    ':base' => $baseCurrency,
                    ':quote' => $quoteCurrency,
                    ':rate' => $rate,
                    ':source' => self::ADMIN_SOURCE,
                    ':valid_from' => $now->format('Y-m-d H:i:s'),
                    ':user_id' => $userId,
                ]
            )->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $this->format($baseCurrency, $quoteCurrency, [
            'rate' => $rate,
            'source' => self::ADMIN_SOURCE,
            'effective_date' => $now->format('Y-m-d'),
            'manual_override' => true,
        ]);
    }

    public function setActiveSource(string $source, int $userId, string $baseCurrency = 'RUB', string $quoteCurrency = 'CUP'): array
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $quoteCurrency = strtoupper(trim($quoteCurrency));
        if (!in_array($source, ['official', 'admin_override'], true)) {
            throw new DomainException('La fuente debe ser official o admin_override.');
        }
        if ($source === 'admin_override' && $this->findEffective($baseCurrency, $quoteCurrency, self::ADMIN_SOURCE) === null) {
            throw new DomainException('No existe una tasa manual activa para este par.');
        }
        $this->db->createCommand()->upsert('{{%platform_settings}}', [
            'setting_key' => 'pricing.exchange_rate_source',
            'setting_value' => $source,
        ], ['setting_key' => 'pricing.exchange_rate_source'])->execute();

        return $this->resolve($baseCurrency, $quoteCurrency);
    }

    public function getRateHistory(string $baseCurrency, string $quoteCurrency, int $limit = 30): array
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $quoteCurrency = strtoupper(trim($quoteCurrency));
        if (!in_array($baseCurrency, ['RUB', 'CUP'], true) || !in_array($quoteCurrency, ['RUB', 'CUP'], true)) {
            throw new DomainException('Par de monedas no válido.');
        }

        $rows = $this->db->createCommand(
            "SELECT rate, source, valid_from, valid_until, created_by_user_id
             FROM {{%exchange_rates}}
             WHERE base_currency = :base AND quote_currency = :quote
             ORDER BY valid_from DESC
             LIMIT :limit",
            [
                ':base' => $baseCurrency,
                ':quote' => $quoteCurrency,
                ':limit' => $limit,
            ]
        )->queryAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'rate' => (string) $row['rate'],
                'source' => $row['source'],
                'effective_date' => (new DateTimeImmutable((string) $row['valid_from']))->format('Y-m-d'),
                'valid_until' => $row['valid_until'] ? (new DateTimeImmutable((string) $row['valid_until']))->format('Y-m-d H:i:s') : null,
            ];
        }
        return $result;
    }

    private function findEffective(string $base, string $quote, string $source): ?array
    {
        $row = $this->db->createCommand(
            "SELECT rate, source, valid_from
             FROM {{%exchange_rates}}
             WHERE base_currency = :base AND quote_currency = :quote AND source = :source AND valid_until IS NULL
             ORDER BY valid_from DESC
             LIMIT 1",
            [
                ':base' => $base,
                ':quote' => $quote,
                ':source' => $source,
            ]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return [
            'rate' => (string) $row['rate'],
            'source' => $row['source'],
            'effective_date' => (new DateTimeImmutable((string) $row['valid_from']))->format('Y-m-d'),
        ];
    }

    private function findEffectiveOfficial(string $baseCurrency, string $quoteCurrency): ?array
    {
        $row = $this->db->createCommand(
            "SELECT rate, source, valid_from
             FROM {{%exchange_rates}}
             WHERE base_currency = :base AND quote_currency = :quote
               AND source IN ('bcc_official', 'cbr_official')
               AND valid_until IS NULL
             ORDER BY valid_from DESC
             LIMIT 1",
            [
                ':base' => $baseCurrency,
                ':quote' => $quoteCurrency,
            ]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return [
            'rate' => (string) $row['rate'],
            'source' => $row['source'],
            'effective_date' => (new DateTimeImmutable((string) $row['valid_from']))->format('Y-m-d'),
        ];
    }

    private function saveOfficial(string $baseCurrency, string $quoteCurrency, string $rate, string $source, string $effectiveDate): void
    {
        $now = new DateTimeImmutable();
        $this->db->createCommand(
            "UPDATE {{%exchange_rates}} SET valid_until = :valid_until
             WHERE base_currency = :base AND quote_currency = :quote AND source = :source AND valid_until IS NULL",
            [
                ':valid_until' => $now->format('Y-m-d H:i:s'),
                ':base' => $baseCurrency,
                ':quote' => $quoteCurrency,
                ':source' => $source,
            ]
        )->execute();

        $this->db->createCommand(
            "INSERT INTO {{%exchange_rates}}
             (base_currency, quote_currency, rate, source, valid_from, valid_until, created_by_user_id)
             VALUES (:base, :quote, :rate, :source, :valid_from, NULL, NULL)",
            [
                ':base' => $baseCurrency,
                ':quote' => $quoteCurrency,
                ':rate' => $rate,
                ':source' => $source,
                ':valid_from' => $now->format('Y-m-d H:i:s'),
            ]
        )->execute();
    }

    private function format(string $base, string $quote, array $rate): array
    {
        $sourceLabel = match ($rate['source']) {
            'bcc_official' => 'BCC (Banco Central de Cuba)',
            'cbr_official' => 'CBR (Banco Central de Rusia)',
            'admin_override' => 'Administrador',
            default => $rate['source'],
        };

        return [
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate' => $rate['rate'],
            'source' => $rate['source'],
            'source_label' => $sourceLabel,
            'effective_date' => $rate['effective_date'],
            'manual_override' => $rate['manual_override'] ?? false,
        ];
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
