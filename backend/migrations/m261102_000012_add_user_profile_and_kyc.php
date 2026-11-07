<?php

use yii\db\Migration;

/**
 * Adds editable customer profile and KYC case/document storage.
 * KYC files are stored outside the public web root; configure SEDERG_PRIVATE_STORAGE_PATH.
 */
class m261102_000012_add_user_profile_and_kyc extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%user_profiles}}', 'nationality_country_id', $this->smallInteger()->null());
        $this->addForeignKey('fk_profiles_nationality_country', '{{%user_profiles}}', 'nationality_country_id', '{{%countries}}', 'id', 'SET NULL', 'CASCADE');
        $this->createTable('{{%kyc_cases}}', [
            'id' => $this->bigPrimaryKey(),
            'public_id' => $this->char(36)->notNull(),
            'user_id' => $this->bigInteger()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('not_started'),
            'submitted_at' => $this->dateTime()->null(),
            'reviewed_at' => $this->dateTime()->null(),
            'reviewed_by_user_id' => $this->bigInteger()->null(),
            'review_reason' => $this->string(1000)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_kyc_cases_public_id', '{{%kyc_cases}}', 'public_id', true);
        $this->createIndex('ux_kyc_cases_user', '{{%kyc_cases}}', 'user_id', true);
        $this->createIndex('ix_kyc_cases_status', '{{%kyc_cases}}', ['status', 'submitted_at']);
        $this->addForeignKey('fk_kyc_case_user', '{{%kyc_cases}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_kyc_case_reviewer', '{{%kyc_cases}}', 'reviewed_by_user_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%kyc_documents}}', [
            'id' => $this->bigPrimaryKey(),
            'public_id' => $this->char(36)->notNull(),
            'kyc_case_id' => $this->bigInteger()->notNull(),
            'document_type' => $this->string(30)->notNull(),
            'storage_key' => $this->string(255)->notNull(),
            'original_name' => $this->string(190)->notNull(),
            'mime_type' => $this->string(100)->notNull(),
            'file_size' => $this->integer()->notNull(),
            'sha256' => $this->char(64)->notNull(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_kyc_documents_public_id', '{{%kyc_documents}}', 'public_id', true);
        $this->createIndex('ix_kyc_documents_case', '{{%kyc_documents}}', ['kyc_case_id', 'created_at']);
        $this->addForeignKey('fk_kyc_documents_case', '{{%kyc_documents}}', 'kyc_case_id', '{{%kyc_cases}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%kyc_documents}}');
        $this->dropTable('{{%kyc_cases}}');
        $this->dropForeignKey('fk_profiles_nationality_country', '{{%user_profiles}}');
        $this->dropColumn('{{%user_profiles}}', 'nationality_country_id');
    }
}
