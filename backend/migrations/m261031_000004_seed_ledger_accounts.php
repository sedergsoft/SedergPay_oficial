<?php

use yii\db\Migration;

class m261031_000004_seed_ledger_accounts extends Migration
{
    public function safeUp()
    {
        $accounts = [
            ['ledger-sandbox-funds-rub', 'Sandbox funds RUB', 'asset', 'RUB'],
            ['ledger-sandbox-payout-rub', 'Sandbox payouts RUB', 'expense', 'RUB'],
            ['ledger-sandbox-funds-cup', 'Sandbox funds CUP', 'asset', 'CUP'],
            ['ledger-sandbox-payout-cup', 'Sandbox payouts CUP', 'expense', 'CUP'],
        ];
        foreach ($accounts as [$code, $name, $type, $currency]) {
            $this->insert('{{%ledger_accounts}}', [
                'public_id' => $this->uuid(),
                'code' => $code,
                'name' => $name,
                'account_type' => $type,
                'currency_code' => $currency,
                'status' => 'active',
            ]);
        }
    }

    public function safeDown()
    {
        $this->delete('{{%ledger_accounts}}', ['code' => [
            'ledger-sandbox-funds-rub',
            'ledger-sandbox-payout-rub',
            'ledger-sandbox-funds-cup',
            'ledger-sandbox-payout-cup',
        ]]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
