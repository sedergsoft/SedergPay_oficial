<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds the SBP person-to-person (C2P) funding method configuration with the
 * admin-managed beneficiary phone/account and the fixed funding purpose.
 */
final class m261112_000017_set_sbp_c2p_method_configuration extends Migration
{
    public function safeUp(): void
    {
        $ruCountryId = (int) $this->db->createCommand(
            "SELECT id FROM {{%countries}} WHERE iso2='RU' LIMIT 1"
        )->queryScalar();
        if ($ruCountryId > 0) {
            $this->update('{{%funding_payment_methods}}', [
                'configuration' => json_encode([
                    'rail' => 'sbp_c2p',
                    'qr_mode' => 'dynamic',
                    'flow' => 'person_to_person',
                    'issuer' => 'payee_bank_app',
                    'confirmation' => 'authenticated_webhook_or_state',
                    'production_enabled' => false,
                    'payee_phone' => '79001234567',
                    'account_number' => '',
                    'purpose' => 'Fondeo SEDERG_PAY',
                ], JSON_THROW_ON_ERROR),
            ], ['code' => 'ru_sbp_c2p']);
        }
    }

    public function safeDown(): void
    {
        $ruCountryId = (int) $this->db->createCommand(
            "SELECT id FROM {{%countries}} WHERE iso2='RU' LIMIT 1"
        )->queryScalar();
        if ($ruCountryId > 0) {
            $this->update('{{%funding_payment_methods}}', [
                'configuration' => json_encode([
                    'rail' => 'sbp_c2p',
                    'qr_mode' => 'dynamic',
                    'flow' => 'person_to_person',
                    'issuer' => 'payee_bank_app',
                    'confirmation' => 'authenticated_webhook_or_state',
                    'production_enabled' => false,
                ], JSON_THROW_ON_ERROR),
            ], ['code' => 'ru_sbp_c2p']);
        }
    }
}
