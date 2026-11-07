<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * M-02: Agrega columnas de encadenamiento de hash (hash chain) a audit_logs
 * para impedir la alteración retrocesiva de registros de auditoría.
 *
 * Cada registro almacena el hash del registro anterior, formando una cadena
 * criptográfica. Cualquier modificación de un registro rompe la cadena y es
 * detectable al verificar la integridad.
 */
final class m261108_000016_add_audit_log_hash_chain extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%audit_logs}}', 'previous_hash', $this->string(64)->null()->after('id'));
        $this->addColumn('{{%audit_logs}}', 'hash_chain', $this->string(64)->null()->after('previous_hash'));
        $this->createIndex('ix_audit_hash_chain', '{{%audit_logs}}', 'hash_chain');
    }

    public function safeDown(): void
    {
        $this->dropIndex('ix_audit_hash_chain', '{{%audit_logs}}');
        $this->dropColumn('{{%audit_logs}}', 'hash_chain');
        $this->dropColumn('{{%audit_logs}}', 'previous_hash');
    }
}
