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

        $activeSource = $this->activeSourceForPair($baseCurrency, $quoteCurrency);
        $direct = $activeSource === 'admin_override'
            ? $this->findEffective($baseCurrency, $quoteCurrency, self::ADMIN_SOURCE)
            : $this->findEffectiveOfficial($baseCurrency, $quoteCurrency);
        if ($direct === null && $activeSource === 'admin_override') {
            throw new DomainException('No existe una tasa manual activa para este par (' . $baseCurrency . '/' . $quoteCurrency . '). Seleccione la tasa oficial o defina una tasa manual.');
        }
        if ($direct === null) {
            try {
                $official = $this->officialClient->fetchRubCup();
                // Save RUB/CUP (buy/compra: CUP per RUB from tasaEspecial)
                $this->saveOfficial('RUB', 'CUP', $official['rate_rub_cup'], $official['source'], $official['effective_date']);
                // Save CUP/RUB (sell/venta: RUB per CUP from tasaOficial)
                $this->saveOfficial('CUP', 'RUB', $official['rate_cup_rub'], $official['source'], $official['effective_date']);
                $direct = $this->findEffectiveOfficial($baseCurrency, $quoteCurrency);
                if ($direct === null) {
                    $now = new DateTimeImmutable();
                    $direct = $this->normalizeRateRow([
                        'rate' => $baseCurrency === 'RUB' ? $official['rate_rub_cup'] : $official['rate_cup_rub'],
                        'source' => $official['source'],
                        'valid_from' => $now->format('Y-m-d H:i:s'),
                        'created_at' => $now->format('Y-m-d H:i:s'),
                        'manual_override' => false,
                    ]);
                }
            } catch (\Throwable $exception) {
                $stale = $this->findLatestStoredOfficial($baseCurrency, $quoteCurrency);
                if ($stale !== null) {
                    $stale['stale'] = true;
                    $stale['stale_reason'] = 'La fuente oficial no está disponible; se muestra el último valor válido almacenado.';
                    $direct = $stale;
                } else {
                    throw $exception;
                }
            }
        }

        return $this->format($baseCurrency, $quoteCurrency, $direct, $activeSource);
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

        $direct = $this->findEffectiveOfficial('RUB', 'CUP');
        if ($direct === null) {
            $now = new DateTimeImmutable();
            $direct = $this->normalizeRateRow([
                'rate' => $official['rate_rub_cup'],
                'source' => $official['source'],
                'valid_from' => $now->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'manual_override' => false,
            ]);
        }

        return $this->format('RUB', 'CUP', $direct, 'official');
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

        $activeSource = $this->activeSourceForPair($baseCurrency, $quoteCurrency);
        return $this->format($baseCurrency, $quoteCurrency, $this->normalizeRateRow([
            'id' => $this->db->getLastInsertID(),
            'rate' => $rate,
            'source' => self::ADMIN_SOURCE,
            'valid_from' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'manual_override' => true,
        ]), $activeSource);
    }

    public function setActiveSource(string $source, int $userId, string $baseCurrency = 'RUB', string $quoteCurrency = 'CUP'): array
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $quoteCurrency = strtoupper(trim($quoteCurrency));
        if (!in_array($source, ['official', 'admin_override'], true)) {
            throw new DomainException('La fuente debe ser official o admin_override.');
        }
        if ($source === 'admin_override' && $this->findEffective($baseCurrency, $quoteCurrency, self::ADMIN_SOURCE) === null) {
            throw new DomainException('No existe una tasa manual activa para el par ' . $baseCurrency . '/' . $quoteCurrency . '. Defina primero una tasa manual para este par.');
        }
        $this->db->createCommand()->upsert('{{%platform_settings}}', [
            'setting_key' => $this->pairSourceKey($baseCurrency, $quoteCurrency),
            'setting_value' => $source,
            'value_type' => 'enum',
            'updated_by_user_id' => $userId,
        ], [
            'setting_value' => $source,
            'value_type' => 'enum',
            'updated_by_user_id' => $userId,
        ])->execute();

        return $this->resolve($baseCurrency, $quoteCurrency);
    }

    private function pairSourceKey(string $baseCurrency, string $quoteCurrency): string
    {
        return 'pricing.exchange_rate_source_' . strtoupper($baseCurrency) . '_' . strtoupper($quoteCurrency);
    }

    private function activeSourceForPair(string $baseCurrency, string $quoteCurrency): string
    {
        $pair = $this->db->createCommand(
            'SELECT setting_value FROM {{%platform_settings}} WHERE setting_key = :k LIMIT 1',
            [':k' => $this->pairSourceKey($baseCurrency, $quoteCurrency)]
        )->queryScalar();
        if (is_string($pair) && in_array($pair, ['official', 'admin_override'], true)) {
            return $pair;
        }
        $global = PlatformSettings::get($this->db, 'pricing.exchange_rate_source');
        return in_array($global, ['official', 'admin_override'], true) ? $global : 'official';
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
            "SELECT id, rate, source, valid_from, created_at
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

        return $this->normalizeRateRow($row);
    }

    private function findEffectiveOfficial(string $baseCurrency, string $quoteCurrency): ?array
    {
        $row = $this->db->createCommand(
            "SELECT id, rate, source, valid_from, created_at
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

        return $this->normalizeRateRow($row);
    }

    private function findPrevious(string $base, string $quote, string $source, string $beforeValidFrom): ?array
    {
        $row = $this->db->createCommand(
            "SELECT id, rate, source, valid_from, created_at
             FROM {{%exchange_rates}}
             WHERE base_currency = :base AND quote_currency = :quote AND source = :source
               AND valid_from < :before
             ORDER BY valid_from DESC
             LIMIT 1",
            [
                ':base' => $base,
                ':quote' => $quote,
                ':source' => $source,
                ':before' => $beforeValidFrom,
            ]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return $this->normalizeRateRow($row);
    }

    private function findLatestStoredOfficial(string $base, string $quote): ?array
    {
        $row = $this->db->createCommand(
            "SELECT id, rate, source, valid_from, created_at
             FROM {{%exchange_rates}}
             WHERE base_currency = :base AND quote_currency = :quote
               AND source IN ('bcc_official', 'cbr_official')
             ORDER BY valid_from DESC
             LIMIT 1",
            [
                ':base' => $base,
                ':quote' => $quote,
            ]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return $this->normalizeRateRow($row);
    }

    private function normalizeRateRow(array $row): array
    {
        $validFrom = new DateTimeImmutable((string) $row['valid_from']);
        $createdAt = isset($row['created_at']) && $row['created_at'] !== null && $row['created_at'] !== ''
            ? new DateTimeImmutable((string) $row['created_at'])
            : $validFrom;

        return [
            'id' => isset($row['id']) ? (string) $row['id'] : null,
            'rate' => (string) $row['rate'],
            'source' => (string) $row['source'],
            'effective_date' => $validFrom->format('Y-m-d'),
            'valid_from' => $validFrom->format(DateTimeImmutable::ATOM),
            'updated_at' => $createdAt->format(DateTimeImmutable::ATOM),
            'manual_override' => ($row['source'] ?? '') === 'admin_override' || !empty($row['manual_override']),
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

    private function format(string $base, string $quote, array $rate, ?string $activeSource = null): array
    {
        $activeSource = $activeSource ?? ($rate['manual_override'] ?? false ? 'admin_override' : 'official');
        $source = (string) ($rate['source'] ?? '');
        $sourceLabel = match ($source) {
            'bcc_official' => 'BCC (Banco Central de Cuba)',
            'cbr_official' => 'CBR (Banco Central de Rusia)',
            'admin_override' => 'Tasa administrativa',
            default => $source !== '' ? $source : 'Fuente no disponible',
        };
        $sourceType = $source === 'admin_override' ? 'administrative' : 'official';
        $sourceTypeLabel = $sourceType === 'administrative' ? 'ADMINISTRATIVA' : 'OFICIAL';
        $sourceUrl = match ($source) {
            'bcc_official' => OfficialRateClient::BCC_SOURCE_URL,
            'cbr_official' => OfficialRateClient::CBR_SOURCE_URL,
            default => null,
        };
        $provenance = match ($source) {
            'bcc_official' => 'Banco Central de Cuba (BCC)',
            'cbr_official' => 'Banco Central de Rusia (CBR)',
            'admin_override' => 'Configuración administrativa de SEDERG_PAY',
            default => $sourceLabel,
        };

        $validFrom = isset($rate['valid_from']) ? (string) $rate['valid_from'] : (new DateTimeImmutable((string) ($rate['effective_date'] ?? 'now')))->format(DateTimeImmutable::ATOM);
        $updatedAt = isset($rate['updated_at']) ? (string) $rate['updated_at'] : $validFrom;
        $effectiveDate = isset($rate['effective_date']) ? (string) $rate['effective_date'] : (new DateTimeImmutable($validFrom))->format('Y-m-d');

        $previous = null;
        $variationAbsolute = null;
        $variationPercent = null;
        $variationDirection = 'unknown';
        if ($source !== '' && isset($rate['valid_from'])) {
            $previous = $this->findPrevious($base, $quote, $source, (string) $rate['valid_from']);
            if ($previous !== null) {
                $prevRate = (string) $previous['rate'];
                $currRate = (string) $rate['rate'];
                $variationAbsolute = bcsub($currRate, $prevRate, 10);
                $variationDirection = bccomp($variationAbsolute, '0', 10) === 0 ? 'flat' : (bccomp($variationAbsolute, '0', 10) > 0 ? 'up' : 'down');
                if (bccomp($prevRate, '0', 10) !== 0) {
                    $variationPercent = bcmul(bcdiv($variationAbsolute, $prevRate, 12), '100', 10);
                }
            }
        }

        $stale = !empty($rate['stale']);
        $staleReason = $rate['stale_reason'] ?? null;

        $result = [
            'base_currency' => $base,
            'quote_currency' => $quote,
            'base_amount' => '1',
            'quote_amount' => (string) $rate['rate'],
            'rate' => (string) $rate['rate'],
            'previous_rate' => $previous['rate'] ?? null,
            'variation_absolute' => $variationAbsolute,
            'variation_percent' => $variationPercent,
            'variation_direction' => $variationDirection,
            'source' => $source,
            'source_label' => $sourceLabel,
            'source_type' => $sourceType,
            'source_type_label' => $sourceTypeLabel,
            'source_url' => $sourceUrl,
            'provenance' => $provenance,
            'active_source' => $activeSource,
            'effective_date' => $effectiveDate,
            'updated_at' => $updatedAt,
            'valid_from' => $validFrom,
            'manual_override' => (bool) ($rate['manual_override'] ?? ($source === 'admin_override')),
            'stale' => $stale,
            'stale_reason' => $staleReason,
        ];

        if ($previous !== null) {
            $result['previous_effective_date'] = $previous['effective_date'];
            $result['previous_updated_at'] = $previous['updated_at'] ?? $previous['valid_from'] ?? null;
        }

        return $result;
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
