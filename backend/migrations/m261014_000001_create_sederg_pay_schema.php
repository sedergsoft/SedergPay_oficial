<?php

use yii\db\Migration;

/**
 * Modelo físico inicial de SEDERG_PAY.
 *
 * Requisitos:
 * - Yii 2.0
 * - MySQL 8.0+
 * - InnoDB, utf8mb4
 *
 * Ejecutar:
 *   php yii migrate --migrationPath=@app/migrations
 *
 * Antes de producción:
 * - Revisar el cumplimiento legal y regulatorio en Rusia y Cuba.
 * - Configurar secretos y credenciales bancarias fuera de la base de datos.
 * - Validar con cada banco los productos/API realmente habilitados.
 */
class m261014_000001_create_sederg_pay_schema extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        $this->createTable('{{%countries}}', [
            'id' => $this->smallInteger()->unsigned()->notNull()->append('AUTO_INCREMENT'),
            'iso2' => $this->char(2)->notNull(),
            'iso3' => $this->char(3)->notNull(),
            'name' => $this->string(100)->notNull(),
            'phone_code' => $this->string(8)->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'PRIMARY KEY (id)',
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_countries_iso2', '{{%countries}}', 'iso2', true);
        $this->createIndex('ux_countries_iso3', '{{%countries}}', 'iso3', true);

        $this->createTable('{{%currencies}}', [
            'code' => $this->char(3)->notNull(),
            'name' => $this->string(60)->notNull(),
            'minor_units' => $this->tinyInteger()->unsigned()->notNull()->defaultValue(2),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'PRIMARY KEY (code)',
        ], self::TABLE_OPTIONS);

        $this->createTable('{{%users}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'email' => $this->string(190)->null(),
            'phone_e164' => $this->string(20)->null(),
            'password_hash' => $this->string(255)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'preferred_language' => $this->string(10)->notNull()->defaultValue('es'),
            'country_id' => $this->smallInteger()->unsigned()->null(),
            'email_verified_at' => $this->dateTime()->null(),
            'phone_verified_at' => $this->dateTime()->null(),
            'last_login_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            'deleted_at' => $this->dateTime()->null(),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_users_public_id', '{{%users}}', 'public_id', true);
        $this->createIndex('ux_users_email', '{{%users}}', 'email', true);
        $this->createIndex('ux_users_phone', '{{%users}}', 'phone_e164', true);
        $this->createIndex('ix_users_status_created', '{{%users}}', ['status', 'created_at']);
        $this->addForeignKey('fk_users_country', '{{%users}}', 'country_id', '{{%countries}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%user_profiles}}', [
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'first_name' => $this->string(100)->notNull(),
            'last_name' => $this->string(100)->notNull(),
            'middle_name' => $this->string(100)->null(),
            'date_of_birth' => $this->date()->null(),
            'residence_country_id' => $this->smallInteger()->unsigned()->null(),
            'address_line' => $this->string(255)->null(),
            'city' => $this->string(100)->null(),
            'postal_code' => $this->string(20)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            'PRIMARY KEY (user_id)',
        ], self::TABLE_OPTIONS);
        $this->addForeignKey('fk_profiles_user', '{{%user_profiles}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_profiles_country', '{{%user_profiles}}', 'residence_country_id', '{{%countries}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%beneficiaries}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'sender_user_id' => $this->bigInteger()->unsigned()->notNull(),
            'country_id' => $this->smallInteger()->unsigned()->notNull(),
            'first_name' => $this->string(100)->notNull(),
            'last_name' => $this->string(100)->notNull(),
            'phone_e164' => $this->string(20)->null(),
            'email' => $this->string(190)->null(),
            'payout_method_code' => $this->string(30)->notNull(),
            'payout_provider' => $this->string(80)->null(),
            'payout_account_token' => $this->string(255)->null(),
            'payout_account_last4' => $this->string(4)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_beneficiaries_public_id', '{{%beneficiaries}}', 'public_id', true);
        $this->createIndex('ix_beneficiaries_sender', '{{%beneficiaries}}', ['sender_user_id', 'status']);
        $this->addForeignKey('fk_beneficiaries_sender', '{{%beneficiaries}}', 'sender_user_id', '{{%users}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_beneficiaries_country', '{{%beneficiaries}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%payment_methods}}', [
            'code' => $this->string(30)->notNull(),
            'name' => $this->string(100)->notNull(),
            'country_id' => $this->smallInteger()->unsigned()->null(),
            'direction' => $this->string(10)->notNull(), // inbound, outbound, both
            'requires_bank_account' => $this->boolean()->notNull()->defaultValue(false),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'configuration' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'PRIMARY KEY (code)',
        ], self::TABLE_OPTIONS);
        $this->addForeignKey('fk_payment_methods_country', '{{%payment_methods}}', 'country_id', '{{%countries}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%exchange_rates}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'base_currency' => $this->char(3)->notNull(),
            'quote_currency' => $this->char(3)->notNull(),
            'rate' => $this->decimal(20, 10)->notNull(),
            'source' => $this->string(80)->notNull(),
            'valid_from' => $this->dateTime()->notNull(),
            'valid_until' => $this->dateTime()->null(),
            'created_by_user_id' => $this->bigInteger()->unsigned()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ix_exchange_pair_valid', '{{%exchange_rates}}', ['base_currency', 'quote_currency', 'valid_from']);
        $this->addForeignKey('fk_rates_base_currency', '{{%exchange_rates}}', 'base_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_rates_quote_currency', '{{%exchange_rates}}', 'quote_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_rates_creator', '{{%exchange_rates}}', 'created_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%remittance_quotes}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'source_country_id' => $this->smallInteger()->unsigned()->notNull(),
            'destination_country_id' => $this->smallInteger()->unsigned()->notNull(),
            'send_amount' => $this->decimal(20, 2)->notNull(),
            'send_currency' => $this->char(3)->notNull(),
            'receive_amount' => $this->decimal(20, 2)->notNull(),
            'receive_currency' => $this->char(3)->notNull(),
            'exchange_rate' => $this->decimal(20, 10)->notNull(),
            'fee_amount' => $this->decimal(20, 2)->notNull()->defaultValue('0.00'),
            'fee_currency' => $this->char(3)->notNull(),
            'total_debit_amount' => $this->decimal(20, 2)->notNull(),
            'payout_method_code' => $this->string(30)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('valid'),
            'expires_at' => $this->dateTime()->notNull(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_quotes_public_id', '{{%remittance_quotes}}', 'public_id', true);
        $this->createIndex('ix_quotes_user_created', '{{%remittance_quotes}}', ['user_id', 'created_at']);
        $this->createIndex('ix_quotes_expiry_status', '{{%remittance_quotes}}', ['status', 'expires_at']);
        $this->addForeignKey('fk_quotes_user', '{{%remittance_quotes}}', 'user_id', '{{%users}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_quotes_source_country', '{{%remittance_quotes}}', 'source_country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_quotes_destination_country', '{{%remittance_quotes}}', 'destination_country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_quotes_send_currency', '{{%remittance_quotes}}', 'send_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_quotes_receive_currency', '{{%remittance_quotes}}', 'receive_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_quotes_fee_currency', '{{%remittance_quotes}}', 'fee_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_quotes_method', '{{%remittance_quotes}}', 'payout_method_code', '{{%payment_methods}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%remittances}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'reference' => $this->string(40)->notNull(),
            'sender_user_id' => $this->bigInteger()->unsigned()->notNull(),
            'beneficiary_id' => $this->bigInteger()->unsigned()->notNull(),
            'quote_id' => $this->bigInteger()->unsigned()->null(),
            'direction' => $this->string(10)->notNull()->defaultValue('ru_to_cu'),
            'source_country_id' => $this->smallInteger()->unsigned()->notNull(),
            'destination_country_id' => $this->smallInteger()->unsigned()->notNull(),
            'send_amount' => $this->decimal(20, 2)->notNull(),
            'send_currency' => $this->char(3)->notNull(),
            'receive_amount' => $this->decimal(20, 2)->notNull(),
            'receive_currency' => $this->char(3)->notNull(),
            'exchange_rate' => $this->decimal(20, 10)->notNull(),
            'fee_amount' => $this->decimal(20, 2)->notNull()->defaultValue('0.00'),
            'fee_currency' => $this->char(3)->notNull(),
            'total_debit_amount' => $this->decimal(20, 2)->notNull(),
            'status' => $this->string(25)->notNull()->defaultValue('awaiting_payment'),
            'compliance_status' => $this->string(20)->notNull()->defaultValue('pending'),
            'paid_at' => $this->dateTime()->null(),
            'completed_at' => $this->dateTime()->null(),
            'cancelled_at' => $this->dateTime()->null(),
            'failure_reason_code' => $this->string(60)->null(),
            'metadata' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_remittances_public_id', '{{%remittances}}', 'public_id', true);
        $this->createIndex('ux_remittances_reference', '{{%remittances}}', 'reference', true);
        $this->createIndex('ix_remittances_sender_created', '{{%remittances}}', ['sender_user_id', 'created_at']);
        $this->createIndex('ix_remittances_status_created', '{{%remittances}}', ['status', 'created_at']);
        $this->createIndex('ix_remittances_beneficiary', '{{%remittances}}', 'beneficiary_id');
        $this->addForeignKey('fk_remittances_sender', '{{%remittances}}', 'sender_user_id', '{{%users}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_remittances_beneficiary', '{{%remittances}}', 'beneficiary_id', '{{%beneficiaries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_remittances_quote', '{{%remittances}}', 'quote_id', '{{%remittance_quotes}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_remittances_source_country', '{{%remittances}}', 'source_country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_remittances_destination_country', '{{%remittances}}', 'destination_country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_remittances_send_currency', '{{%remittances}}', 'send_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_remittances_receive_currency', '{{%remittances}}', 'receive_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_remittances_fee_currency', '{{%remittances}}', 'fee_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%compliance_checks}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'remittance_id' => $this->bigInteger()->unsigned()->notNull(),
            'check_type' => $this->string(40)->notNull(),
            'provider' => $this->string(80)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'risk_level' => $this->string(20)->null(),
            'external_reference' => $this->string(120)->null(),
            'result_summary' => $this->text()->null(),
            'checked_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ix_compliance_remittance', '{{%compliance_checks}}', ['remittance_id', 'check_type', 'created_at']);
        $this->createIndex('ix_compliance_status', '{{%compliance_checks}}', ['status', 'risk_level']);
        $this->addForeignKey('fk_compliance_remittance', '{{%compliance_checks}}', 'remittance_id', '{{%remittances}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('{{%bank_connections}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'bank_code' => $this->string(30)->notNull(), // sberbank, vtb, alfa
            'connection_name' => $this->string(100)->notNull(),
            'environment' => $this->string(15)->notNull()->defaultValue('production'),
            'account_token' => $this->string(255)->notNull(), // token/referencia interna; no guardar credenciales aquí
            'account_mask' => $this->string(40)->null(),
            'account_currency' => $this->char(3)->notNull(),
            'country_id' => $this->smallInteger()->unsigned()->notNull(),
            'integration_mode' => $this->string(30)->notNull(), // api, host_to_host, statement_import
            'status' => $this->string(20)->notNull()->defaultValue('inactive'),
            'last_success_at' => $this->dateTime()->null(),
            'last_error_code' => $this->string(80)->null(),
            'last_error_at' => $this->dateTime()->null(),
            'configuration' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ix_bank_connections_bank_status', '{{%bank_connections}}', ['bank_code', 'status']);
        $this->createIndex('ux_bank_connections_account_env', '{{%bank_connections}}', ['bank_code', 'account_token', 'environment'], true);
        $this->addForeignKey('fk_bank_connections_currency', '{{%bank_connections}}', 'account_currency', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_bank_connections_country', '{{%bank_connections}}', 'country_id', '{{%countries}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%deposit_requests}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'remittance_id' => $this->bigInteger()->unsigned()->notNull(),
            'bank_connection_id' => $this->bigInteger()->unsigned()->notNull(),
            'expected_amount' => $this->decimal(20, 2)->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'payment_reference' => $this->string(80)->notNull(),
            'payer_name' => $this->string(160)->null(),
            'status' => $this->string(25)->notNull()->defaultValue('pending'),
            'expires_at' => $this->dateTime()->null(),
            'matched_transaction_id' => $this->bigInteger()->unsigned()->null(),
            'confirmed_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_deposit_requests_public_id', '{{%deposit_requests}}', 'public_id', true);
        $this->createIndex('ux_deposit_requests_reference', '{{%deposit_requests}}', 'payment_reference', true);
        $this->createIndex('ix_deposit_requests_status_expiry', '{{%deposit_requests}}', ['status', 'expires_at']);
        $this->createIndex('ix_deposit_requests_remittance', '{{%deposit_requests}}', 'remittance_id');
        $this->addForeignKey('fk_deposit_requests_remittance', '{{%deposit_requests}}', 'remittance_id', '{{%remittances}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_deposit_requests_connection', '{{%deposit_requests}}', 'bank_connection_id', '{{%bank_connections}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_deposit_requests_currency', '{{%deposit_requests}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%bank_webhook_events}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'bank_connection_id' => $this->bigInteger()->unsigned()->notNull(),
            'provider_event_id' => $this->string(160)->notNull(),
            'event_type' => $this->string(100)->notNull(),
            'signature_valid' => $this->boolean()->notNull()->defaultValue(false),
            'payload' => $this->json()->notNull(),
            'processing_status' => $this->string(20)->notNull()->defaultValue('received'),
            'processing_attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'last_error' => $this->text()->null(),
            'received_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'processed_at' => $this->dateTime()->null(),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_webhook_provider_event', '{{%bank_webhook_events}}', ['bank_connection_id', 'provider_event_id'], true);
        $this->createIndex('ix_webhook_processing', '{{%bank_webhook_events}}', ['processing_status', 'received_at']);
        $this->addForeignKey('fk_webhook_connection', '{{%bank_webhook_events}}', 'bank_connection_id', '{{%bank_connections}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%bank_transactions}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'bank_connection_id' => $this->bigInteger()->unsigned()->notNull(),
            'provider_transaction_id' => $this->string(160)->notNull(),
            'transaction_type' => $this->string(20)->notNull()->defaultValue('credit'),
            'amount' => $this->decimal(20, 2)->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'counterparty_name' => $this->string(190)->null(),
            'counterparty_account_mask' => $this->string(50)->null(),
            'payment_reference' => $this->string(100)->null(),
            'description' => $this->string(500)->null(),
            'booked_at' => $this->dateTime()->notNull(),
            'value_at' => $this->dateTime()->null(),
            'provider_status' => $this->string(30)->null(),
            'raw_payload' => $this->json()->null(),
            'source' => $this->string(20)->notNull()->defaultValue('api'),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_bank_tx_provider_id', '{{%bank_transactions}}', ['bank_connection_id', 'provider_transaction_id'], true);
        $this->createIndex('ix_bank_tx_booked', '{{%bank_transactions}}', ['bank_connection_id', 'booked_at']);
        $this->createIndex('ix_bank_tx_reference_amount', '{{%bank_transactions}}', ['payment_reference', 'amount', 'currency_code']);
        $this->addForeignKey('fk_bank_tx_connection', '{{%bank_transactions}}', 'bank_connection_id', '{{%bank_connections}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_bank_tx_currency', '{{%bank_transactions}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->addForeignKey('fk_deposit_matched_tx', '{{%deposit_requests}}', 'matched_transaction_id', '{{%bank_transactions}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%reconciliation_cases}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'bank_transaction_id' => $this->bigInteger()->unsigned()->notNull(),
            'deposit_request_id' => $this->bigInteger()->unsigned()->null(),
            'remittance_id' => $this->bigInteger()->unsigned()->null(),
            'case_type' => $this->string(30)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('open'),
            'match_score' => $this->decimal(5, 2)->null(),
            'resolution_code' => $this->string(60)->null(),
            'resolution_notes' => $this->text()->null(),
            'resolved_by_user_id' => $this->bigInteger()->unsigned()->null(),
            'resolved_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ix_reconciliation_status_created', '{{%reconciliation_cases}}', ['status', 'created_at']);
        $this->createIndex('ix_reconciliation_deposit', '{{%reconciliation_cases}}', 'deposit_request_id');
        $this->addForeignKey('fk_recon_bank_tx', '{{%reconciliation_cases}}', 'bank_transaction_id', '{{%bank_transactions}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_recon_deposit', '{{%reconciliation_cases}}', 'deposit_request_id', '{{%deposit_requests}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_recon_remittance', '{{%reconciliation_cases}}', 'remittance_id', '{{%remittances}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_recon_resolver', '{{%reconciliation_cases}}', 'resolved_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%wallets}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'owner_user_id' => $this->bigInteger()->unsigned()->null(),
            'wallet_type' => $this->string(20)->notNull()->defaultValue('settlement'),
            'currency_code' => $this->char(3)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ix_wallet_owner_currency', '{{%wallets}}', ['owner_user_id', 'currency_code']);
        $this->addForeignKey('fk_wallet_owner', '{{%wallets}}', 'owner_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_wallet_currency', '{{%wallets}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%wallet_ledger_entries}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'wallet_id' => $this->bigInteger()->unsigned()->notNull(),
            'remittance_id' => $this->bigInteger()->unsigned()->null(),
            'bank_transaction_id' => $this->bigInteger()->unsigned()->null(),
            'entry_type' => $this->string(30)->notNull(),
            'direction' => $this->string(6)->notNull(), // credit/debit
            'amount' => $this->decimal(20, 2)->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'idempotency_key' => $this->string(100)->notNull(),
            'description' => $this->string(255)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_ledger_idempotency', '{{%wallet_ledger_entries}}', 'idempotency_key', true);
        $this->createIndex('ix_ledger_wallet_created', '{{%wallet_ledger_entries}}', ['wallet_id', 'created_at']);
        $this->createIndex('ix_ledger_remittance', '{{%wallet_ledger_entries}}', 'remittance_id');
        $this->addForeignKey('fk_ledger_wallet', '{{%wallet_ledger_entries}}', 'wallet_id', '{{%wallets}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_ledger_remittance', '{{%wallet_ledger_entries}}', 'remittance_id', '{{%remittances}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_ledger_bank_tx', '{{%wallet_ledger_entries}}', 'bank_transaction_id', '{{%bank_transactions}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_ledger_currency', '{{%wallet_ledger_entries}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%payout_orders}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'remittance_id' => $this->bigInteger()->unsigned()->notNull(),
            'beneficiary_id' => $this->bigInteger()->unsigned()->notNull(),
            'payment_method_code' => $this->string(30)->notNull(),
            'provider' => $this->string(80)->null(),
            'amount' => $this->decimal(20, 2)->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'status' => $this->string(25)->notNull()->defaultValue('pending'),
            'provider_reference' => $this->string(160)->null(),
            'requested_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'paid_at' => $this->dateTime()->null(),
            'failure_code' => $this->string(80)->null(),
            'failure_message' => $this->text()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_payout_orders_public_id', '{{%payout_orders}}', 'public_id', true);
        $this->createIndex('ux_payout_provider_ref', '{{%payout_orders}}', ['provider', 'provider_reference'], true);
        $this->createIndex('ix_payout_status_requested', '{{%payout_orders}}', ['status', 'requested_at']);
        $this->addForeignKey('fk_payout_remittance', '{{%payout_orders}}', 'remittance_id', '{{%remittances}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_payout_beneficiary', '{{%payout_orders}}', 'beneficiary_id', '{{%beneficiaries}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_payout_method', '{{%payout_orders}}', 'payment_method_code', '{{%payment_methods}}', 'code', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_payout_currency', '{{%payout_orders}}', 'currency_code', '{{%currencies}}', 'code', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%payout_attempts}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'payout_order_id' => $this->bigInteger()->unsigned()->notNull(),
            'attempt_no' => $this->smallInteger()->unsigned()->notNull(),
            'provider_request_id' => $this->string(160)->null(),
            'status' => $this->string(25)->notNull()->defaultValue('started'),
            'request_payload' => $this->json()->null(),
            'response_payload' => $this->json()->null(),
            'error_code' => $this->string(80)->null(),
            'error_message' => $this->text()->null(),
            'started_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'finished_at' => $this->dateTime()->null(),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_payout_attempt_no', '{{%payout_attempts}}', ['payout_order_id', 'attempt_no'], true);
        $this->createIndex('ix_payout_attempt_status', '{{%payout_attempts}}', ['status', 'started_at']);
        $this->addForeignKey('fk_payout_attempt_order', '{{%payout_attempts}}', 'payout_order_id', '{{%payout_orders}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('{{%bank_sync_cursors}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'bank_connection_id' => $this->bigInteger()->unsigned()->notNull(),
            'stream_name' => $this->string(50)->notNull()->defaultValue('transactions'),
            'cursor_value' => $this->string(500)->null(),
            'last_synced_at' => $this->dateTime()->null(),
            'last_transaction_at' => $this->dateTime()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('idle'),
            'last_error' => $this->text()->null(),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_bank_sync_cursor_stream', '{{%bank_sync_cursors}}', ['bank_connection_id', 'stream_name'], true);
        $this->addForeignKey('fk_bank_sync_connection', '{{%bank_sync_cursors}}', 'bank_connection_id', '{{%bank_connections}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('{{%idempotency_keys}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'scope' => $this->string(80)->notNull(),
            'idempotency_key' => $this->string(100)->notNull(),
            'request_hash' => $this->char(64)->notNull(),
            'response_status' => $this->smallInteger()->unsigned()->null(),
            'response_body' => $this->json()->null(),
            'resource_type' => $this->string(50)->null(),
            'resource_id' => $this->string(80)->null(),
            'expires_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_idempotency_scope_key', '{{%idempotency_keys}}', ['scope', 'idempotency_key'], true);
        $this->createIndex('ix_idempotency_expiry', '{{%idempotency_keys}}', 'expires_at');

        $this->createTable('{{%audit_logs}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'actor_user_id' => $this->bigInteger()->unsigned()->null(),
            'action' => $this->string(100)->notNull(),
            'entity_type' => $this->string(80)->notNull(),
            'entity_id' => $this->string(80)->null(),
            'ip_address' => $this->string(45)->null(),
            'user_agent' => $this->string(500)->null(),
            'correlation_id' => $this->string(100)->null(),
            'before_data' => $this->json()->null(),
            'after_data' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ix_audit_entity', '{{%audit_logs}}', ['entity_type', 'entity_id', 'created_at']);
        $this->createIndex('ix_audit_actor_created', '{{%audit_logs}}', ['actor_user_id', 'created_at']);
        $this->createIndex('ix_audit_correlation', '{{%audit_logs}}', 'correlation_id');
        $this->addForeignKey('fk_audit_actor', '{{%audit_logs}}', 'actor_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        // Restricciones CHECK: aplicadas por MySQL 8.0.16+.
        $this->execute("ALTER TABLE {{%exchange_rates}} ADD CONSTRAINT chk_exchange_rate_positive CHECK (rate > 0)");
        $this->execute("ALTER TABLE {{%remittance_quotes}} ADD CONSTRAINT chk_quote_amounts CHECK (send_amount > 0 AND receive_amount > 0 AND fee_amount >= 0 AND total_debit_amount > 0)");
        $this->execute("ALTER TABLE {{%remittances}} ADD CONSTRAINT chk_remittance_amounts CHECK (send_amount > 0 AND receive_amount > 0 AND fee_amount >= 0 AND total_debit_amount > 0)");
        $this->execute("ALTER TABLE {{%bank_transactions}} ADD CONSTRAINT chk_bank_tx_amount_nonzero CHECK (amount <> 0)");
        $this->execute("ALTER TABLE {{%wallet_ledger_entries}} ADD CONSTRAINT chk_ledger_amount_positive CHECK (amount > 0)");
        $this->execute("ALTER TABLE {{%wallet_ledger_entries}} ADD CONSTRAINT chk_ledger_direction CHECK (direction IN ('credit', 'debit'))");
        $this->execute("ALTER TABLE {{%remittances}} ADD CONSTRAINT chk_remittance_direction CHECK (direction IN ('ru_to_cu', 'cu_to_ru', 'other'))");
        $this->execute("ALTER TABLE {{%bank_connections}} ADD CONSTRAINT chk_bank_environment CHECK (environment IN ('sandbox', 'production'))");

        // Catálogos mínimos iniciales. Revisar códigos/operatividad antes de producción.
        $this->batchInsert('{{%countries}}', ['iso2', 'iso3', 'name', 'phone_code', 'is_active'], [
            ['RU', 'RUS', 'Rusia', '+7', 1],
            ['CU', 'CUB', 'Cuba', '+53', 1],
        ]);
        $this->batchInsert('{{%currencies}}', ['code', 'name', 'minor_units', 'is_active'], [
            ['RUB', 'Rublo ruso', 2, 1],
            ['CUP', 'Peso cubano', 2, 1],
            ['USD', 'Dólar estadounidense', 2, 1],
            ['EUR', 'Euro', 2, 1],
        ]);
        $this->batchInsert('{{%payment_methods}}', ['code', 'name', 'country_id', 'direction', 'requires_bank_account', 'is_active'], [
            ['bank_transfer', 'Transferencia bancaria', 1, 'inbound', 1, 1],
            ['cash_pickup', 'Pago en efectivo', 2, 'outbound', 0, 1],
            ['bank_account', 'Abono en cuenta bancaria', 2, 'outbound', 1, 1],
            ['card_or_wallet', 'Tarjeta o billetera autorizada', 2, 'outbound', 0, 0],
        ]);
    }

    public function safeDown()
    {
        $tables = [
            'audit_logs',
            'idempotency_keys',
            'bank_sync_cursors',
            'payout_attempts',
            'payout_orders',
            'wallet_ledger_entries',
            'wallets',
            'reconciliation_cases',
            'deposit_requests',
            'bank_transactions',
            'bank_webhook_events',
            'bank_connections',
            'compliance_checks',
            'remittances',
            'remittance_quotes',
            'exchange_rates',
            'payment_methods',
            'beneficiaries',
            'user_profiles',
            'users',
            'currencies',
            'countries',
        ];
        foreach ($tables as $table) {
            $this->dropTable('{{%' . $table . '}}');
        }
    }
}
