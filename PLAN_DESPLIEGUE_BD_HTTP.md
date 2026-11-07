# SEDERG_PAY — Plan de despliegue: PostgreSQL 17 + API sobre HTTP

## 1. Objetivo

Desplegar la base de datos **PostgreSQL 17** de SEDERG_PAY en el entorno local y exponer la aplicación sobre **HTTP** para que el backend y los consumidores puedan usarla. Nota: PostgreSQL no habla HTTP nativamente; el acceso a la BD se hace a través del backend Yii2 expuesto en `http://localhost:8080`, cuyo endpoint `GET /health` valida la conectividad con la base ejecutando `SELECT 1`.

Motor retenido: **PostgreSQL 17** (no TiDB Cloud/MySQL), por decisión del equipo y línea base de `PLAN_DE_ACCION.md#L20`.

## 2. Arquitectura y puertos

| Servicio | Imagen | Puerto local | Acceso |
|---|---|---|---|
| frontend | `./frontend` (Vite/React) | `5173` | HTTP — portal del remitente |
| backend | `./backend` (Yii2/PHP 8.3) | `8080` (`SEDERG_BACKEND_PORT`) | HTTP — API REST; `GET /health` valida la BD |
| postgres | `postgres:17-alpine` | **no expuesto** (`5432` comentado en `docker-compose.yml#L75`) | solo red interna de Docker |
| redis | `redis:7-alpine` | `6379` | red interna + localhost para el backend |
| clamav | `clamav/clamav:stable` | interno | escaneo de archivos adjuntos |

**Recomendación para administración HTTP de la BD:** añadir **pgweb** (interfaz web sobre PostgreSQL) en `http://localhost:8081` solo en sandbox local. Nunca exponer el puerto `5432` de PostgreSQL directamente en redes externas.

## 3. Prerrequisitos

- Docker Desktop / Docker Engine + Docker Compose (v2)
- Git clonado de `D:\proyectos\SEDERG_PAY`
- Puerto `8080` (y `5173`, `6379`) libre en la máquina

## 4. Paso a paso

### Paso 0 — Variables de entorno

El repositorio trae `.env.example`. Copialo y ajusta los secretos:

```powershell
Copy-Item .env.example .env
```

Claves obligatorias en `.env` (el compose falla si faltan):

- `SEDERG_DB_PASSWORD` — contraseña de la app en PostgreSQL
- `SEDERG_COOKIE_KEY` — secreto de 32 bytes para cookies
- `SEDERG_PII_KEY` — secreto de cifrado de PII
- `SEDERG_ACCOUNT_ENCRYPTION_KEY` — genera con `.\scripts\Initialize-LocalAccountEncryption.ps1`
- `SEDERG_REDIS_PASSWORD` — contraseña de Redis

Valores por defecto aceptables en sandbox local:

```ini
SEDERG_DB_DSN=pgsql:host=postgres;port=5432;dbname=sederg_pay
SEDERG_DB_USER=sederg_app
SEDERG_DB_PORT=5432
SEDERG_BACKEND_PORT=8080
SEDERG_AUTH_MODE=development
SEDERG_APP_URL=http://localhost:5173
SEDERG_CORS_ORIGINS=http://localhost:5173
```

**No commitees `.env`.** Es solo para el sandbox local.

### Paso 1 — Levantar solo la base de datos y Redis

Desde la raíz del proyecto:

```powershell
docker compose up -d postgres redis
```

### Paso 2 — Verificar que PostgreSQL esté sano

```powershell
docker compose ps
```

El servicio `postgres` debe aparecer como `healthy`. Comprobación directa de la BD:

```powershell
docker compose exec postgres pg_isready -U sederg_app -d sederg_pay
```

Debe devolver `accepting connections`.

### Paso 3 — Instalar dependencias PHP del backend

```powershell
docker compose run --rm backend composer install
```

### Paso 4 — Ejecutar las migraciones de Yii2 contra PostgreSQL 17

