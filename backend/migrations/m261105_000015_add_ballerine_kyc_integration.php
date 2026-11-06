<?php

use yii\db\Migration;

/**
 * Binds internal KYC cases to Ballerine collection workflows and stores
 * authenticated provider events with an idempotency key.
 */
class m261105_000015_add_ballerine_kyc_integration extends Migration
{
    public function safeUp(): void
    {
        // Provider states such as "resubmission_required" exceed the original
        // varchar(20) status column. Widen it before any provider event can
        // write an internal status.
        $this->alterColumn(
            '{{%kyc_cases}}',
            'status',
            $this->string(30)->notNull()->defaultValue('not_started')
        );

        $this->addColumn('{{%kyc_cases}}', 'provider_name', $this->string(30)->null()->after('status'));
        $this->addColumn('{{%kyc_cases}}', 'provider_session_id', $this->string(190)->null()->after('provider_name'));
        $this->addColumn('{{%kyc_cases}}', 'provider_entity_id', $this->string(190)->null()->after('provider_session_id'));
        $this->addColumn('{{%kyc_cases}}', 'provider_status', $this->string(60)->null()->after('provider_entity_id'));
        $this->addColumn('{{%kyc_cases}}', 'provider_last_event_id', $this->string(190)->null()->after('provider_status'));
        $this->addColumn('{{%kyc_cases}}', 'provider_last_event_at', $this->dateTime()->null()->after('provider_last_event_id'));
        $this->addColumn('{{%kyc_cases}}', 'provider_collection_url', $this->string(2048)->null()->after('provider_last_event_at'));
        $this->addColumn('{{%kyc_cases}}', 'provider_session_expires_at', $this->dateTime()->null()->after('provider_collection_url'));
        $this->createIndex(
            'ux_kyc_cases_provider_session',
            '{{%kyc_cases}}',
            ['provider_name', 'provider_session_id'],
            true
        );
        $this->createIndex('ix_kyc_cases_provider_status', '{{%kyc_cases}}', ['provider_status', 'updated_at']);

        $this->createTable('{{%kyc_provider_events}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'public_id' => $this->char(36)->notNull(),
            'kyc_case_id' => $this->bigInteger()->unsigned()->null(),
            'provider_name' => $this->string(30)->notNull(),
            'provider_event_id' => $this->string(190)->notNull(),
            'provider_session_id' => $this->string(190)->notNull(),
            'event_type' => $this->string(190)->notNull(),
            'provider_status' => $this->string(60)->null(),
            'internal_status' => $this->string(30)->null(),
            'outcome' => $this->string(30)->notNull()->defaultValue('observed'),
            'occurred_at' => $this->dateTime()->notNull(),
            'received_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'processed_at' => $this->dateTime()->null(),
            'payload_sha256' => $this->char(64)->notNull(),
            'payload_summary' => $this->json()->notNull(),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->createIndex('ux_kyc_provider_events_public_id', '{{%kyc_provider_events}}', 'public_id', true);
        $this->createIndex(
            'ux_kyc_provider_events_event',
            '{{%kyc_provider_events}}',
            ['provider_name', 'provider_event_id'],
            true
        );
        $this->createIndex(
            'ix_kyc_provider_events_session',
            '{{%kyc_provider_events}}',
            ['provider_name', 'provider_session_id', 'occurred_at']
        );
        $this->addForeignKey(
            'fk_kyc_provider_events_case',
            '{{%kyc_provider_events}}',
            'kyc_case_id',
            '{{%kyc_cases}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%kyc_provider_events}}');
        $this->dropIndex('ix_kyc_cases_provider_status', '{{%kyc_cases}}');
        $this->dropIndex('ux_kyc_cases_provider_session', '{{%kyc_cases}}');
        $this->dropColumn('{{%kyc_cases}}', 'provider_session_expires_at');
        $this->dropColumn('{{%kyc_cases}}', 'provider_collection_url');
        $this->dropColumn('{{%kyc_cases}}', 'provider_last_event_at');
        $this->dropColumn('{{%kyc_cases}}', 'provider_last_event_id');
        $this->dropColumn('{{%kyc_cases}}', 'provider_status');
        $this->dropColumn('{{%kyc_cases}}', 'provider_entity_id');
        $this->dropColumn('{{%kyc_cases}}', 'provider_session_id');
        $this->dropColumn('{{%kyc_cases}}', 'provider_name');

        $this->alterColumn(
            '{{%kyc_cases}}',
            'status',
            $this->string(20)->notNull()->defaultValue('not_started')
        );
    }
}
