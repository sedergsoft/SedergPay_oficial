<?php

declare(strict_types=1);

use yii\db\Migration;

final class m261103_000014_create_platform_funding_accounts extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%funding_methods}}', [
            'code' => $this->string(40)->notNull(),
            'name' => $this->string(120)->notNull(),
            'country_id' => $this->smallInteger()->unsigned()->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'provider_code' => $this->string(40)->notNull()->defaultValue('manual'),
            'rail_type' => $this->string(30)->notNull(),
            'qr_strategy' => $this->string(40)->notNull()->defaultValue('none'),
            'requires_reference' => $this->boolean()->notNull()->defaultValue(true),
            'supports_dynamic_qr' => $this->boolean()->notNull()->defaultValue(false),
            'is_enabled' => $this->boolean()->notNull()->defaultValue(false),
            'configuration' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            'PRIMARY KEY (code)',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addForeignKey('fk_funding_method_country', '{{%funding_methods}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_funding_method_currency', '{{%funding_methods}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%platform_funding_accounts}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'funding_method_code' => $this->string(40)->notNull(),
            'country_id' => $this->smallInteger()->unsigned()->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'bank_code' => $this->string(40)->notNull(),
            'account_holder' => $this->string(160)->notNull()->defaultValue('SEDERG_PAY'),
            'account_identifier_ciphertext' => $this->text()->notNull(),
            'account_mask' => $this->string(80)->notNull(),
            'routing_code_ciphertext' => $this->text()->null(),
            'environment' => $this->string(15)->notNull()->defaultValue('sandbox'),
            'status' => $this->string(20)->notNull()->defaultValue('inactive'),
            'valid_from' => $this->dateTime()->null(),
            'valid_until' => $this->dateTime()->null(),
            'created_by_user_id' => $this->bigInteger()->unsigned()->null(),
            'updated_by_user_id' => $this->bigInteger()->unsigned()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->createIndex('ux_platform_funding_public_id', '{{%platform_funding_accounts}}', 'public_id', true);
        $this->createIndex('ix_platform_funding_route', '{{%platform_funding_accounts}}', ['country_id', 'currency_code', 'funding_method_code', 'environment', 'status']);
        $this->addForeignKey('fk_platform_funding_method', '{{%platform_funding_accounts}}', 'funding_method_code', '{{%funding_methods}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_country', '{{%platform_funding_accounts}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_currency', '{{%platform_funding_accounts}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_created_by', '{{%platform_funding_accounts}}', 'created_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_platform_funding_updated_by', '{{%platform_funding_accounts}}', 'updated_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->addColumn('{{%deposit_requests}}', 'funding_method_code', $this->string(40)->null());
        $this->addColumn('{{%deposit_requests}}', 'platform_funding_account_id', $this->bigInteger()->unsigned()->null());
        $this->addColumn('{{%deposit_requests}}', 'payment_instructions_snapshot', $this->json()->null());
        $this->addForeignKey('fk_deposit_funding_method', '{{%deposit_requests}}', 'funding_method_code', '{{%funding_methods}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_deposit_platform_account', '{{%deposit_requests}}', 'platform_funding_account_id', '{{%platform_funding_accounts}}', 'id', 'RESTRICT', 'CASCADE');

        $this->insert('{{%funding_methods}}', [
            'code' => 'ru_bank_transfer',
            'name' => 'Transferencia bancaria en Rusia',
            'country_id' => 1,
            'currency_code' => 'RUB',
            'provider_code' => 'manual',
            'rail_type' => 'bank_transfer',
            'qr_strategy' => 'bank_supported_only',
            'requires_reference' => 1,
            'supports_dynamic_qr' => 0,
            'is_enabled' => 0,
        ]);
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_deposit_platform_account', '{{%deposit_requests}}');
        $this->dropForeignKey('fk_deposit_funding_method', '{{%deposit_requests}}');
        $this->dropColumn('{{%deposit_requests}}', 'payment_instructions_snapshot');
        $this->dropColumn('{{%deposit_requests}}', 'platform_funding_account_id');
        $this->dropColumn('{{%deposit_requests}}', 'funding_method_code');
        $this->dropTable('{{%platform_funding_accounts}}');
        $this->dropTable('{{%funding_methods}}');
    }
}
