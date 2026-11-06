<?php

use yii\db\Migration;

/**
 * Idempotency resources use public UUIDs as well as internal numeric IDs.
 */
class m261101_000006_widen_idempotency_resource_id extends Migration
{
    public function safeUp()
    {
        $this->alterColumn('{{%idempotency_keys}}', 'resource_id', $this->string(80)->null());
    }

    public function safeDown()
    {
        $this->alterColumn('{{%idempotency_keys}}', 'resource_id', $this->bigInteger()->null());
    }
}
