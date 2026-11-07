<?php

use yii\db\Migration;

final class m261107_000015_seed_cup_rub_exchange_rate extends Migration
{
    public function safeUp()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->insert('{{%exchange_rates}}', [
            'base_currency' => 'CUP',
            'quote_currency' => 'RUB',
            'rate' => '0.0370000000',
            'source' => 'admin_override',
            'valid_from' => $now->format('Y-m-d H:i:s'),
            'created_by_user_id' => null,
        ]);
    }

    public function safeDown()
    {
        $this->delete('{{%exchange_rates}}', [
            'base_currency' => 'CUP',
            'quote_currency' => 'RUB',
            'source' => 'admin_override',
        ]);
    }
}
