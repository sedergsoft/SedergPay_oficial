# SEDERG_PAY

Plataforma de gestión, control y conciliación de remesas Rusia ↔ Cuba.

## Línea base técnica

- Backend: Yii2 sobre PHP 8.3.
- Frontend: React + TypeScript.
- Persistencia: PostgreSQL 17.
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

## Identidad y sistema visual

La interfaz usa un sistema de tokens centralizado en
[`frontend/src/styles.css`](./frontend/src/styles.css): azul principal `#0B4F9C`,
azul tecnológico `#00AEEF`, verde éxito `#00C553`, rojo alerta `#E53935`,
azul noche `#10264D`, gris neutro `#F4F6F9` y blanco `#FFFFFF`. El logotipo
vectorial de globo y flecha está implementado en
[`frontend/src/App.tsx`](./frontend/src/App.tsx) y no depende de emojis ni
imágenes externas.

La interfaz pública y la navegación admiten español y ruso mediante el catálogo
centralizado de [`frontend/src/i18n.ts`](./frontend/src/i18n.ts). La preferencia
se guarda localmente y el diseño mantiene una cuadrícula base de 8 px,
contraste de estados y adaptación mobile-first.

## Módulo de administración

Los administradores pueden abrir **Administración** desde el panel y gestionar
la configuración operativa en `GET/PUT /api/v1/admin/settings`. El módulo
incluye comisión, límites de envío, vigencia de cotizaciones, habilitación de
registros, habilitación de transferencias, límite de beneficiarios y expiración
de enlaces de verificación. Los cambios se validan en backend, se aplican a
nuevas operaciones y se registran en `audit_logs`.

Las credenciales SMTP, OIDC, cifrado, base de datos y otras credenciales de
infraestructura no se exponen en la interfaz; deben permanecer en el gestor de
secretos o variables de entorno del despliegue.

## Primer arranque del backend

Requisitos: PHP 8.3+, Composer, PostgreSQL 17 y Yii2.

1. Copiar `.env.example` a `.env` y sustituir todos los valores de ejemplo.
2. En Windows/PowerShell, inicializar la clave local de cifrado de cuentas antes de arrancar Docker:

   `powershell -ExecutionPolicy Bypass -File .\scripts\Initialize-LocalAccountEncryption.ps1`

   El script genera una clave aleatoria independiente de 32 bytes si falta o conserva la existente para no invalidar cuentas ya cifradas. La clave se guarda únicamente en `.env`; nunca la compartas ni la subas a Git. El servicio backend de Docker exige esta variable y no arranca si falta.
3. Configurar la conexión PostgreSQL mediante variables de entorno; no guardar secretos en el repositorio.
4. Ejecutar la migración incluida:

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

Define `SEDERG_DB_PASSWORD` en `.env` y ejecuta:

```powershell
docker compose up --build
docker compose exec backend php yii migrate --migrationPath=@app/migrations --interactive=0
```

El portal queda en `http://localhost:5173` y la API en
`http://localhost:8080`. El flujo usa autenticación de desarrollo únicamente
para demostración local; no es una configuración válida para producción.

Comprobación rápida del entorno:

```powershell
curl.exe http://localhost:8080/health
docker compose ps
```

La interfaz local usa el proxy de Nginx para enviar `/api/*` al backend. Para
detener los contenedores sin borrar los datos:

```powershell
docker compose down
```

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

### Administración local del sandbox

En el sandbox local, el portal ofrece registro, inicio de sesión, menú de
operaciones y un módulo administrativo. Las cuentas nuevas tienen rol
`customer`. Para promover una cuenta de prueba a administradora, ejecuta el
siguiente comando sustituyendo el correo por el de la cuenta registrada; la
contraseña root se lee desde `.env` y no se guarda en el repositorio:

```powershell
$dbUser = 'sederg_app'
$email = 'admin@example.test'
docker compose exec -T postgres psql -U $dbUser -d sederg_pay -c "UPDATE users SET role='admin' WHERE email='$email';"
```

Después de cerrar sesión y volver a entrar, el usuario verá la opción
**Administración**, que consulta las operaciones globales. Un usuario normal
recibe `403 Forbidden` al intentar acceder a `GET /api/v1/admin/operations`.

El flujo de cliente solicita para cada beneficiario nombre, apellidos, teléfono,
email, país y método de pago. Para **Abono en cuenta bancaria** también solicita
el banco/proveedor y el número completo de cuenta; para **Transferencia a
tarjeta** solicita el número completo de tarjeta. Estos valores se cifran en
el backend y la interfaz solo muestra los últimos cuatro dígitos. Al cotizar se
seleccionan el beneficiario y su método compatible; la remesa se crea usando
esa selección, no el primer beneficiario guardado.

### Verificación de correo

Las cuentas nuevas quedan en estado `pending`. El backend genera un token de
64 caracteres, guarda únicamente su hash y lo envía al correo registrado. La
cuenta no puede iniciar sesión, cotizar ni transferir hasta consumir el token
en `POST /api/v1/auth/verify-email`; el token vence en 30 minutos.

