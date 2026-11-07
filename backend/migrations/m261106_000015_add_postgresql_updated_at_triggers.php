<?php

use yii\db\Migration;

/**
 * PostgreSQL equivalent of MySQL's ON UPDATE CURRENT_TIMESTAMP.
 * A database trigger keeps updated_at consistent for writes from API, jobs and SQL clients.
 */
class m261106_000015_add_postgresql_updated_at_triggers extends Migration
{
    private const TABLES = [
        'users',
        'user_profiles',
        'beneficiaries',
        'remittances',
        'bank_connections',
        'deposit_requests',
        'reconciliation_cases',
        'wallets',
        'payout_orders',
        'bank_sync_cursors',
        'platform_settings',
        'kyc_cases',
        'funding_payment_methods',
        'platform_funding_accounts',
    ];

    public function safeUp(): void
    {
        $this->execute(<<<'SQL'
CREATE OR REPLACE FUNCTION sederg_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        foreach (self::TABLES as $table) {
            $this->execute('CREATE TRIGGER ' . $this->db->quoteTableName('trg_' . $table . '_updated_at')
                . ' BEFORE UPDATE ON ' . $this->db->quoteTableName($table)
                . ' FOR EACH ROW EXECUTE FUNCTION sederg_set_updated_at()');
        }
    }

    public function safeDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->execute('DROP TRIGGER IF EXISTS ' . $this->db->quoteTableName('trg_' . $table . '_updated_at')
                . ' ON ' . $this->db->quoteTableName($table));
        }
        $this->execute('DROP FUNCTION IF EXISTS sederg_set_updated_at()');
    }
}
