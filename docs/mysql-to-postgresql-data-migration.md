# Migración MySQL a PostgreSQL

Procedimiento de importación no destructivo. El volumen MySQL original nunca se monta para escritura ni se elimina.

## Antes de empezar
- Ejecutar desde la raíz del repositorio y detener backend/rate-updater para evitar nuevas operaciones.
- Confirmar el volumen con `docker volume ls`. Por defecto se espera `sederg_pay_sederg_mysql_data`; si difiere, establecer `SEDERG_MYSQL_VOLUME` en `.env`.
- `SEDERG_MYSQL_ROOT_PASSWORD` debe ser la contraseña root del MySQL anterior. `SEDERG_DB_PASSWORD` es la contraseña PostgreSQL y puede ser distinta.
- PostgreSQL debe tener aplicadas las migraciones Yii y todas las tablas destino deben estar vacías.
- Crear un backup externo antes de continuar.

## PowerShell (Windows)

1. Detener aplicación: `docker compose stop backend rate-updater frontend`.
2. Confirmar volumen: `docker volume ls`.
3. Crear backup externo (sustituir el nombre si corresponde):
   `$mysqlVolume = "sederg_pay_sederg_mysql_data"`
   `docker run --rm -v "${mysqlVolume}:/source:ro" -v "${PWD}:/backup" alpine:3.22 sh -c "tar czf /backup/sederg_mysql_backup.tar.gz -C /source ."`
4. Detener el contenedor MySQL antiguo sin borrar el volumen. Obtener nombre con `docker ps -a --filter "name=mysql" --format "{{.Names}}"` y detenerlo con `docker stop <nombre-real>`.
5. Levantar PostgreSQL: `docker compose up -d postgres` y verificar `docker compose ps postgres`.
6. Construir el backend PostgreSQL y crear el esquema vacío: `docker compose run --rm --build --no-deps backend php yii migrate --interactive=0`.
7. Ejecutar migración: `docker compose -f docker-compose.yml -f docker-compose.migration.yml --profile migration up --build --abort-on-container-exit --exit-code-from db-migrator db-migrator`.
8. Solo si aparece `DATA TRANSFER COMPLETED`, iniciar: `docker compose up -d backend rate-updater frontend`; verificar `docker compose ps`.

No ejecutar `docker compose down -v`, no eliminar `sederg_mysql_data` y no borrar el contenedor antiguo hasta finalizar auditoría.

## Qué hace el mecanismo
1. `mysql-volume-clone` copia el volumen detenido a `legacy_mysql_migration_copy`; el origen se monta `read-only`.
2. MySQL 8.4 arranca sobre la copia escribible para permitir recuperación InnoDB sin tocar el original.
3. `db-migrator` transfiere tablas y columnas coincidentes a PostgreSQL dentro de una transacción.
4. Aborta si falta alguna tabla destino, una columna obligatoria no tiene equivalencia o una tabla destino ya tiene registros.
5. Conserva IDs, convierte booleanos y fechas cero cuando procede, y reajusta secuencias/identity.
6. Omite `yii_migration`: el historial Yii destino corresponde a las migraciones aplicadas en PostgreSQL.

El migrador requiere un rol PostgreSQL con privilegio para ejecutar `SET session_replication_role`.

## Reintentos
La importación no sobrescribe tablas destino. Si falla, revisa logs. La transacción debe revertirse completa. La copia temporal solo debe recrearse después de verificar que el volumen original y el backup están intactos. Si se elimina, borrar únicamente `<proyecto>_legacy_mysql_migration_copy`, nunca el volumen original.

## Validación posterior obligatoria
Comparar recuentos de todas las tablas con el informe; revisar usuarios, beneficiarios, cotizaciones, remesas, depósitos, pagos, ledger, conciliaciones, KYC, documentos y auditoría. Ejecutar flujos RU-CU y CU-RU, idempotencia, comisión, estados y conciliación. Mantener MySQL y su backup intactos hasta completar la auditoría.