<?php

use yii\db\Migration;

/**
 * Adds an explicit role for sandbox administration.
 */
class m261101_000007_add_user_roles extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%users}}', 'role', $this->string(20)->notNull()->defaultValue('customer')->after('status'));
        $this->createIndex('ix_users_role_created', '{{%users}}', ['role', 'created_at']);
    }

    public function safeDown()
    {
        $this->dropIndex('ix_users_role_created', '{{%users}}');
        $this->dropColumn('{{%users}}', 'role');
    }
}
