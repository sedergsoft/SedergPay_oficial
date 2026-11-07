# SEDERG_PAY — Plan de despliegue: TiDB Cloud + GitHub Pages + backend gratuito

## Objetivo

Desplegar el MVP sandbox sin coste usando:

| Componente | Servicio | Plan |
|---|---|---|
| Base de datos | **TiDB Cloud Starter** (PostgreSQL-compatible, Public Preview) | Free tier |
| Frontend | **GitHub Pages** (SPA React/Vite) | Gratis |
| Backend API (Yii2/PHP 8.3) | **Render Free** (contenedor Docker) — alternativa: Koyeb Free | Free tier |
| Redis | **Upstash Free** | Free tier |

Este plan no habilita dinero real. Todo permanece en sandbox hasta completar
los gates de [PLAN_DE_ACCION.md](./PLAN_DE_ACCION.md).

## Advertencias críticas (leer antes de ejecutar)

1. **TiDB Cloud PostgreSQL-compatible está en Public Preview.** El motor es
   TiDB emulando PostgreSQL (protocolo pgwire, PL/pgSQL parcial, triggers
   `BEFORE`/`AFTER`). No es PostgreSQL 17 real: ejecutar TODAS las migraciones
   contra la instancia antes de confiar en ella. Cobertura documentada:
   <https://docs.pingcap.com/tidbcloud/postgresql-compatibility/>.
   - La migración `m261106_000015_add_postgresql_updated_at_triggers` usa
     PL/pgSQL simple (asignación + `RETURN NEW`) → dentro de lo soportado.
   - Sin particionamiento, sin `SERIALIZABLE` real, sin replicación lógica,
     sin extensiones de terceros.
2. **Free tier de TiDB Starter**: escala a cero tras inactividad (primer
  arranque lento), límites de request units/mes, no es plataforma de
   producción para datos financieros reales.
3. **Soberanía de datos**: TiDB Cloud es un servicio de EE. UU. (PingCAP).
   Evaluar restricciones legales antes de tocar usuarios reales (Rusia/Cuba).
4. **Render Free duerme** tras ~15 min de inactividad y su disco es efímero:
   `SEDERG_PRIVATE_STORAGE_PATH` (KYC, adjuntos) NO persiste. Para el sandbox
   vale; para KYC real hará falta otra opción.
5. No subir secretos a Git ni al bundle del frontend.

## Fase 0 — Prerrequisitos locales

