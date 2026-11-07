# Migración MySQL a PostgreSQL

Procedimiento de importación no destructivo. El volumen MySQL original nunca se monta para escritura ni se elimina.

## Antes de empezar
- Ejecutar desde la raíz del repositorio y detener backend/rate-updater para evitar nuevas operaciones.
- Confirmar el volumen con `docker volume ls`. Por defecto se espera `sederg_pay_sederg_mysql_data`; si difiere, establecer `SEDERG_MYSQL_VOLUME` en `.env`.
- `SEDERG_MYSQL_ROOT_PASSWORD` debe ser la contraseña root del MySQL anterior. `SEDERG_DB_PASSWORD` es la contraseña PostgreSQL y puede ser distinta.
- PostgreSQL debe tener aplicadas las migraciones Yii y todas las tablas destino deben estar vacías.
- Crear un backup externo antes de continuar.

## PowerShell (Windows)

1. Actualiza en `.env` `SEDERG_MYSQL_ROOT_PASSWORD`, `SEDERG_DB_PASSWORD` y, si corresponde, `SEDERG_MYSQL_VOLUME`.
2. Abre PowerShell en la raíz del repositorio y ejecuta:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Invoke-MySqlToPostgresMigration.ps1
```

El script:
- valida la configuración Docker Compose y comprueba que existe el volumen MySQL;
- solicita confirmación antes de detener contenedores;
- detiene los contenedores que utilizan el volumen antiguo y genera un backup comprimido de solo lectura;
- inicia PostgreSQL, aplica las migraciones Yii y ejecuta la transferencia;
- solo si el importador termina con `DATA TRANSFER COMPLETED`, levanta todos los servicios y muestra su estado.

Si ya existe un backup externo verificado, puede usarse `-SkipBackup`; no se recomienda omitir el backup inicial.

No ejecutar `docker compose down -v`, no eliminar el volumen MySQL original ni el backup hasta finalizar la auditoría financiera.

## Qué hace el mecanismo
1. `mysql-source` copia el volumen detenido a `legacy_mysql_migration_copy` cuando la copia está vacía; el origen se monta `read-only`.
2. MySQL 8.4 arranca sobre la copia escribible para permitir recuperación InnoDB sin tocar el original. La copia solo se reutiliza si existe su marcador de finalización en `legacy_mysql_migration_state`; si la copia quedó parcial, el contenedor aborta en lugar de iniciar MySQL con datos incompletos.
3. `db-migrator` transfiere tablas y columnas coincidentes a PostgreSQL dentro de una transacción.
4. Aborta si falta alguna tabla destino, una columna obligatoria no tiene equivalencia o una tabla destino ya tiene registros.
5. Conserva IDs, convierte booleanos y fechas cero cuando procede, y reajusta secuencias/identity.
6. Omite `yii_migration`: el historial Yii destino corresponde a las migraciones aplicadas en PostgreSQL.

El migrador requiere un rol PostgreSQL con privilegio para ejecutar `SET session_replication_role`.

## Reintentos
La importación no sobrescribe tablas destino. Si falla, revisa logs. La transacción debe revertirse completa. La copia temporal solo debe recrearse después de verificar que el volumen original y el backup están intactos. Si se elimina, borrar únicamente `<proyecto>_legacy_mysql_migration_copy` y `<proyecto>_legacy_mysql_migration_state`, nunca el volumen original.

## Validación posterior obligatoria
Comparar recuentos de todas las tablas con el informe; revisar usuarios, beneficiarios, cotizaciones, remesas, depósitos, pagos, ledger, conciliaciones, KYC, documentos y auditoría. Ejecutar flujos RU-CU y CU-RU, idempotencia, comisión, estados y conciliación. Mantener MySQL y su backup intactos hasta completar la auditoría.