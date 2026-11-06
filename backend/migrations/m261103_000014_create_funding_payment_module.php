<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Payment collection methods and platform-owned receiving accounts.
 *
 * Account numbers must be encrypted by the application before persistence.
 * SEDERG_PAY is the platform's commercial holder label; legal_entity_name
 * records the exact registered account holder when it differs.
 */
final class m261103_000014_create_funding_payment_module extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%funding_payment_methods}}', [
            'code' => $this->string(40)->notNull(),
            'name' => $this->string(120)->notNull(),
            'country_id' => $this->smallInteger()->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'method_type' => $this->string(30)->notNull(),
            'provider_code' => $this->string(50)->null(),
            'supports_dynamic_qr' => $this->boolean()->notNull()->defaultValue(false),
            'requires_reference' => $this->boolean()->notNull()->defaultValue(true),
            'is_active' => $this->boolean()->notNull()->defaultValue(false),
            'configuration' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'PRIMARY KEY (code)',
        ]);
        $this->createIndex('ix_funding_method_country_active', '{{%funding_payment_methods}}', ['country_id', 'is_active']);
        $this->addForeignKey('fk_funding_method_country', '{{%funding_payment_methods}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_funding_method_currency', '{{%funding_payment_methods}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%platform_funding_accounts}}', [
            'id' => $this->bigPrimaryKey(),
            'public_id' => $this->char(36)->notNull(),
            'country_id' => $this->smallInteger()->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'bank_code' => $this->string(50)->notNull(),
            'bank_name' => $this->string(120)->notNull(),
            'account_holder_name' => $this->string(160)->notNull()->defaultValue('SEDERG_PAY'),
            'legal_entity_name' => $this->string(200)->null(),
            'account_type' => $this->string(30)->notNull()->defaultValue('bank_account'),
            'account_identifier_ciphertext' => $this->text()->notNull(),
            'account_mask' => $this->string(80)->notNull(),
            'routing_identifier_ciphertext' => $this->text()->null(),
            'routing_mask' => $this->string(80)->null(),
            'environment' => $this->string(15)->notNull()->defaultValue('sandbox'),
            'status' => $this->string(20)->notNull()->defaultValue('inactive'),
            'valid_from' => $this->dateTime()->null(),
            'valid_until' => $this->dateTime()->null(),
            'notes' => $this->text()->null(),
            'created_by_user_id' => $this->bigInteger()->null(),
            'updated_by_user_id' => $this->bigInteger()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_platform_funding_account_public', '{{%platform_funding_accounts}}', 'public_id', true);
        $this->createIndex('ix_platform_funding_account_route', '{{%platform_funding_accounts}}', ['country_id', 'currency_code', 'environment', 'status']);
        $this->addForeignKey('fk_platform_funding_account_country', '{{%platform_funding_accounts}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_account_currency', '{{%platform_funding_accounts}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_account_creator', '{{%platform_funding_accounts}}', 'created_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_account_updater', '{{%platform_funding_accounts}}', 'updated_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%funding_account_methods}}', [
            'funding_account_id' => $this->bigInteger()->notNull(),
            'payment_method_code' => $this->string(40)->notNull(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'PRIMARY KEY (funding_account_id, payment_method_code)',
        ]);
        $this->addForeignKey('fk_funding_account_methods_account', '{{%funding_account_methods}}', 'funding_account_id', '{{%platform_funding_accounts}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_funding_account_methods_method', '{{%funding_account_methods}}', 'payment_method_code', '{{%funding_payment_methods}}', 'code', 'RESTRICT', 'CASCADE');

        $this->addColumn('{{%deposit_requests}}', 'funding_account_id', $this->bigInteger()->null());
        $this->addColumn('{{%deposit_requests}}', 'funding_method_code', $this->string(40)->null());
        $this->addColumn('{{%deposit_requests}}', 'account_snapshot', $this->json()->null());
        $this->addColumn('{{%deposit_requests}}', 'qr_payload', $this->text()->null());
        $this->addColumn('{{%deposit_requests}}', 'qr_expires_at', $this->dateTime()->null());
        $this->addColumn('{{%deposit_requests}}', 'provider_payment_id', $this->string(160)->null());
        $this->createIndex('ux_deposit_provider_payment_id', '{{%deposit_requests}}', 'provider_payment_id', true);
        $this->createIndex('ix_deposit_funding_account', '{{%deposit_requests}}', 'funding_account_id');
        $this->addForeignKey('fk_deposit_funding_account', '{{%deposit_requests}}', 'funding_account_id', '{{%platform_funding_accounts}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_deposit_funding_method', '{{%deposit_requests}}', 'funding_method_code', '{{%funding_payment_methods}}', 'code', 'RESTRICT', 'CASCADE');

        $this->batchInsert('{{%funding_payment_methods}}',
            ['code', 'name', 'country_id', 'currency_code', 'method_type', 'provider_code', 'supports_dynamic_qr', 'requires_reference', 'is_active'],
            [
                ['ru_bank_transfer', 'Transferencia bancaria en Rusia', 1, 'RUB', 'bank_transfer', null, 0, 1, 0],
                ['cu_bank_transfer', 'Transferencia bancaria en Cuba', 2, 'CUP', 'bank_transfer', null, 0, 1, 0],
                ['cu_instant_qr', 'Pago QR en Cuba (sandbox)', 2, 'CUP', 'instant_qr', 'sedergpay_sandbox', 1, 1, 0],
            ]
        );

        // SBP C2B is a Russian commercial acquiring rail, not a C2C phone transfer.
        // It remains disabled until an acquiring contract and credentials exist.
        $ruCountryId = (int) $this->db->createCommand(
            "SELECT id FROM {{%countries}} WHERE iso2='RU' LIMIT 1"
        )->queryScalar();
        if ($ruCountryId > 0) {
            $this->insert('{{%funding_payment_methods}}', [
                'code' => 'ru_sbp_qr',
                'name' => 'СБП — QR (sandbox)',
                'country_id' => $ruCountryId,
                'currency_code' => 'RUB',
                'method_type' => 'instant_qr',
                'provider_code' => 'sbp_c2b_sandbox',
                'supports_dynamic_qr' => true,
                'requires_reference' => true,
                'is_active' => false,
                'configuration' => json_encode([
                    'rail' => 'sbp_c2b',
                    'qr_mode' => 'dynamic',
                    'issuer' => 'acquiring_bank',
                    'confirmation' => 'authenticated_webhook_or_statement',
                    'production_enabled' => false,
                ], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_deposit_funding_method', '{{%deposit_requests}}');
        $this->dropForeignKey('fk_deposit_funding_account', '{{%deposit_requests}}');
        $this->dropIndex('ix_deposit_funding_account', '{{%deposit_requests}}');
        $this->dropIndex('ux_deposit_provider_payment_id', '{{%deposit_requests}}');
        $this->dropColumn('{{%deposit_requests}}', 'provider_payment_id');
        $this->dropColumn('{{%deposit_requests}}', 'qr_expires_at');
        $this->dropColumn('{{%deposit_requests}}', 'qr_payload');
        $this->dropColumn('{{%deposit_requests}}', 'account_snapshot');
        $this->dropColumn('{{%deposit_requests}}', 'funding_method_code');
        $this->dropColumn('{{%deposit_requests}}', 'funding_account_id');
        $this->dropTable('{{%funding_account_methods}}');
        $this->dropTable('{{%platform_funding_accounts}}');
        $this->dropTable('{{%funding_payment_methods}}');
    }
}
