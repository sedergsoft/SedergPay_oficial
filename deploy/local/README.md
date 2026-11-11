# Despliegue local — SEDERG_PAY

Copia autocontenida del stack para reproducir el despliegue en tu máquina,
sin depender del `docker-compose.yml` de desarrollo (que monta el código como
volumen y arranca ClamAV y Redis).

## Contenido

| Archivo | Para qué sirve |
|---|---|
| `docker-compose.local.yml` | Stack completo: PostgreSQL 17 + API + frontend Nginx |
| `env.local.example` | Plantilla de variables para este stack |
| `composer.json` | **Copia** del `backend/composer.json` (despliegue offline) |
| `composer.lock` | **Copia** del `backend/composer.lock` con versiones exactas |

> Las copias de Composer permiten reconstruir la imagen del backend sin acceso
> al repositorio (por ejemplo al mover el proyecto a un servidor por `scp`).
> **Si cambian las dependencias, vuelve a copiarlas:**
> ```powershell
> Copy-Item backend\composer.json deploy\local\composer.json -Force
> Copy-Item backend\composer.lock deploy\local\composer.lock -Force
> ```

## Arranque

Desde la raíz del repositorio:

```bash
# 1. Variables (la primera vez)
cp deploy/local/env.local.example .env.local
# edita .env.local y define contraseñas/secretos

# 2. Construir y arrancar
docker compose --env-file .env.local -f deploy/local/docker-compose.local.yml up -d --build

# 3. Aplicar el esquema
docker compose --env-file .env.local -f deploy/local/docker-compose.local.yml \
  run --rm backend php yii migrate --interactive=0
```

También puedes reutilizar el `.env` de la raíz (ya contiene los secretos de
desarrollo) pasando `--env-file .env`.

## URLs

| Servicio | URL |
|---|---|
| Frontend | <http://localhost:5173> |
| API | <http://localhost:18080> (con el `.env` del repo, `SEDERG_BACKEND_PORT=8080` → <http://localhost:8080>) |
| Health | `/health` en la URL de la API |
| PostgreSQL | Solo red interna: `docker compose ... exec postgres psql -U sederg_app -d sederg_pay` |

> Los correos de verificación y recuperación **no se envían**: con
> `SEDERG_MAIL_TRANSPORT=log` se escriben en el log del backend. Para leer el
> enlace de verificación:
> ```bash
> docker compose --env-file .env -f deploy/local/docker-compose.local.yml logs backend | grep -i verification
> ```

El frontend publica `nginx.conf`, que ya redirige `/api/` al backend, así que
no hace falta definir `VITE_API_BASE_URL` en modo `development`.

## Verificación

```bash
curl http://localhost:18080/health
curl -X POST http://localhost:18080/api/v1/quotes \
  -H 'Content-Type: application/json' \
  -d '{"source_currency":"USD","target_currency":"CUP","amount":"100"}'
```

## Parar y limpiar

```bash
# Parar sin perder datos
docker compose --env-file .env -f deploy/local/docker-compose.local.yml down

# Parar y borrar la base de datos local
docker compose --env-file .env -f deploy/local/docker-compose.local.yml down -v
```

## Diferencias con producción

- Sin ClamAV (`SEDERG_KYC_REQUIRED=0`): no se pueden probar adjuntos KYC.
- Sin Redis: el backend no lo usa.
- `SEDERG_AUTH_MODE=development`: identidad por cabecera `X-User-Public-Id`.
- El servidor PHP es el embebido de Yii (`php yii serve`), válido solo para
  desarrollo y demos.
