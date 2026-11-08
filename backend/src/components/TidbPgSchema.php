<?php

declare(strict_types=1);

namespace app\components;

/**
 * Yii2 PostgreSQL Schema compatible con TiDB Cloud (preview "PostgreSQL
 * compatible"). TiDB aun no emula la columna pg_type.typndims ni las
 * SQL functions helpers de information_schema usadas por el Schema
 * estandar de Yii, por lo que esta subclase las elimina de la consulta
 * de introspeccion de columnas.
 *
 * En PostgreSQL 17 real el comportamiento es identico: solo sacrifica la
 * dimension (numero de dimensiones) de columnas array, que el proyecto no usa.
 */
final class TidbPgSchema extends \yii\db\pgsql\Schema
{
    /**
     * {@inheritdoc}
     */
    protected function findColumns($table)
    {
        $tableName = $this->db->quoteValue($table->name);
        $schemaName = $this->db->quoteValue($table->schemaName);

        $orIdentity = '';
        if (version_compare($this->db->serverVersion, '12.0', '>=')) {
            $orIdentity = 'OR attidentity != \'\'';
        }

        $sql = <<<SQL
SELECT
    d.nspname AS table_schema,
    c.relname AS table_name,
    a.attname AS column_name,
    t.typname AS data_type,
    t.typtype AS type_type,
    (SELECT nspname FROM pg_namespace WHERE oid = t.typnamespace) AS type_scheme,
    a.attlen AS character_maximum_length,
    pg_catalog.col_description(c.oid, a.attnum) AS column_comment,
    a.atttypmod AS modifier,
    a.attnotnull = false AS is_nullable,
    CAST(pg_get_expr(ad.adbin, ad.adrelid) AS varchar) AS column_default,
    coalesce(pg_get_expr(ad.adbin, ad.adrelid) ~ 'nextval',false) {$orIdentity} AS is_autoinc,
    pg_get_serial_sequence(quote_ident(d.nspname) || '.' || quote_ident(c.relname), a.attname) AS sequence_name,
    CASE WHEN t.typtype = 'e'::char
        THEN array_to_string((SELECT array_agg(enumlabel) FROM pg_enum WHERE enumtypid = t.oid)::varchar[], ',')
        ELSE NULL
    END AS enum_values,
    CASE atttypid
         WHEN 21 /*int2*/ THEN 16
         WHEN 23 /*int4*/ THEN 32
         WHEN 20 /*int8*/ THEN 64
         WHEN 1700 /*numeric*/ THEN
              CASE WHEN atttypmod = -1
               THEN null
               ELSE ((atttypmod - 4) >> 16) & 65535
               END
         WHEN 700 /*float4*/ THEN 24 /*FLT_MANT_DIG*/
         WHEN 701 /*float8*/ THEN 53 /*DBL_MANT_DIG*/
         ELSE null
      END   AS numeric_precision,
      CASE
        WHEN atttypid IN (21, 23, 20) THEN 0
        WHEN atttypid IN (1700) THEN
        CASE
            WHEN atttypmod = -1 THEN null
            ELSE (atttypmod - 4) & 65535
        END
           ELSE null
      END AS numeric_scale,
    CASE
        WHEN a.atttypid IN (1042, 1043) AND a.atttypmod > 0 THEN a.atttypmod - 4
        WHEN a.attlen > 0 THEN a.attlen
        ELSE null
    END AS size,
    a.attnum = any (ct.conkey) as is_pkey,
    0 AS dimension
FROM
    pg_class c
    LEFT JOIN pg_attribute a ON a.attrelid = c.oid
    LEFT JOIN pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum
    LEFT JOIN pg_type t ON a.atttypid = t.oid
    LEFT JOIN pg_namespace d ON d.oid = c.relnamespace
    LEFT JOIN pg_constraint ct ON ct.conrelid = c.oid AND ct.contype = 'p'
WHERE
    a.attnum > 0 AND t.typname != '' AND NOT a.attisdropped
    AND c.relname = {$tableName}
    AND d.nspname = {$schemaName}
ORDER BY
    a.attnum;
SQL;
        $columns = $this->db->createCommand($sql)->queryAll();
        if (empty($columns)) {
            return false;
        }
        foreach ($columns as $column) {
            if ($this->db->slavePdo->getAttribute(\PDO::ATTR_CASE) === \PDO::CASE_UPPER) {
                $column = array_change_key_case($column, CASE_LOWER);
            }
            $column = $this->loadColumnSchema($column);
            $table->columns[$column->name] = $column;
            if ($column->isPrimaryKey) {
                $table->primaryKey[] = $column->name;
                if ($table->sequenceName === null) {
                    $table->sequenceName = $column->sequenceName;
                }
                $column->defaultValue = null;
            } elseif ($column->defaultValue) {
                if (
                    in_array($column->type, [self::TYPE_TIMESTAMP, self::TYPE_DATE, self::TYPE_TIME], true) &&
                    (
                        in_array(
                            strtoupper($column->defaultValue),
                            ['NOW()', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'],
                            true
                        ) ||
                        (false !== strpos($column->defaultValue, '('))
                    )
                ) {
                    $column->defaultValue = new \yii\db\Expression($column->defaultValue);
                } elseif ($column->type === 'boolean') {
                    $column->defaultValue = ($column->defaultValue === 'true');
                } elseif (preg_match("/^B'(.*?)'::/", $column->defaultValue, $matches)) {
                    $column->defaultValue = bindec($matches[1]);
                } elseif (preg_match("/^'(.*?)'::/", $column->defaultValue, $matches)) {
                    $column->defaultValue = $column->phpTypecast($matches[1]);
                } elseif (preg_match('/^(\()?(.*?)(?(1)\))(?:::.+)?$/', $column->defaultValue, $matches)) {
                    if ($matches[2] === 'NULL') {
                        $column->defaultValue = null;
                    } else {
                        $column->defaultValue = $column->phpTypecast($matches[2]);
                    }
                } else {
                    $column->defaultValue = $column->phpTypecast($column->defaultValue);
                }
            }
        }

        return true;
    }
}
