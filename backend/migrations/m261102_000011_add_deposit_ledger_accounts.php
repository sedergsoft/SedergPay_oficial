<?php

use yii\db\Migration;

/**
 * Adds liability accounts for the funds-in side of a remittance,
 * so confirming a deposit can post a balanced double-entry movement.
 */
class m261102_000011_add_deposit_ledger_accounts extends Migration
{
    public function safeUp()
    {
        $accounts = [
            ['ledger-sandbox-deposits-rub', 'Sandbox customer deposits RUB', 'liability', 'RUB'],
            ['ledger-sandbox-deposits-cup', 'Sandbox customer deposits CUP', 'liability', 'CUP'],
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
            'ledger-sandbox-deposits-rub',
            'ledger-sandbox-deposits-cup',
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
