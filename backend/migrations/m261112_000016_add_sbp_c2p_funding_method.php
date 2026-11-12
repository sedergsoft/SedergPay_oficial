<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the SBP person-to-person (C2P) sandbox funding method used for personal
 * QR instructions (e.g. informal settlements between individuals without a
 * registered Russian legal entity).
 */
final class m261112_000016_add_sbp_c2p_funding_method extends Migration
{
    public function safeUp(): void
    {
        $ruCountryId = (int) $this->db->createCommand(
            "SELECT id FROM {{%countries}} WHERE iso2='RU' LIMIT 1"
        )->queryScalar();
        if ($ruCountryId > 0) {
            $this->insert('{{%funding_payment_methods}}', [
                'code' => 'ru_sbp_c2p',
                'name' => 'СБП — QR persona a persona (sandbox)',
                'country_id' => $ruCountryId,
                'currency_code' => 'RUB',
                'method_type' => 'instant_qr',
                'provider_code' => 'sbp_c2p_sandbox',
                'supports_dynamic_qr' => true,
                'requires_reference' => true,
                'is_active' => false,
                'configuration' => json_encode([
                    'rail' => 'sbp_c2p',
                    'qr_mode' => 'dynamic',
                    'flow' => 'person_to_person',
                    'issuer' => 'payee_bank_app',
                    'confirmation' => 'authenticated_webhook_or_state',
                    'production_enabled' => false,
                ], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function safeDown(): void
    {
        $this->delete('{{%funding_payment_methods}}', ['code' => 'ru_sbp_c2p']);
    }
}
