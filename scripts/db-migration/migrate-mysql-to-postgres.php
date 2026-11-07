<?php

declare(strict_types=1);

/**
 * Non-destructive MySQL -> PostgreSQL data importer.
 *
 * The source database is read only from this process. The target schema must
 * already have been created by the Yii migrations.
 */
function abortMigration(string $message): never
{
    throw new RuntimeException($message);
}

function pgIdent(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function myIdent(string $value): string
{
    return chr(96) . str_replace(chr(96), chr(96) . chr(96), $value) . chr(96);
}

$mysql = null;
$pg = null;

try {
    $mysql = new PDO(
        getenv('MYSQL_DSN') ?: 'mysql:host=mysql-source;port=3306;dbname=sederg_pay;charset=utf8mb4',
        getenv('MYSQL_USER') ?: 'root',
        getenv('MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]
    );

    $pg = new PDO(
        getenv('PG_DSN') ?: 'pgsql:host=postgres;port=5432;dbname=sederg_pay',
        getenv('PG_USER') ?: 'sederg_app',
        getenv('PG_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Yii2's default migration history table is "migration", not "yii_migration".
    // Both names are excluded for installations that customized the table name.
    $historyTables = ['migration', 'yii_migration'];
    $found = $mysql->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tables = array_values(array_filter(
        array_map(static fn(array $row): string => (string) $row[0], $found),
        static fn(string $table): bool => !in_array($table, $historyTables, true)
    ));
    sort($tables);

    if ($tables === []) {
        abortMigration('No MySQL source tables found.');
    }

    $targetTables = $pg->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $targetSet = array_fill_keys($targetTables, true);
    $missingTables = array_values(array_filter($tables, static fn(string $table): bool => !isset($targetSet[$table])));

    if ($missingTables !== []) {
        abortMigration('PostgreSQL schema is missing source tables; no data copied: ' . implode(', ', $missingTables));
    }

    // Yii migrations seed these reference tables. Reconcile them from MySQL by
    // primary key instead of rejecting the non-empty PostgreSQL seed rows.
    $seedTables = ['countries', 'currencies', 'payment_methods', 'funding_payment_methods', 'bank_connections', 'ledger_accounts'];

    $pg->beginTransaction();
    // Keep explicit source IDs and avoid firing FK/updated_at triggers during bulk load.
    // The PostgreSQL application role created by the official image is a superuser.
    $pg->exec('SET LOCAL session_replication_role = replica');

    $totalRows = 0;
    $report = [];

    foreach ($tables as $table) {
        $pgTable = pgIdent($table);
        $mysqlTable = myIdent($table);
        $isSeedTable = in_array($table, $seedTables, true);

        $targetCountBefore = (int) $pg->query("SELECT COUNT(*) FROM {$pgTable}")->fetchColumn();
        if (!$isSeedTable && $targetCountBefore !== 0) {
            abortMigration("Target table {$table} already contains {$targetCountBefore} rows; refusing to overwrite.");
        }

        $sourceColumns = $mysql->query("SHOW COLUMNS FROM {$mysqlTable}")->fetchAll();
        $sourceColumnNames = [];
        foreach ($sourceColumns as $column) {
            $sourceColumnNames[(string) $column['Field']] = $column;
        }

        $targetColumnQuery = $pg->prepare(
            "SELECT column_name, data_type, is_nullable, column_default, is_identity, is_generated
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :table
             ORDER BY ordinal_position"
        );
        $targetColumnQuery->execute([':table' => $table]);
        $targetColumns = $targetColumnQuery->fetchAll();

        $targetColumnMap = [];
        foreach ($targetColumns as $column) {
            $targetColumnMap[$column['column_name']] = $column;
        }

        // Never silently discard source data because a column was renamed or omitted.
        foreach ($sourceColumnNames as $name => $sourceColumn) {
            if (!isset($targetColumnMap[$name])) {
                abortMigration("Source column {$table}.{$name} has no PostgreSQL equivalent; define an explicit mapping before continuing.");
            }
        }

        $copyColumns = [];
        $hasAlwaysIdentity = false;
        foreach ($sourceColumns as $column) {
            $name = (string) $column['Field'];
            $targetColumn = $targetColumnMap[$name];

            if ($targetColumn['is_generated'] === 'ALWAYS') {
                // PostgreSQL computed columns are recalculated from their expression.
                continue;
            }

            if ($targetColumn['is_identity'] === 'YES' && stripos((string) $targetColumn['column_default'], 'ALWAYS') !== false) {
                $hasAlwaysIdentity = true;
            }
            $copyColumns[] = $name;
        }

        if ($copyColumns === []) {
            abortMigration("No compatible data columns found for {$table}.");
        }

        foreach ($targetColumns as $column) {
            $required = $column['is_nullable'] === 'NO'
                && $column['column_default'] === null
                && $column['is_identity'] !== 'YES'
                && $column['is_generated'] === 'NEVER';

            if ($required && !isset($sourceColumnNames[$column['column_name']])) {
                abortMigration("Required PostgreSQL column {$table}.{$column['column_name']} has no MySQL source value or default.");
            }
        }

        $primaryKeyQuery = $pg->prepare(
            "SELECT kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_catalog = tc.constraint_catalog
              AND kcu.constraint_schema = tc.constraint_schema
              AND kcu.constraint_name = tc.constraint_name
              AND kcu.table_name = tc.table_name
             WHERE tc.table_schema = 'public'
               AND tc.table_name = :table
               AND tc.constraint_type = 'PRIMARY KEY'
             ORDER BY kcu.ordinal_position"
        );
        $primaryKeyQuery->execute([':table' => $table]);
        $primaryKey = $primaryKeyQuery->fetchAll(PDO::FETCH_COLUMN);

        if ($isSeedTable && $primaryKey === []) {
            abortMigration("Seed table {$table} has no primary key; safe reconciliation is impossible.");
        }

        $selectColumns = implode(', ', array_map('myIdent', $copyColumns));
        $insertColumns = implode(', ', array_map('pgIdent', $copyColumns));
        $placeholders = implode(', ', array_map(static fn(string $name): string => ':' . $name, $copyColumns));
        $identityClause = $hasAlwaysIdentity ? ' OVERRIDING SYSTEM VALUE' : '';

        $upsertClause = '';
        if ($isSeedTable) {
            $updateColumns = array_values(array_diff($copyColumns, $primaryKey));
            if ($updateColumns === []) {
                $upsertClause = ' ON CONFLICT (' . implode(', ', array_map('pgIdent', $primaryKey)) . ') DO NOTHING';
            } else {
                $assignments = array_map(
                    static fn(string $name): string => pgIdent($name) . ' = EXCLUDED.' . pgIdent($name),
                    $updateColumns
                );
                $upsertClause = ' ON CONFLICT (' . implode(', ', array_map('pgIdent', $primaryKey)) . ') DO UPDATE SET '
                    . implode(', ', $assignments);
            }
        }

        $sourceRows = $mysql->query("SELECT {$selectColumns} FROM {$mysqlTable}");
        $insert = $pg->prepare(
            "INSERT INTO {$pgTable} ({$insertColumns}){$identityClause} VALUES ({$placeholders}){$upsertClause}"
        );

        $sourceCount = 0;
        while ($row = $sourceRows->fetch(PDO::FETCH_ASSOC)) {
            $values = [];

            foreach ($copyColumns as $name) {
                $value = $row[$name];
                $targetType = $targetColumnMap[$name]['data_type'];

                if (is_string($value) && in_array(
                    $value,
                    ['0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00:00.000000'],
                    true
                )) {
                    $value = null;
                }

                if ($targetType === 'boolean' && $value !== null) {
                    $value = in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
                }

                if ($targetType === 'bytea' && is_string($value)) {
                    $value = '\\x' . bin2hex($value);
                }

                $values[':' . $name] = $value;
            }

            $insert->execute($values);
            $sourceCount++;
        }

        $targetCountAfter = (int) $pg->query("SELECT COUNT(*) FROM {$pgTable}")->fetchColumn();
        if ($isSeedTable) {
            if ($targetCountAfter < $sourceCount) {
                abortMigration("Reference table {$table} has fewer PostgreSQL rows ({$targetCountAfter}) than MySQL source rows ({$sourceCount}).");
            }
        } elseif ($targetCountAfter !== $sourceCount) {
            abortMigration("Row count mismatch for {$table}: source={$sourceCount}, target={$targetCountAfter}.");
        }

        $report[$table] = ['source_rows' => $sourceCount, 'target_rows' => $targetCountAfter];
        $totalRows += $sourceCount;
    }

    // Re-enable triggers before explicitly checking every FK relationship.
    $pg->exec('SET LOCAL session_replication_role = origin');

    $foreignKeys = $pg->query(
        "SELECT con.conname,
                child.relname AS child_table,
                parent.relname AS parent_table,
                array_agg(child_att.attname ORDER BY key_cols.ord) AS child_columns,
                array_agg(parent_att.attname ORDER BY key_cols.ord) AS parent_columns
         FROM pg_constraint con
         JOIN pg_class child ON child.oid = con.conrelid
         JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace
         JOIN pg_class parent ON parent.oid = con.confrelid
         JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS key_cols(attnum, ord) ON TRUE
         JOIN LATERAL unnest(con.confkey) WITH ORDINALITY AS parent_keys(attnum, ord)
           ON parent_keys.ord = key_cols.ord
         JOIN pg_attribute child_att ON child_att.attrelid = child.oid AND child_att.attnum = key_cols.attnum
         JOIN pg_attribute parent_att ON parent_att.attrelid = parent.oid AND parent_att.attnum = parent_keys.attnum
         WHERE con.contype = 'f' AND child_ns.nspname = 'public'
         GROUP BY con.conname, child.relname, parent.relname"
    )->fetchAll();

    foreach ($foreignKeys as $foreignKey) {
        $childTable = pgIdent((string) $foreignKey['child_table']);
        $parentTable = pgIdent((string) $foreignKey['parent_table']);
        $childColumns = str_getcsv(trim((string) $foreignKey['child_columns'], '{}'));
        $parentColumns = str_getcsv(trim((string) $foreignKey['parent_columns'], '{}'));

        $joins = [];
        $notNull = [];
        foreach ($childColumns as $index => $childColumn) {
            $childColumn = trim($childColumn, '"');
            $parentColumn = trim($parentColumns[$index], '"');
            $joins[] = 'c.' . pgIdent($childColumn) . ' = p.' . pgIdent($parentColumn);
            $notNull[] = 'c.' . pgIdent($childColumn) . ' IS NOT NULL';
        }

        $firstParentColumn = trim($parentColumns[0], '"');
        $orphanSql = 'SELECT 1 FROM ' . $childTable . ' c LEFT JOIN ' . $parentTable . ' p ON '
            . implode(' AND ', $joins)
            . ' WHERE ' . implode(' AND ', $notNull)
            . ' AND p.' . pgIdent($firstParentColumn) . ' IS NULL LIMIT 1';

        if ($pg->query($orphanSql)->fetchColumn() !== false) {
            abortMigration(
                'Foreign-key validation failed for ' . $foreignKey['conname']
                . ' (' . $foreignKey['child_table'] . ' -> ' . $foreignKey['parent_table'] . ').'
            );
        }
    }

    // Reset all PostgreSQL serial/identity sequences to the imported maximum.
    $identityColumns = $pg->query(
        "SELECT table_name, column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (is_identity = 'YES' OR column_default LIKE 'nextval(%')"
    )->fetchAll();

    foreach ($identityColumns as $identity) {
        $table = (string) $identity['table_name'];
        $column = (string) $identity['column_name'];
        $sequenceQuery = $pg->prepare('SELECT pg_get_serial_sequence(:table_name, :column_name)');
        $sequenceQuery->execute([':table_name' => $table, ':column_name' => $column]);
        $sequenceName = $sequenceQuery->fetchColumn();

        if ($sequenceName) {
            $tableSql = pgIdent($table);
            $columnSql = pgIdent($column);
            $resetSequence = $pg->prepare(
                "SELECT setval(:sequence_name,
                    GREATEST(COALESCE((SELECT MAX({$columnSql}) FROM {$tableSql}), 1), 1),
                    EXISTS (SELECT 1 FROM {$tableSql}))"
            );
            $resetSequence->execute([':sequence_name' => $sequenceName]);
        }
    }

    $pg->commit();

    echo "DATA TRANSFER COMPLETED\n";
    echo 'Tables: ' . count($report) . "\n";
    echo "Source rows processed: {$totalRows}\n";
    foreach ($report as $table => $counts) {
        echo $table . ': source=' . $counts['source_rows'] . ', target=' . $counts['target_rows'] . "\n";
    }
    echo "MySQL source unchanged. No PostgreSQL table was dropped or truncated.\n";
} catch (Throwable $error) {
    if ($pg instanceof PDO && $pg->inTransaction()) {
        $pg->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
