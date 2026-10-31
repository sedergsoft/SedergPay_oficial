<?php

use yii\db\Migration;

class m261031_000003_seed_simulated_bank extends Migration
{
    public function safeUp()
    {
        $this->insert('{{%bank_connections}}', [
            'bank_code' => 'simulator',
            'connection_name' => 'Sandbox payment provider',
            'environment' => 'sandbox',
            'account_token' => 'simulated-bank-ru-001',
            'account_mask' => 'SIM-001',
            'account_currency' => 'RUB',
            'country_id' => 1,
            'integration_mode' => 'simulated',
            'status' => 'active',
            'configuration' => json_encode(['simulated' => true], JSON_THROW_ON_ERROR),
        ]);
    }

    public function safeDown()
    {
        $this->delete('{{%bank_connections}}', [
            'bank_code' => 'simulator',
            'environment' => 'sandbox',
            'account_token' => 'simulated-bank-ru-001',
        ]);
    }
}