El sistema no tiene fallback a logs ni devuelve el token en la respuesta HTTP.
El correo se entrega como HTML y texto alternativo e incluye un enlace de
activación de un solo uso; configura `SEDERG_APP_URL` con la URL pública del
frontend (usa `http://localhost:5173` solo para desarrollo). Para enviar
correos reales configura SMTP antes de iniciar Docker. Puedes usar
un proveedor gratuito de SMTP, por ejemplo una cuenta con SMTP habilitado en
Brevo, y su contraseña SMTP/API:

```powershell
$env:SEDERG_MAIL_TRANSPORT = 'smtp'
$env:SEDERG_MAIL_HOST = 'smtp-relay.brevo.com'
$env:SEDERG_MAIL_PORT = '587'
$env:SEDERG_MAIL_ENCRYPTION = 'tls'
$env:SEDERG_MAIL_USERNAME = 'tu-login-smtp'
$env:SEDERG_MAIL_PASSWORD = 'tu-clave-smtp'
$env:SEDERG_MAIL_FROM = 'no-reply@tu-dominio.example'
$env:SEDERG_APP_URL = 'https://app.tu-dominio.example'
docker compose up -d --force-recreate backend
```

En un despliegue persistente guarda esas variables únicamente en el secreto
del proveedor/servidor, nunca en Git. Si faltan credenciales, el registro
responde `503` y no crea la cuenta: así nunca se presenta una confirmación
falsa de correo enviado.

### Tasa RUB/CUP

La cotización nunca acepta una tasa enviada por el navegador. El backend obtiene
la tasa RUB/CUP desde la API diaria oficial del Banco Central de Cuba
(`https://api.bc.gob.cu/v1/tasas-de-cambio/activas`), usando el segmento
`tasaEspecial` para población/FGNE, y la guarda con fecha efectiva y fuente.
Si el BCC no responde, consulta como respaldo el XML oficial del Banco de Rusia
(`https://www.cbr.ru/scripts/XML_daily_eng.asp`) y convierte RUB por CUP a CUP por RUB.
El servicio `rate-updater` ejecuta `php yii rates/update` al iniciar y cada
24 horas; además, una cotización actualiza la tasa si todavía no existe para
la fecha oficial disponible.

La tasa efectiva se muestra en el portal antes y después del inicio de sesión.
Un administrador puede definir una tasa manual desde **Administración** o
mediante `POST /api/v1/admin/exchange-rate` con `{ "rate": "0.30" }`.
Mientras exista un override administrativo vigente, se utiliza ese valor;
si no existe, se utiliza la última tasa oficial diaria guardada. El endpoint
público `GET /api/v1/exchange-rates/current` devuelve la tasa efectiva, fuente
y fecha para integraciones y comprobaciones operativas.

### Despliegue gratuito con GitHub Pages y una VM free

El workflow [`deploy.yml`](./.github/workflows/deploy.yml) publica el frontend
en GitHub Pages y puede desplegar el backend en una VM gratuita elegible, como
Oracle Cloud Always Free. GitHub Pages y la VM deben mantenerse dentro de sus
límites gratuitos.

Para activar el backend configura en GitHub:

- Variable `VM_DEPLOY=true`.
- Secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` y `DEPLOY_ENV`.
- Variable `VITE_API_BASE_URL` con la URL HTTPS de la API.
- Variable `VITE_AUTH_MODE=development` solo para sandbox; usa `oidc` en un
  entorno protegido.

`DEPLOY_ENV` debe contener el archivo `.env` privado de la VM, incluyendo
`API_DOMAIN`, `TLS_EMAIL`, `SEDERG_DB_PASSWORD`,
`SEDERG_MYSQL_ROOT_PASSWORD` y `SEDERG_COOKIE_KEY`. El workflow lo escribe
con permisos privados en `~/sederg-pay/.env`, ejecuta las migraciones y
publica el backend mediante Caddy en los puertos 80/443. PostgreSQL y Redis no se
publican en la red externa.

No subas ese archivo al repositorio. Configura `SEDERG_CORS_ORIGINS` con el
origen exacto de GitHub Pages, sin la ruta `/SedergPay_oficial`, por ejemplo
`https://sedergsoft.github.io`.

`VITE_API_BASE_URL` es obligatoria para GitHub Pages. El workflow detiene la
publicación si está vacía para evitar que el navegador envíe peticiones POST al
dominio estático de GitHub y produzca respuestas `405 Method Not Allowed`.

### Alternativa Render Free

El archivo [`render.yaml`](./render.yaml) permite crear el backend como un
servicio Docker gratuito en Render. Render Free duerme tras inactividad y su
disco es efímero, por lo que esta opción es únicamente para el sandbox. No
uses una base MySQL dentro del contenedor: configura `SEDERG_DB_DSN` hacia una
base PostgreSQL persistente y privada, y completa las variables secretas
que Render marca como `sync: false`. El servicio debe quedar en modo
`development` hasta configurar OIDC/MFA.