```powershell
docker compose exec backend php yii migrate --interactive=0
```

Esto aplica el esquema inicial y todas las migraciones de `backend/migrations/`, incluido el trigger PL/pgSQL de `updated_at` (`m261106_000015_add_postgresql_updated_at_triggers`), compatible solo con PostgreSQL.

### Paso 5 — Levantar el stack completo (API sobre HTTP)

```powershell
docker compose up --build
```

Esto levanta:

- **Backend API:** `http://localhost:8080`
- **Frontend:** `http://localhost:5173`

### Paso 6 — Verificar la base de datos vía HTTP

```powershell
curl.exe http://localhost:8080/health
```

Respuesta esperada (JSON):

```json
{"status":"ok","service":"sederg-pay-api","environment":"sandbox"}
```

El health controller ejecuta `SELECT 1` contra PostgreSQL, por tanto este endpoint confirma que la base de datos está desplegada y accesible desde la aplicación sobre HTTP.

### Paso 7 — (Opcional) Administración HTTP de la BD

Para gestionar PostgreSQL desde el navegador sin exponer el `5432`, añadir al `docker-compose.yml` un servicio pgweb:

```yaml
  pgweb:
    image: sosedoff/pgweb:latest
    ports: ["8081:8081"]
    environment:
      DATABASE_URL: postgres://sederg_app:${SEDERG_DB_PASSWORD}@postgres:5432/sederg_pay?sslmode=disable
    depends_on:
      postgres: {condition: service_healthy}
```

Luego: `http://localhost:8081` → UI web sobre PostgreSQL. Solo en sandbox local.

## 5. Operación diaria

| Acción | Comando |
|---|---|
| Ver logs del backend | `docker compose logs -f backend` |
| Ver logs de postgres | `docker compose logs -f postgres` |
| Backup de la BD | `docker compose exec postgres pg_dump -U sederg_app sederg_pay > backup.sql` |
| Restaurar backup | `docker compose exec -T postgres psql -U sederg_app -d sederg_pay < backup.sql` |
| Detener sin borrar datos | `docker compose down` (los datos persisten en el volumen `sederg_postgres_data`) |
| Borrar todo (incluidos datos de BD) | `docker compose down -v` — **irrevocable** |
| Reiniciar solo el backend | `docker compose restart backend` |

## 6. Seguridad

- El puerto `5432` de PostgreSQL permanece comentado (`docker-compose.yml#L75`); no se expone públicamente.
- El backend accede a la BD a través de la red interna de Docker.
- Los secretos viven en `.env` (no tracked en Git).
- El MVP es sandbox: sin dinero real, sin bancos productivos, sin datos de clientes reales sin consentimiento y gates legales.

## 7. Checklist de verificación

- [ ] `.env` creado desde `.env.example` con secretos generados
- [ ] `docker compose up -d postgres redis` → `postgres` como `healthy`
- [ ] `pg_isready` responde `accepting connections`
- [ ] `composer install` ejecutado en el contenedor backend
- [ ] `php yii migrate` aplicado sin errores
- [ ] `docker compose up --build` levanta backend en `:8080` y frontend en `:5173`
- [ ] `curl http://localhost:8080/health` → `{"status":"ok", ...}`
- [ ] Datos persisten tras `docker compose down` y `up` (volumen `sederg_postgres_data`)

## 8. Archivos relacionados

- `docker-compose.yml` — definición de servicios (postgres, backend, redis, clamav, frontend)
- `.env.example` — plantilla de variables de entorno
- `backend/config/web.php` — DSN `pgsql:` y ruta `GET health`
- `backend/config/console.php` — conexión de consola a PostgreSQL
- `backend/migrations/` — migraciones Yii2 (PostgreSQL 17)
- `PLAN_DE_ACCION.md#L20` — decisión de línea base: PostgreSQL 17
- `PLAN_DESPLIEGUE_GRATUITO.md` — plan de despliegue sin coste (VM gratuita)