- [ ] Terra ya verificada: `docker compose ps` con Postgres 17 local sano.
- [ ] Cuentas creadas: TiDB Cloud (<https://tidbcloud.com>), Render, Upstash.
- [ ] Datos de migración si hay datos locales que subir:
  `pg_dump -Fc sederg_pay` (opcional para sandbox vacío no se necesita).

## Fase 1 — Base de datos en TiDB Cloud Starter (PostgreSQL-compatible)

1. Crear proyecto y cluster en <https://tidbcloud.com>: tipo **Starter**,
   activar la opción **PostgreSQL compatibility** (preview) en la creación.
2. Copiar la cadena de conexión de la consola (endpoint pgwire,
   `sslmode=require;` obligatorio).
3. Probar conexión desde local:
   ```powershell
   psql "<connection-string>" -c "select version();"
   ```
4. **Ejecutar las migraciones de Yii contra TiDB** (validación del preview):
   ```powershell
   docker compose run --rm -e SEDERG_DB_DSN="<dsn-yii>-sslmode=require" backend `
     php yii migrate --migrationPath=@app/migrations --interactive=0
   ```
   > En Yii, siguiente formato de DSN:
   > `pgsql:host=<host>;port=<port>;dbname=<db>;sslmode=require`
   > ( exigente: si el endpoint pgwire exige TLS en el propio DSN, incluirlo).
5. Smoke check SQL:
   ```powershell
   psql "<connection-string>" -c "\dt"
   ```
   Verificar que existen las tablas del esquema y el trigger `updated_at`:
   `UPDATE` sobre una tabla debe rellenar `updated_at` sin error.

**Puerta de calidad:** si alguna migración falla en TiDB, PARAR y documentar la
incompatibilidad antes de continuar (el fallback es PostgreSQL gestionado real:
Neon o Supabase, que ya se auditaron como compatibles en la conversación).

## Fase 2 — Redis en Upstash

1. Crear base **Upstash Redis** (región europea más cercana a la API).
2. Copiar la URL `rediss://...` (TLS obligatorio).
3. Guardarla para la Fase 3: se inyecta como variable de entorno del backend.
   > El backend actual no referencia Redis en su config web; se usa el stack
   > de Docker local. Para Render inyectar como `SEDERG_REDIS_URL` (ajustar
   > config si hay cache/queue). Si el MVP sandbox no usa Redis todavía, esta
   > fase puede completarse al final.

## Fase 3 — Backend (contenedor PHP) en Render Free

1. En Render → **New → Blueprint / Docker** apuntando al repo (o Push-to-Docker
   con la imagen del `backend` construida del `Dockerfile` de `./backend`).
2. Variables de entorno (sin secretos en Git; usar el panel de Render):
   ```text
   SEDERG_DB_DSN=pgsql:host=<pgwire-host>;port=<port>;dbname=<db>;sslmode=require
   SEDERG_DB_USER=<usuario-tidb>
   SEDERG_DB_PASSWORD=<token-tidb>
   SEDERG_AUTH_MODE=development          # solo sandbox
   SEDERG_COOKIE_KEY=<clave-regenerada>
   SEDERG_DEBUG=0
   SEDERG_PII_KEY=<regenerada>
   SEDERG_ACCOUNT_ENCRYPTION_KEY=<regenerada>
   SEDERG_PRIVATE_STORAGE_PATH=/var/lib/sederg-private
   SEDERG_MAIL_TRANSPORT=smtp            # o log para sandbox
   SEDERG_APP_URL=https://sedergsoft.github.io/SedergPay_oficial/
   SEDERG_CORS_ORIGINS=https://sedergsoft.github.io
   SEDERG_BACKEND_PORT=8080
   ```
3. **ClamAV**: el compose local exige `clamav` healthy. En Render Free no hay
   contenedor sidecar fácil; desacoplar `depends_on` y `SEDERG_CLAMD_HOST`
   para el despliegue cloud (mantener ClamAV en el stack de VM/producción).
4. **rate-updater**: en Render crear un segundo servicio (Cron Job free o
   Background Worker) que ejecute cada 6 h `php yii rates/update` con las
   mismas variables de BD.
5. Ejecutar de nuevo `php yii migrate` contra TiDB **desde el propio Render**
   (shell del servicio) para confirmar conectividad saliente.
6. Verificar: `GET https://<api>.onrender.com/health` → JSON OK.

**Puerta de calidad:** si Render Free bloquea la salida TLS por 4000/pgwire
o el cold start rompe la primera petición, documentarlo y valorar **Koyeb**
(como alternativa free-tier con build dockerfile directo).

## Fase 4 — Frontend en GitHub Pages

1. El workflow de Pages ya existe (F0 de `PLAN_DESPLIEGUE_GRATUITO.md`).
2. Configurar en **GitHub Actions variables**:
   ```text
   VITE_API_BASE_URL=https://<api>.onrender.com
   VITE_AUTH_MODE=development
   ```
3. Ejecutar el workflow de Pages → comprobar la SPA publicada en
   `https://sedergsoft.github.io/SedergPay_oficial/`.
4. En el backend, validar que `SEDERG_CORS_ORIGINS` incluye exactamente ese
   origen (con `https://sedergsoft.github.io` sin barra final).

## Fase 5 — Validación E2E sandbox

- [ ] Registro de cliente y verificación de correo.
- [ ] Alta de beneficiario.
- [ ] Cotización (`POST /api/v1/quotes`).
- [ ] Creación de remesa y depósito sandbox.
- [ ] Confirmación simulada, revisión de cumplimiento, payout simulado,
  reconciliación final → estado `completed`.
- [ ] Reintentos idempotentes ( trigger `updated_at` verificado en TiDB).
- [ ] Aislamiento entre usuarios (umbral A/B).

## Fase 6 — Operación mínima

- [ ] Backup: `pg_dump "<connection-string>"` semanal (TiDB Starter no
  garantiza PITR en free tier).
- [ ] Prueba de restauración en Postgres local 17 (validar reversibilidad).
- [ ] Alertas de cuota en TiDB/Render/Upstash.
- [ ] Logs sin contraseñas, tokens ni datos bancarios.
- [ ] No dejar servicios despiertos por la noche (el sueño por inactividad
  es gratis y deseado).

## Alternativa si el preview de TiDB no aguanta las migraciones

Volver al plan de [PLAN_DESPLIEGUE_GRATUITO.md](./PLAN_DESPLIEGUE_GRATUITO.md)
(PostgreSQL 17 real) o usar **Neon/Supabase Free** (Postgres gestionado
compatibilidad total prometida), solo cambiando el DSN y sin tocar migraciones.

## Criterio de finalización

El frontend de GitHub Pages completa el flujo sandbox de remesa (`completed`)
contra la API HTTPS de Render y la BD TiDB Starter con el trigger `updated_at`
funcionando. No es producción financiera sin los gates de
[PLAN_DE_ACCION.md](./PLAN_DE_ACCION.md).
