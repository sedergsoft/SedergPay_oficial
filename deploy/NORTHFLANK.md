# Runbook — Backend en Northflank Sandbox Free

Estado verificado el 2026-11-10:

- La imagen `backend/Dockerfile` construye sin errores y arranca (`docker build` + `docker compose up backend`).
- `GET /health` responde `200 {"status":"ok","service":"sederg-pay-api","environment":"sandbox"}` contra PostgreSQL local.
- La base de datos de producción (TiDB Cloud Starter, pgwire) ya tiene el esquema aplicado:
  workflow *Run database migrations (TiDB Cloud)* → run `42813171320`, **success**, con
  `No new migrations found. Your system is up-to-date.` en las dos pasadas (idempotente).

Lo único que falta para tener API pública es crear el servicio: Northflank exige una cuenta
y un token de API (o hacer clic en el flujo de GitHub). Pasos abajo.

## 1. Datos del servicio

| Campo | Valor |
|---|---|
| Tipo | Service → Deployment |
| Build | Build from GitHub → `sedergsoft/SedergPay_oficial`, branch `main` |
| Build context | `backend` |
| Dockerfile | `backend/Dockerfile` |
| Puerto interno | `8080` (HTTP) |
| Health check | `GET /health`, esperando HTTP 200 |
| Plan de cómputo | el más pequeño del Sandbox (0.1–0.5 vCPU / 256–512 MB) |
| Región | la más cercana disponible (eu-* si existe) |

El `Dockerfile` ya usa `CMD ["sh","-c","php yii serve 0.0.0.0:${PORT:-8080} --docroot=web"]`,
así que respeta el `PORT` que inyecte Northflank.

## 2. Variables (Secret Group, nunca en Git)

```text
PORT=8080
SEDERG_DB_DSN=pgsql:host=<pgwire-host>;port=5432;dbname=<db>;sslmode=require
SEDERG_DB_USER=<usuario-tidb>
SEDERG_DB_PASSWORD=<password-tidb>
SEDERG_AUTH_MODE=development
SEDERG_COOKIE_KEY=<generar>
SEDERG_PII_KEY=<generar>
SEDERG_ACCOUNT_ENCRYPTION_KEY=<generar>
SEDERG_DEBUG=0
SEDERG_KYC_REQUIRED=0
SEDERG_PRIVATE_STORAGE_PATH=/var/lib/sederg-private
SEDERG_MAIL_TRANSPORT=smtp
SEDERG_MAIL_HOST=<smtp>
SEDERG_MAIL_PORT=2525
SEDERG_MAIL_ENCRYPTION=tls
SEDERG_MAIL_USERNAME=<usuario>
SEDERG_MAIL_PASSWORD=<password>
SEDERG_MAIL_FROM=<remitente>
SEDERG_APP_URL=https://sedergsoft.github.io/SedergPay_oficial/
SEDERG_CORS_ORIGINS=https://sedergsoft.github.io
```

Las tres claves (`SEDERG_COOKIE_KEY`, `SEDERG_PII_KEY`, `SEDERG_ACCOUNT_ENCRYPTION_KEY`) se
generan con el mismo criterio que en local (ver `scripts/Initialize-LocalAccountEncryption.ps1`)
y **no** se reutilizan las de desarrollo.

El DSN, usuario y contraseña de TiDB son los mismos que ya están en los secretos de GitHub
(`SEDERG_DB_*`); no hay que inventar valores nuevos.

## 3. Verificación (puerta de calidad)

```powershell
curl.exe -s https://<servicio>.code.run/health
# {"status":"ok","service":"sederg-pay-api","environment":"sandbox"}

curl.exe -s -X POST https://<servicio>.code.run/api/v1/quotes `
  -H "Content-Type: application/json" -d '{"source_currency":"USD","target_currency":"CUP","amount":"100"}'
```

Si `/health` falla por timeout de conexión al 5432, el Sandbox está bloqueando la salida TLS:
en ese caso se cambia de proveedor (Render Free / Koyeb Free) antes de tocar el frontend.

## 4. Después del backend

En GitHub → Settings → Secrets and variables → Actions → *Variables*:

- `VITE_API_BASE_URL=https://<servicio>.code.run`
- `VITE_AUTH_MODE=development` (sandbox) o `production` con OIDC configurado
- borrar `VITE_UI_PREVIEW` para que el *guard* exija URL real

Y redisparar el workflow *Deploy frontend to GitHub Pages*.

## 5. Migraciones pendientes de este checkout

`backend/migrations/m261108_000016_add_account_security_module.php` todavía **no** está en
`main` (untracked). Producción está migrada hasta `m261106_000015`. Si se publica ese módulo,
hay que commitearlo y volver a lanzar el workflow de migraciones antes de desplegar el backend
con los controladores nuevos.
