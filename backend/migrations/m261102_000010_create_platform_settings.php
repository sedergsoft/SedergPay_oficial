<?php

use yii\db\Migration;

final class m261102_000010_create_platform_settings extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%platform_settings}}', [
            'setting_key' => $this->string(100)->notNull(),
            'setting_value' => $this->string(255)->notNull(),
            'value_type' => $this->string(20)->notNull(),
            'updated_by_user_id' => $this->bigInteger()->unsigned()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
            'PRIMARY KEY (setting_key)',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->createIndex(
            'ix_platform_settings_updated_by',
            '{{%platform_settings}}',
            'updated_by_user_id'
        );
        $this->addForeignKey(
            'fk_platform_settings_updated_by',
            '{{%platform_settings}}',
            'updated_by_user_id',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_platform_settings_updated_by', '{{%platform_settings}}');
        $this->dropIndex('ix_platform_settings_updated_by', '{{%platform_settings}}');
        $this->dropTable('{{%platform_settings}}');
    }
}
