# SEDERG_PAY

Plataforma de gestión, control y conciliación de remesas Rusia ↔ Cuba.

## Línea base técnica

- Backend: Yii2 sobre PHP 8.3.
- Frontend: React + TypeScript.
- Persistencia: MySQL 8.4 LTS, InnoDB y `utf8mb4`.
- Integraciones: adaptadores independientes y únicamente en sandbox hasta disponer de autorización contractual.
- Seguridad: Keycloak/OIDC, MFA, idempotencia, auditoría y ledger inmutable.

El producto acepta datos reales introducidos por clientes registrados, con consentimiento
y controles de protección de datos. Esta base no habilita pagos reales, custodia de fondos
ni acceso bancario por sí misma.

## Estructura inicial

- `backend/`: migración Yii2 y documentación del modelo físico.
- `backend/docs/openapi.yaml`: contrato REST inicial versionado.
- `backend/src/`: reglas de dominio independientes del framework.
- `backend/tests/Unit/`: pruebas unitarias de estados, ledger y cotizaciones.
- `PLAN_DE_ACCION.md`: fases, entregables, gates y estado actual.
- `frontend/`: portal React/TypeScript del cliente y flujo sandbox.
- Documentos de entrada: especificaciones, arquitectura, DER y decisiones entregados para el proyecto.

## Primer arranque del backend

Requisitos: PHP 8.3+, Composer, MySQL 8.4 y Yii2.

1. Copiar `.env.example` a `.env` y sustituir todos los valores de ejemplo.
2. Configurar la conexión MySQL mediante variables de entorno; no guardar secretos en el repositorio.
3. Ejecutar la migración incluida:

   `php yii migrate --migrationPath=@app/migrations`

La migración crea el esquema inicial, los controles de consentimiento, eventos,
outbox y ledger de doble partida. Antes de usar datos personales en un entorno
compartido deben completarse consentimiento, protección de datos, control de acceso,
retención, backups y respuesta a incidentes. Antes de mover dinero deben completarse
los gates legales, bancarios, de cumplimiento, seguridad, liquidez y operación.

Si el PHP local no es 8.3, el servicio de herramientas usa PHP 8.3 y las
extensiones requeridas:

```powershell
docker compose run --rm backend composer install
docker compose run --rm backend php yii migrate --migrationPath=@app/migrations
```

## Arranque completo del MVP sandbox

Define `SEDERG_DB_PASSWORD` y `SEDERG_MYSQL_ROOT_PASSWORD` en `.env` y ejecuta:

```powershell
docker compose up --build
docker compose exec backend php yii migrate --migrationPath=@app/migrations --interactive=0
```

El portal queda en `http://localhost:5173` y la API en
`http://localhost:8080`. El flujo usa autenticación de desarrollo únicamente
para demostración local; no es una configuración válida para producción.

### Autenticación para piloto

En un entorno no local configura `SEDERG_AUTH_MODE=oidc` y proporciona el issuer,
audience y clave pública RS256 del proveedor OIDC. El backend valida firma,
issuer, audience y expiración, y requiere que el `sub` del token esté
provisionado en `users.oidc_subject`. El header `X-User-Public-Id` solo existe
para el modo `development` y no debe habilitarse en producción.

El frontend solo envía `Authorization: Bearer ...` cuando
`VITE_AUTH_MODE` no es `development`; el token debe ser gestionado por la
integración OIDC del host y almacenarse en `localStorage` como
`sederg_access_token` durante el piloto. No incrustes tokens en el bundle ni
en variables `VITE_*` de una imagen pública.

### Despliegue gratuito con GitHub Pages y una VM free

El workflow [`deploy.yml`](./.github/workflows/deploy.yml) publica el frontend
en GitHub Pages y puede desplegar el backend en una VM gratuita elegible, como
Oracle Cloud Always Free. GitHub Pages y la VM deben mantenerse dentro de sus
límites gratuitos.

Para activar el backend configura en GitHub:

- Variable `VM_DEPLOY=true`.
- Secrets `DEPLOY_HOST`, `DEPLOY_USER` y `DEPLOY_SSH_KEY`.
- Variable `VITE_API_BASE_URL` con la URL HTTPS de la API.
- Variable `VITE_AUTH_MODE=development` solo para sandbox; usa `oidc` en un
  entorno protegido.

La VM debe tener Docker, Compose y un `.env` seguro en
`~/sederg-pay/.env`. No subas ese archivo al repositorio. Configura
`SEDERG_CORS_ORIGINS` con la URL exacta de GitHub Pages.

`VITE_API_BASE_URL` es obligatoria para GitHub Pages. El workflow detiene la
publicación si está vacía para evitar que el navegador envíe peticiones POST al
dominio estático de GitHub y produzca respuestas `405 Method Not Allowed`.
