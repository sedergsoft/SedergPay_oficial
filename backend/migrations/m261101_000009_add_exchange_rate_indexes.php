<?php

use yii\db\Migration;

class m261101_000009_add_exchange_rate_indexes extends Migration
{
    public function safeUp()
    {
        $this->createIndex(
            'ix_exchange_rates_lookup',
            '{{%exchange_rates}}',
            ['base_currency', 'quote_currency', 'source', 'valid_from']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('ix_exchange_rates_lookup', '{{%exchange_rates}}');
    }
}
