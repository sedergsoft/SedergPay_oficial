<?php

use yii\db\Migration;

class m261031_000005_add_oidc_subject extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%users}}', 'oidc_subject', $this->string(255)->null()->after('public_id'));
        $this->createIndex('ux_users_oidc_subject', '{{%users}}', 'oidc_subject', true);
    }

    public function safeDown()
    {
        $this->dropIndex('ux_users_oidc_subject', '{{%users}}');
        $this->dropColumn('{{%users}}', 'oidc_subject');
    }
}
