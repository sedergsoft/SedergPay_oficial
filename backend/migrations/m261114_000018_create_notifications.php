<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * In-app notifications for users (status changes, platform events).
 */
final class m261114_000018_create_notifications extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%notifications}}', [
            'id' => $this->bigPrimaryKey(),
            'public_id' => $this->char(36)->notNull(),
            'user_id' => $this->bigInteger()->notNull(),
            'type' => $this->string(64)->notNull(),
            'title' => $this->string(190)->notNull(),
            'body' => $this->text()->notNull(),
            'data' => $this->json()->null(),
            'is_read' => $this->boolean()->notNull()->defaultValue(false),
            'read_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        $this->createIndex('ux_notifications_public_id', '{{%notifications}}', 'public_id', true);
        $this->createIndex('ix_notifications_user_read_created', '{{%notifications}}', ['user_id', 'is_read', 'created_at']);
        $this->createIndex('ix_notifications_user_created', '{{%notifications}}', ['user_id', 'created_at']);
        $this->addForeignKey('fk_notifications_user', '{{%notifications}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_notifications_user', '{{%notifications}}');
        $this->dropTable('{{%notifications}}');
    }
}
