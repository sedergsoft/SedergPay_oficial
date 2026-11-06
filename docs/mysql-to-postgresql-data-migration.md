# Migración MySQL a PostgreSQL

Proceso no destructivo: no elimina ni modifica el volumen MySQL original.

## Requisitos
- Ejecutar desde la raíz del repositorio.
- Crear una copia de seguridad externa del volumen MySQL.
- Comprobar el nombre con docker volume ls. El valor predeterminado es sederg_pay_sederg_mysql_data; si es distinto, establecer SEDERG_MYSQL_VOLUME en .env.
- SEDERG_MYSQL_ROOT_PASSWORD debe ser la contraseña root del MySQL anterior.
- PostgreSQL debe tener aplicadas las migraciones Yii y sus tablas destino deben estar vacías.

## PowerShell
1. Detener aplicaciones: docker compose stop backend rate-updater frontend
2. Obtener el nombre del contenedor MySQL antiguo: docker ps -a --filter "name=mysql" --format "{{.Names}}"
3. Detener el contenedor MySQL antiguo con su nombre real, por ejemplo: docker stop sederg_pay-mysql-1
4. Iniciar PostgreSQL: docker compose up -d postgres
5. Ejecutar: docker compose -f docker-compose.yml -f docker-compose.migration.yml --profile migration up --build --abort-on-container-exit db-migrator
6. Revisar el informe de tablas y filas. Después iniciar: docker compose up -d backend rate-updater frontend
7. Comprobar: docker compose ps

No ejecutar docker compose down -v.

## Garantías y límites
- El volumen MySQL se monta en solo lectura; el origen no se modifica.
- No se ejecutan DROP ni TRUNCATE.
- Aborta si falta una tabla destino, una columna obligatoria no tiene correspondencia o una tabla destino ya contiene registros.
- La carga completa se hace en una transacción PostgreSQL y se revierte ante errores.
- Conserva IDs y reajusta secuencias; omite solo yii_migration, cuyo historial pertenece a las migraciones ya aplicadas en PostgreSQL.
- Requiere que el usuario PostgreSQL sea propietario/superusuario para desactivar temporalmente los triggers de FK dentro de la transacción.

## Validación posterior
Comparar recuentos de todas las tablas y verificar usuarios, beneficiarios, cotizaciones, remesas, pagos, ledger, conciliaciones, KYC y auditoría. Ejecutar flujos RU-CU y CU-RU, idempotencia y conciliación. No eliminar el volumen MySQL hasta completar auditoría y backup verificado.
