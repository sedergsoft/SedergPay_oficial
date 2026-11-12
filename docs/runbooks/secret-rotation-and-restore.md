# Runbooks: Rotación de secretos y restauración — SEDERG_PAY

## 1. Rotación de secretos (general)

### Principios
- Rotar fuera de horario crítico, con respaldo y plan de reversión. Nunca commitear secretos.
- Separar secretos por propósito: `SEDERG_COOKIE_KEY`, `SEDERG_PII_KEY`, `SEDERG_ACCOUNT_ENCRYPTION_KEY`, credenciales DB/SMTP/API, claves OIDC.

### Impacto y compatibilidad (crítico)
- **`SEDERG_ACCOUNT_ENCRYPTION_KEY` / `SEDERG_PII_KEY`:** si se usan para cifrar datos en reposo, rotar la clave sin re-cifrado previo puede volver **ilegibles** registros existentes (KYC/cuentas/datos sensibles). Procedimiento seguro:
  1. Inventariar datos cifrados y el esquema/versión de cifrado.
  2. Implementar/ejecutar migración de re-cifrado (clave antigua → nueva) en ventana de mantenimiento con backup verificado.
  3. Validar lectura/escritura antes de retirar la clave antigua.
- **`SEDERG_COOKIE_KEY`:** invalida sesiones/cookies firmadas; coordinar con usuarios.
- **Credenciales DB/SMTP/OIDC:** actualizar en secreto/`.env`, redesplegar y verificar conectividad; para OIDC preferir JWKS/rotación del IdP sin caída.

### Pasos operativos
1. Generar nuevos valores (`openssl rand -hex 32`, `openssl rand -base64 32` según corresponda).
2. Actualizar gestor de secretos/`.env` del entorno (staging → producción).
3. Redesplegar servicios afectados y validar `/health`, autenticación y flujos que usan el secreto.
4. Revocar/eliminar valores antiguos cuando sea seguro; registrar fecha, responsable y alcance.

## 2. Respaldos cifrados y copia externa

- **Generar dump:** `bash deploy/backup-postgres.sh` (verifica con `pg_restore --list`).
- **Cifrar (recomendado):** `bash deploy/backup-postgres-encrypted.sh` genera `.dump.age` o `.dump.gpg` si la herramienta está disponible, o indica cómo instalarla. Mantener la clave/passphrase fuera del servidor y del repo.
- **Copia offsite/inmutable:** copiar el artefacto cifrado a almacenamiento externo (p. ej. UpCloud Object Storage/S3 con versionado/bloqueo de objetos) según política de retención. Automatizar vía cron/tarea del operador y monitorear éxito.
- **Retención y pruebas:** definir retención (diaria/semanal/mensual), y ejecutar restauraciones de prueba periódicas en entorno aislado.

## 3. Restauración (prueba segura y real)

### Prueba sin afectar producción (por defecto)
```bash
bash deploy/restore-postgres.sh --file ~/backups/sederg_YYYY-MM-DD_HHMMSS.dump
```
Restaura en `<db>_restore_test` y ejecuta verificaciones básicas.

### Restaurar cifrado
Descifrar primero (ejemplos):
- age: `age -d -i /ruta/llave.txt backup.dump.age > backup.dump`
- gpg: `gpg --decrypt backup.dump.gpg > backup.dump`
Luego restaurar el `.dump` resultante.

### Restauración sobre la base real (solo emergencia, con autorización)
```bash
bash deploy/restore-postgres.sh --file <dump> --target sederg_pay --force
```
Requiere ventana de mantenimiento, confirmar impacto, y validar integridad/consistencia y funcionamiento de la aplicación después.

## 4. Respuesta a incidentes y abuso (mínimo)
- **Abuso/fuerza bruta:** revisar logs/alertas de throttling, bloquear IPs/orígenes en borde/WAF, ajustar límites por entorno (`SEDERG_THROTTLE_*`), invalidar credenciales/tokens comprometidos.
- **Sospecha de exposición de secretos:** rotar inmediatamente (con plan de re-cifrado si aplica), auditar accesos y desplegar parches.
- **Contención:** aislar servicio comprometido, preservar evidencia, restaurar desde estado conocido si es necesario.

> Mantener este runbook junto con `oidc-cutover.md` y los procedimientos operativos del despliegue.
