<?php

use yii\db\Migration;

final class m261103_000013_add_quote_fee_mode extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%remittance_quotes}}', 'fee_mode', $this->string(30)->notNull()->defaultValue('add_to_send')->after('fee_currency'));
        $this->createIndex('ix_quotes_fee_mode', '{{%remittance_quotes}}', 'fee_mode');
    }

    public function safeDown()
    {
        $this->dropIndex('ix_quotes_fee_mode', '{{%remittance_quotes}}');
        $this->dropColumn('{{%remittance_quotes}}', 'fee_mode');
    }
}
