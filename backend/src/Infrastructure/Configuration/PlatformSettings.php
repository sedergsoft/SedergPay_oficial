<?php

declare(strict_types=1);

namespace app\Infrastructure\Configuration;

use DomainException;
use yii\db\Connection;

final class PlatformSettings
{
    /**
     * Runtime settings only. Secrets and infrastructure credentials stay in
     * deployment environment variables and are intentionally not editable here.
     *
     * @return array<string, array{type: string, default: string, min?: string, max?: string, label: string, category: string}>
     */
    public static function definitions(): array
    {
        return [
            'pricing.fee_rate_percent' => [
                'type' => 'decimal', 'default' => getenv('SEDERG_FEE_RATE_PERCENT') ?: '3.00',
                'min' => '0', 'max' => '100', 'label' => 'Comisión (%)', 'category' => 'pricing',
            ],
            'pricing.minimum_send_amount' => [
                'type' => 'decimal', 'default' => getenv('SEDERG_MIN_SEND_AMOUNT') ?: '15000.00',
                'min' => '0.01', 'max' => '999999999.99', 'label' => 'Importe mínimo de envío', 'category' => 'pricing',
            ],
            'pricing.maximum_send_amount' => [
                'type' => 'decimal', 'default' => getenv('SEDERG_MAX_SEND_AMOUNT') ?: '50000.00',
                'min' => '0.01', 'max' => '999999999.99', 'label' => 'Importe máximo de envío', 'category' => 'pricing',
            ],
            'pricing.exchange_rate_source' => [
                'type' => 'enum', 'default' => 'official',
                'options' => ['official', 'admin_override'],
                'label' => 'Fuente de tasa de cambio', 'category' => 'pricing',
            ],
            'pricing.quote_validity_seconds' => [
                'type' => 'integer', 'default' => '900', 'min' => '60', 'max' => '86400',
                'label' => 'Vigencia de cotización (segundos)', 'category' => 'pricing',
            ],
            'operations.registrations_enabled' => [
                'type' => 'boolean', 'default' => 'true',
                'label' => 'Registro de usuarios habilitado', 'category' => 'operations',
            ],
            'operations.transfers_enabled' => [
                'type' => 'boolean', 'default' => 'true',
                'label' => 'Transferencias habilitadas', 'category' => 'operations',
            ],
            'operations.max_beneficiaries' => [
                'type' => 'integer', 'default' => '20', 'min' => '1', 'max' => '100',
                'label' => 'Máximo de beneficiarios por usuario', 'category' => 'operations',
            ],
            'security.verification_token_ttl_minutes' => [
                'type' => 'integer', 'default' => '30', 'min' => '5', 'max' => '1440',
                'label' => 'Expiración del enlace de verificación (minutos)', 'category' => 'security',
            ],
        ];
    }

    public static function all(Connection $db): array
    {
        $definitions = self::definitions();
        $stored = $db->createCommand(
            'SELECT setting_key, setting_value, value_type, updated_at
             FROM {{%platform_settings}}'
        )->queryAll();
        $storedByKey = [];
        foreach ($stored as $row) {
            $storedByKey[$row['setting_key']] = $row;
        }

        $result = [];
        foreach ($definitions as $key => $definition) {
            $row = $storedByKey[$key] ?? null;
            $value = $row['setting_value'] ?? $definition['default'];
            $result[] = [
                'key' => $key,
                'value' => self::cast($value, $definition['type']),
                'type' => $definition['type'],
                'label' => $definition['label'],
                'category' => $definition['category'],
                'options' => $definition['options'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $result;
    }

    public static function get(Connection $db, string $key): string
    {
        $definition = self::definition($key);
        $value = $db->createCommand(
            'SELECT setting_value FROM {{%platform_settings}} WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => $key]
        )->queryScalar();

        return $value === false || $value === null
            ? $definition['default']
            : (string) $value;
    }

    public static function getBool(Connection $db, string $key): bool
    {
        return self::get($db, $key) === 'true';
    }

    public static function save(Connection $db, int $userId, array $values): array
    {
        $definitions = self::definitions();
        $before = [];
        $validated = [];
        foreach ($values as $key => $value) {
            if (!isset($definitions[$key])) {
                throw new DomainException("Unknown platform setting: {$key}");
            }
            $definition = $definitions[$key];
            $normalized = self::normalize($value, $definition);
            $before[$key] = self::get($db, $key);
            $validated[$key] = $normalized;
        }
        if (($validated['pricing.exchange_rate_source'] ?? null) === 'admin_override') {
            $manualRateExists = (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%exchange_rates}}
                 WHERE base_currency = :base AND quote_currency = :quote
                   AND source = :source AND valid_from <= UTC_TIMESTAMP()
                   AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())',
                [':base' => 'RUB', ':quote' => 'CUP', ':source' => 'admin_override']
            )->queryScalar();
            if ($manualRateExists === 0) {
                throw new DomainException('No hay una tasa manual guardada para activar.');
            }
        }

        $effectiveMinimum = $validated['pricing.minimum_send_amount']
            ?? self::get($db, 'pricing.minimum_send_amount');
        $effectiveMaximum = $validated['pricing.maximum_send_amount']
            ?? self::get($db, 'pricing.maximum_send_amount');
        if (bccomp($effectiveMinimum, $effectiveMaximum, 10) > 0) {
            throw new DomainException('The minimum send amount cannot exceed the maximum send amount');
        }

        foreach ($validated as $key => $value) {
            $definition = $definitions[$key];
            $db->createCommand()->upsert('{{%platform_settings}}', [
                'setting_key' => $key,
                'setting_value' => $value,
                'value_type' => $definition['type'],
                'updated_by_user_id' => $userId,
            ], [
                'setting_value' => $value,
                'value_type' => $definition['type'],
                'updated_by_user_id' => $userId,
            ])->execute();
        }

        return ['before' => $before, 'after' => $validated, 'settings' => self::all($db)];
    }

    private static function definition(string $key): array
    {
        $definitions = self::definitions();
        if (!isset($definitions[$key])) {
            throw new DomainException("Unknown platform setting: {$key}");
        }
        return $definitions[$key];
    }

    private static function normalize(mixed $value, array $definition): string
    {
        $type = $definition['type'];
        if ($type === 'boolean') {
            if (!is_bool($value) && !in_array($value, ['true', 'false', 0, 1, '0', '1'], true)) {
                throw new DomainException('Boolean platform settings require true or false');
            }
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        $string = is_int($value) || is_float($value) ? (string) $value : trim((string) $value);
        if ($type === 'enum' && !in_array($string, $definition['options'] ?? [], true)) {
            throw new DomainException('Invalid value for platform setting');
        }
        if ($type === 'integer' && !preg_match('/^\d+$/', $string)) {
            throw new DomainException('Integer platform settings require a whole number');
        }
        if ($type === 'decimal' && !preg_match('/^\d+(?:\.\d{1,10})?$/', $string)) {
            throw new DomainException('Decimal platform settings require a positive decimal');
        }
        if (isset($definition['min']) && bccomp($string, $definition['min'], 10) < 0) {
            throw new DomainException("Setting is below the minimum allowed value ({$definition['min']})");
        }
        if (isset($definition['max']) && bccomp($string, $definition['max'], 10) > 0) {
            throw new DomainException("Setting exceeds the maximum allowed value ({$definition['max']})");
        }

        return $type === 'integer' ? (string) (int) $string : $string;
    }

    private static function cast(string $value, string $type): bool|int|string
    {
        return match ($type) {
            'boolean' => $value === 'true',
            'integer' => (int) $value,
            default => $value,
        };
    }
}
