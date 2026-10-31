<?php

use yii\db\Migration;

/**
 * Controls required by the real-data MVP.
 *
 * This migration does not enable real banking or payment execution.
 */
class m261030_000002_add_mvp_controls extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        $this->createTable('{{%user_consents}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'consent_type' => $this->string(60)->notNull(),
            'policy_version' => $this->string(30)->notNull(),
            'granted_at' => $this->dateTime()->notNull(),
            'revoked_at' => $this->dateTime()->null(),
            'evidence_reference' => $this->string(160)->null(),
            'ip_address' => $this->string(45)->null(),
            'user_agent' => $this->string(500)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex(
            'ix_user_consents_user_type',
            '{{%user_consents}}',
            ['user_id', 'consent_type', 'granted_at']
        );
        $this->addForeignKey(
            'fk_user_consents_user',
            '{{%user_consents}}',
            'user_id',
            '{{%users}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );

        $this->createTable('{{%remittance_state_events}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'remittance_id' => $this->bigInteger()->unsigned()->notNull(),
            'from_status' => $this->string(25)->null(),
            'to_status' => $this->string(25)->notNull(),
            'actor_user_id' => $this->bigInteger()->unsigned()->null(),
            'reason' => $this->string(500)->notNull(),
            'correlation_id' => $this->string(100)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex(
            'ix_remittance_state_events_remittance',
            '{{%remittance_state_events}}',
            ['remittance_id', 'created_at']
        );
        $this->addForeignKey(
            'fk_remittance_state_events_remittance',
            '{{%remittance_state_events}}',
            'remittance_id',
            '{{%remittances}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk_remittance_state_events_actor',
            '{{%remittance_state_events}}',
            'actor_user_id',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        $this->createTable('{{%ledger_accounts}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'code' => $this->string(60)->notNull(),
            'name' => $this->string(160)->notNull(),
            'account_type' => $this->string(30)->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_ledger_accounts_public_id', '{{%ledger_accounts}}', 'public_id', true);
        $this->createIndex('ux_ledger_accounts_code_currency', '{{%ledger_accounts}}', ['code', 'currency_code'], true);
        $this->addForeignKey(
            'fk_ledger_accounts_currency',
            '{{%ledger_accounts}}',
            'currency_code',
            '{{%currencies}}',
            'code',
            'RESTRICT',
            'CASCADE'
        );

        $this->createTable('{{%ledger_transactions}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'reference' => $this->string(100)->notNull(),
            'remittance_id' => $this->bigInteger()->unsigned()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('posted'),
            'description' => $this->string(255)->notNull(),
            'posted_at' => $this->dateTime()->notNull(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_ledger_transactions_public_id', '{{%ledger_transactions}}', 'public_id', true);
        $this->createIndex('ux_ledger_transactions_reference', '{{%ledger_transactions}}', 'reference', true);
        $this->createIndex('ix_ledger_transactions_remittance', '{{%ledger_transactions}}', 'remittance_id');
        $this->addForeignKey(
            'fk_ledger_transactions_remittance',
            '{{%ledger_transactions}}',
            'remittance_id',
            '{{%remittances}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        $this->createTable('{{%ledger_entries}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'ledger_transaction_id' => $this->bigInteger()->unsigned()->notNull(),
            'ledger_account_id' => $this->bigInteger()->unsigned()->notNull(),
            'direction' => $this->string(6)->notNull(),
            'amount' => $this->decimal(20, 2)->notNull(),
            'currency_code' => $this->char(3)->notNull(),
            'idempotency_key' => $this->string(160)->notNull(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_ledger_entries_idempotency', '{{%ledger_entries}}', 'idempotency_key', true);
        $this->createIndex(
            'ix_ledger_entries_transaction',
            '{{%ledger_entries}}',
            'ledger_transaction_id'
        );
        $this->addForeignKey(
            'fk_ledger_entries_transaction',
            '{{%ledger_entries}}',
            'ledger_transaction_id',
            '{{%ledger_transactions}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk_ledger_entries_account',
            '{{%ledger_entries}}',
            'ledger_account_id',
            '{{%ledger_accounts}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk_ledger_entries_currency',
            '{{%ledger_entries}}',
            'currency_code',
            '{{%currencies}}',
            'code',
            'RESTRICT',
            'CASCADE'
        );
        $this->execute(
            "ALTER TABLE {{%ledger_entries}} "
            . "ADD CONSTRAINT chk_ledger_entries_amount_positive CHECK (amount > 0)"
        );
        $this->execute(
            "ALTER TABLE {{%ledger_entries}} "
            . "ADD CONSTRAINT chk_ledger_entries_direction CHECK (direction IN ('debit', 'credit'))"
        );

        $this->createTable('{{%outbox_messages}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'message_id' => $this->char(36)->notNull(),
            'topic' => $this->string(160)->notNull(),
            'aggregate_type' => $this->string(80)->notNull(),
            'aggregate_id' => $this->string(80)->notNull(),
            'payload' => $this->json()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'available_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'published_at' => $this->dateTime()->null(),
            'last_error' => $this->text()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('ux_outbox_message_id', '{{%outbox_messages}}', 'message_id', true);
        $this->createIndex(
            'ix_outbox_queue',
            '{{%outbox_messages}}',
            ['status', 'available_at', 'created_at']
        );
    }

    public function safeDown()
    {
        foreach ([
            'outbox_messages',
            'ledger_entries',
            'ledger_transactions',
            'ledger_accounts',
            'remittance_state_events',
            'user_consents',
        ] as $table) {
            $this->dropTable('{{%' . $table . '}}');
        }
    }
}
