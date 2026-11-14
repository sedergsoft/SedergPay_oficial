<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds EnZona (Cuba) as a sandbox funding payment method.
 */
final class m261114_000019_add_enzona_funding_method extends Migration
{
    public function safeUp(): void
    {
        $cuCountryId = (int) $this->db->createCommand(
            "SELECT id FROM {{%countries}} WHERE iso2='CU' LIMIT 1"
        )->queryScalar();
        if ($cuCountryId <= 0) {
            return;
        }

        $exists = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%funding_payment_methods}} WHERE code = :code',
            [':code' => 'cu_enzona']
        )->queryScalar();
        if ($exists > 0) {
            return;
        }

        $this->insert('{{%funding_payment_methods}}', [
            'code' => 'cu_enzona',
            'name' => 'EnZona (Cuba, sandbox)',
            'country_id' => $cuCountryId,
            'currency_code' => 'CUP',
            'method_type' => 'instant_qr',
            'provider_code' => 'sedergpay_sandbox',
            'supports_dynamic_qr' => true,
            'requires_reference' => true,
            'is_active' => false,
            'configuration' => json_encode([
                'platform' => 'enzona',
                'country' => 'CU',
                'flow' => 'wallet_payment',
                'qr_mode' => 'dynamic',
                'confirmation' => 'authenticated_webhook_or_state',
                'production_enabled' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function safeDown(): void
    {
        $this->delete('{{%funding_payment_methods}}', ['code' => 'cu_enzona']);
    }
}
