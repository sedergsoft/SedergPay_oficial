<?php
use yii\db\Migration;

/** Funding accounts and SBP C2B rail. SBP QR payloads are issued by the acquiring bank. */
class m261103_000020_create_funding_accounts_and_sbp extends Migration
{
    private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        $this->createTable('{{%funding_accounts}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'bank_connection_id' => $this->bigInteger()->unsigned()->notNull(),
            'country_id' => $this->smallInteger()->unsigned()->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'owner_name' => $this->string(160)->notNull()->defaultValue('SEDERG_PAY'),
            'account_name' => $this->string(120)->notNull(),
            'account_token' => $this->string(255)->notNull(),
            'account_mask' => $this->string(50)->null(),
            'payment_rail' => $this->string(30)->notNull(),
            'merchant_id' => $this->string(120)->null(),
            'terminal_id' => $this->string(120)->null(),
            'provider_account_ref' => $this->string(160)->null(),
            'is_default' => $this->boolean()->notNull()->defaultValue(false),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'metadata' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::OPTIONS);
        $this->createIndex('ux_funding_accounts_public_id', '{{%funding_accounts}}', 'public_id', true);
        $this->createIndex('ix_funding_accounts_rail_active', '{{%funding_accounts}}', ['payment_rail', 'country_id', 'currency_code', 'is_active']);
        $this->createIndex('ix_funding_accounts_connection', '{{%funding_accounts}}', 'bank_connection_id');
        $this->addForeignKey('fk_funding_accounts_connection', '{{%funding_accounts}}', 'bank_connection_id', '{{%bank_connections}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_funding_accounts_country', '{{%funding_accounts}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_funding_accounts_currency', '{{%funding_accounts}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->addColumn('{{%deposit_requests}}', 'funding_account_id', $this->bigInteger()->unsigned()->null()->after('bank_connection_id'));
        $this->addColumn('{{%deposit_requests}}', 'payment_rail', $this->string(30)->notNull()->defaultValue('bank_transfer')->after('funding_account_id'));
        $this->addColumn('{{%deposit_requests}}', 'qr_payload', $this->text()->null()->after('payment_rail'));
        $this->addColumn('{{%deposit_requests}}', 'qr_expires_at', $this->dateTime()->null()->after('qr_payload'));
        $this->addColumn('{{%deposit_requests}}', 'provider_payment_id', $this->string(160)->null()->after('qr_expires_at'));
        $this->addColumn('{{%deposit_requests}}', 'provider_status', $this->string(40)->null()->after('provider_payment_id'));
        $this->createIndex('ix_deposit_funding_account', '{{%deposit_requests}}', 'funding_account_id');
        $this->createIndex('ux_deposit_provider_payment', '{{%deposit_requests}}', 'provider_payment_id', true);
        $this->addForeignKey('fk_deposit_funding_account', '{{%deposit_requests}}', 'funding_account_id', '{{%funding_accounts}}', 'id', 'RESTRICT', 'CASCADE');

        $this->insert('{{%payment_methods}}', [
            'code' => 'sbp_qr',
            'name' => 'СБП — QR',
            'country_id' => null,
            'direction' => 'inbound',
            'requires_bank_account' => true,
            'is_active' => true,
            'configuration' => json_encode([
                'rail' => 'sbp_c2b',
                'country' => 'RU',
                'currency' => 'RUB',
                'qr_mode' => 'dynamic',
                'qr_issuer' => 'acquiring_bank',
                'confirmation' => 'signed_webhook_or_bank_statement',
            ], JSON_THROW_ON_ERROR),
            'created_at' => new yii\db\Expression('CURRENT_TIMESTAMP'),
        ]);
    }

    public function safeDown()
    {
        $this->delete('{{%payment_methods}}', ['code' => 'sbp_qr']);
        $this->dropForeignKey('fk_deposit_funding_account', '{{%deposit_requests}}');
        $this->dropIndex('ux_deposit_provider_payment', '{{%deposit_requests}}');
        $this->dropIndex('ix_deposit_funding_account', '{{%deposit_requests}}');
        $this->dropColumn('{{%deposit_requests}}', 'provider_status');
        $this->dropColumn('{{%deposit_requests}}', 'provider_payment_id');
        $this->dropColumn('{{%deposit_requests}}', 'qr_expires_at');
        $this->dropColumn('{{%deposit_requests}}', 'qr_payload');
        $this->dropColumn('{{%deposit_requests}}', 'payment_rail');
        $this->dropColumn('{{%deposit_requests}}', 'funding_account_id');
        $this->dropTable('{{%funding_accounts}}');
    }
}
