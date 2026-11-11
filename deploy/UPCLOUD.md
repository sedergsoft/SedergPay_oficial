# Runbook — Backend SEDERG_PAY en UpCloud

> **Alcance:** desplegar la API Yii2/PHP 8.3 en un Cloud Server de UpCloud con
> HTTPS automático, PostgreSQL 17, ClamAV y despliegue continuo desde GitHub.
> El **frontend sigue en GitHub Pages**; el dominio de UpCloud publica
> únicamente la API. El puntero del frontend a la API se cambia con la
> variable de repositorio `VITE_API_BASE_URL` (§7.3).
>
> **Estado:** **desplegado y verificado el 2026-11-11.**
>
> | | |
> |---|---|
> | App + API | <https://5-22-219-30.sslip.io> |
> | Health | <https://5-22-219-30.sslip.io/health> |
> | Servidor | UpCloud `es-mad1` (Madrid) · `STARTER-1xCPU-4GB` · IP `5.22.219.30` |
> | SSH | `ssh deploy@5.22.219.30` |
>
> Nota: en cuentas *trial* UpCloud bloquea Frankfurt y Helsinki
> (`ZONE_FORBIDDEN_IN_TRIAL`); el servidor quedó en Madrid.

---

## 1. Arquitectura objetivo

```text
GitHub Pages (frontend React)
  https://sedergsoft.github.io/SedergPay_oficial/
            │  fetch + CORS
            ▼
UpCloud Cloud Server (Ubuntu 24.04)
  └── Caddy :443  ── Let's Encrypt automático
        └── backend :8080 (PHP 8.3, Yii2)
              ├── postgres :5432   (red interna Docker)
              ├── clamav   :3310   (red interna Docker)
              └── volumen sederg-private (adjuntos KYC cifrados)

GitHub Actions ──SSH──> docker compose up -d --build + yii migrate
```

Puertos publicados: **22** (restringido a tu IP), **80** y **443**. PostgreSQL,
ClamAV y el backend **no** se publican a Internet.

---

## 2. Dimensionamiento y coste

| Plan | RAM / vCPU / disco | Precio | Uso recomendado |
|---|---|---|---|
| Starter | 1 GB / 1 / 10 GB | 3 €/mes | No recomendado (ClamAV no cabe) |
| Starter | 2 GB / 1 / 20 GB | **6 €/mes** | Demo sandbox con swap |
| Starter | 2 GB / 2 / 30 GB | **8 €/mes** | Demo con holgura |
| Starter | 4 GB / 2 / 30 GB | **12 €/mes** | Recomendado para piloto |

- ClamAV consume ~1–1,5 GB de RAM al cargar firmas: con 2 GB hay que añadir swap.
- Backups automáticos: +10 % del plan (muy recomendable).
- Egreso: 0 €/GB. Facturación por hora; Starter se factura como máximo 28 días/mes.
- Prueba: 30 días con 500 € de crédito.
- Base de datos gestionada (opcional, piloto): PostgreSQL 1 GB desde 9 €/mes con
  backups y PITR.

**Atajo para sandbox:** ya existe una base TiDB Cloud Starter migrada
(workflow *Run database migrations (TiDB Cloud)*, run `42813171320`, `success`).
Puedes desplegar solo el backend + Caddy y apuntar `SEDERG_DB_DSN` al endpoint
pgwire de TiDB. Así el servidor de 2 GB (6 €/mes) es suficiente.

---

## 3. Crear el servidor

En <https://hub.upcloud.com>:

1. **Deploy server**.
2. Zona: la más cercana a tus usuarios (Frankfurt o Madrid para Europa).
3. Plan: Starter **2 GB / 2 vCPU** (mínimo para sandbox) o **4 GB** (piloto).
4. SO: **Ubuntu 24.04 LTS**.
5. SSH: añade tu clave pública (`~/.ssh/id_ed25519.pub`). No uses contraseña.
6. Opcional: etiqueta `sederg-pay` y activa *Simple Backups*.
7. Anota la **IP pública**.

### Firewall

Docker publica puertos saltándose `ufw`, así que el filtrado fiable es el
**firewall de UpCloud** (Hub → Server → Firewall):

| Puerto | Origen | Motivo |
|---|---|---|
| 22/tcp | tu IP /32 | administración |
| 80/tcp | 0.0.0.0/0 | validación ACME y redirección a 443 |
| 443/tcp | 0.0.0.0/0 | API |

Todo lo demás, cerrado.

---

## 4. Bootstrap del servidor

Desde tu máquina:

```bash
scp deploy/bootstrap-upcloud.sh root@IP_PUBLICA:/root/
ssh root@IP_PUBLICA 'bash /root/bootstrap-upcloud.sh'
```

El script (idempotente) hace:

- `apt update && apt upgrade`
- creación del usuario `deploy` con tu misma clave SSH y `sudo` sin contraseña
- instalación de Docker Engine + Compose plugin
- `ufw` como segunda barrera (22 restringido, 80/443 abiertos)
- `unattended-upgrades` para parches de seguridad
- creación de `~/sederg-pay`

Después, **cierra la sesión de root** y trabaja siempre como `deploy`:

```bash
ssh deploy@IP_PUBLICA
```

---

## 5. DNS gratuito y TLS

### Opción C — sslip.io (usada en este despliegue, sin configurar nada)

`sslip.io` resuelve cualquier IP pública como subdominio, así que
`5-22-219-30.sslip.io` apunta a `5.22.219.30` sin registrar ningún dominio.
Caddy obtiene el certificado (ZeroSSL) solo, porque el DNS ya resuelve y los
puertos 80/443 están abiertos.

```bash
dig +short 5-22-219-30.sslip.io   # -> 5.22.219.30
```

Ventaja: cero mantenimiento y cero coste. Inconveniente: la URL cambia si
cambia la IP del servidor y el dominio no es "de marca" para un piloto
comercial.

> ⚠️ **Limitación medida (2026-11-11):** los *runners* de GitHub Actions (IP de
> Azure) **no consiguen completar el TLS** contra `*.sslip.io`
> (`tlsv1 alert internal error`): el tráfico se corta antes de llegar a Caddy.
> Los navegadores de los usuarios y el propio servidor sí acceden con normalidad.
> Por eso el workflow verifica la API **desde el servidor** y no desde el runner.
> Para una URL estable y sin estas limitaciones, usa **DuckDNS** o un dominio
> propio (opciones A y B).

### Opción A — DuckDNS (recomendada para demo)

1. Crea `sederg-pay.duckdns.org` en <https://www.duckdns.org> y copia el token.
2. Apunta el dominio a la IP del servidor:

```bash
curl "https://www.duckdns.org/update?domains=sederg-pay&token=TU_TOKEN&ip=IP_PUBLICA"
# debe responder: OK
```

3. Automatiza la renovación de IP (por si reinicias el servidor):

```bash
sudo tee /etc/cron.d/duckdns >/dev/null <<'EOF'
*/5 * * * * root curl -fsS "https://www.duckdns.org/update?domains=sederg-pay&token=TU_TOKEN&ip=" >/dev/null
EOF
```

### Opción B — dominio propio

Crea un registro `A` `api.tudominio.com → IP_PUBLICA` en tu proveedor DNS y
espera la propagación (`dig +short api.tudominio.com`).

Caddy obtiene y renueva el certificado solo, siempre que 80 y 443 estén abiertos
y el DNS ya resuelva.

---

## 6. Variables y secretos

```bash
cd ~/sederg-pay
cp deploy/upcloud.env.example .env
chmod 600 .env
nano .env
```

Genera los secretos **en el servidor** (no reutilices los de desarrollo):

```bash
echo "SEDERG_COOKIE_KEY=$(openssl rand -hex 32)"
echo "SEDERG_PII_KEY=$(openssl rand -hex 32)"
echo "SEDERG_ACCOUNT_ENCRYPTION_KEY=$(openssl rand -base64 32)"
echo "SEDERG_DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=')"
```

Valores mínimos que debes revisar:

| Variable | Valor |
|---|---|
| `API_DOMAIN` | `5-22-219-30.sslip.io` (o tu dominio propio) |
| `TLS_EMAIL` | tu correo real (Let's Encrypt avisa aquí) |
| `SEDERG_DB_DSN` | `pgsql:host=postgres;port=5432;dbname=sederg_pay` (o pgwire de TiDB con `sslmode=require`) |
| `SEDERG_CORS_ORIGINS` | `https://5-22-219-30.sslip.io,https://sedergsoft.github.io` |
| `SEDERG_APP_URL` | `https://5-22-219-30.sslip.io/` (origen del enlace de verificación) |
| `SEDERG_MAIL_TRANSPORT` | `api` en la nube (ver §8.1), `smtp` en local |
| `SEDERG_MAIL_API_KEY` | Clave de API de Brevo (`xkeysib-…`), **no** la clave SMTP |
| `SEDERG_AUTH_MODE` | `development` (sandbox) / `production` (piloto) |
| `SEDERG_KYC_REQUIRED` | `0` (sandbox) / `1` (piloto, exige ClamAV) |

> `SEDERG_AUTH_MODE=development` habilita `X-User-Public-Id`. Es aceptable para
> una demo sandbox y **nunca** para producción financiera: allí se usa OIDC.

---

## 7. Despliegue

### 7.1 Primer despliegue manual

```bash
cd ~/sederg-pay

# 1. Dependencias de infraestructura
docker compose -f docker-compose.yml -f docker-compose.production.yml up -d --build postgres clamav

# 2. Esquema
docker compose -f docker-compose.yml -f docker-compose.production.yml \
  run --rm backend php yii migrate --migrationPath=@app/migrations --interactive=0

# 3. API + frontend + TLS
docker compose -f docker-compose.yml -f docker-compose.production.yml up -d --build backend frontend caddy

# 4. Estado
docker compose -f docker-compose.yml -f docker-compose.production.yml ps
docker compose -f docker-compose.yml -f docker-compose.production.yml logs -f caddy
```

Espera a que Caddy emita el certificado (30–90 s la primera vez).

### 7.2 Verificación

```bash
curl -fsS https://sederg-pay.duckdns.org/health
# {"status":"ok","service":"sederg-pay-api","environment":"sandbox"}

curl -fsS -X POST https://sederg-pay.duckdns.org/api/v1/quotes \
  -H 'Content-Type: application/json' \
  -d '{"source_currency":"USD","target_currency":"CUP","amount":"100"}'
```

Prueba también desde el navegador la consola del frontend publicado:

```js
fetch('https://sederg-pay.duckdns.org/health').then(r => r.json()).then(console.log)
```

Si responde, CORS y TLS están correctos.

### 7.3 Despliegue continuo

El workflow `.github/workflows/deploy.yml` ya está escrito para este escenario.
En GitHub → *Settings → Secrets and variables → Actions*:

**Variables** (ya configuradas en el repositorio el 2026-11-11)

```text
VITE_API_BASE_URL=https://5-22-219-30.sslip.io
VITE_AUTH_MODE=development
VITE_UI_PREVIEW=false
```

**Secrets**

```text
DEPLOY_HOST=IP_PUBLICA
DEPLOY_USER=deploy
DEPLOY_SSH_KEY=<contenido de la clave privada ed25519>
DEPLOY_ENV=<contenido completo del .env del servidor>
```

Borra `VITE_UI_PREVIEW` y relanza *Deploy frontend to GitHub Pages*.

> `DEPLOY_ENV` es un secret multilínea: pégalo tal cual, sin comillas.
> Cada push a `main` que toque `backend/**` redespliega automáticamente.

---

## 8. Backups

### Opción A — Simple Backups de UpCloud (+10 %)

Cubre la VM completa, incluidos los volúmenes Docker.

### Opción B — dump lógico diario

```bash
mkdir -p ~/backups
sudo tee /etc/cron.d/sederg-backup >/dev/null <<'EOF'
15 3 * * * deploy cd $HOME/sederg-pay && docker compose -f docker-compose.yml -f docker-compose.production.yml exec -T postgres pg_dump -U sederg_app -Fc sederg_pay > $HOME/backups/sederg_$(date +\%F).dump
EOF
```

Añade rotación (7 días) y copia fuera del servidor (UpCloud Object Storage desde
5 €/mes, o descarga cifrada con `gpg`).

**Prueba la restauración** antes de considerarla válida:

```bash
docker compose ... exec -T postgres pg_restore -U sederg_app -d sederg_pay_nuevo --clean < backup.dump
```

---

## 8.1 Correo: por qué la nube usa API y no SMTP

Diagnóstico ejecutado en el servidor (2026-11-11):

```text
smtp-relay.brevo.com:587  → sin respuesta
smtp-relay.brevo.com:2525 → sin respuesta
smtp-relay.brevo.com:25   → sin respuesta
smtp.gmail.com:587        → sin respuesta
api.github.com:443        → ABIERTO
```

UpCloud **bloquea todo el tráfico SMTP saliente** en las cuentas *trial* (25, 465, 587 y 2525),
mientras que HTTPS funciona con normalidad. Por eso los correos de verificación y de
notificación no llegaban: el despliegue estaba además en `SEDERG_MAIL_TRANSPORT=log`.

### Solución implementada

`VerificationMailer` admite tres transportes:

| Transporte | Cómo funciona | Cuándo usarlo |
|---|---|---|
| `smtp` | Conexión SMTP directa con `STARTTLS` (o `ssl`/`none`) | Entornos con salida SMTP libre (local, redes propias) |
| `api` | `POST` JSON sobre HTTPS a `https://api.brevo.com/v3/smtp/email` | **Nube (UpCloud trial)**: funciona por 443 |
| `log` | No envía nada; el enlace de verificación queda en el log del backend | Sandbox local sin credenciales |

Configuración para la nube:

```bash
SEDERG_MAIL_TRANSPORT=api
SEDERG_MAIL_API_URL=https://api.brevo.com/v3/smtp/email
SEDERG_MAIL_API_KEY=xkeysib-...      # clave de API de Brevo (NO la clave SMTP xsmtpsib-)
SEDERG_MAIL_API_KEY_HEADER=api-key
SEDERG_MAIL_FROM=remitente@tu-dominio
SEDERG_MAIL_FROM_NAME=SEDERG_PAY
```

> La clave SMTP de Brevo (`xsmtpsib-…`) **no** sirve para la API: devuelve
> `401 {"message":"Key not found"}`. Hay que crear una *API key* en
> *Brevo → SMTP & API → API Keys*.

### Comprobación

```bash
docker compose -f docker-compose.yml -f docker-compose.production.yml exec -T backend php -r '
require "/app/vendor/autoload.php";
$m = new app\Infrastructure\Identity\VerificationMailer();
var_dump($m->send(getenv("SEDERG_MAIL_FROM"), "Prueba", "token-de-prueba", 30));'
```

### Si UpCloud habilita el SMTP

Si en el futuro UpCloud abre el egress SMTP (suele ocurrir al verificar la cuenta),
basta con cambiar `SEDERG_MAIL_TRANSPORT=smtp` y rellenar `SEDERG_MAIL_USERNAME`,
`SEDERG_MAIL_PASSWORD`, `SEDERG_MAIL_HOST`, `SEDERG_MAIL_PORT` y
`SEDERG_MAIL_ENCRYPTION`.

---

## 9. Operación

| Tarea | Comando |
|---|---|
| Ver logs | `docker compose ... logs -f backend` |
| Reiniciar API | `docker compose ... restart backend` |
| Estado | `docker compose ... ps` |
| Consumo | `docker stats --no-stream` |
| Actualizar | `git pull` + `docker compose ... up -d --build backend caddy` |
| Rollback | `git checkout <commit>` + `docker compose ... up -d --build backend` |

### Actualizaciones de seguridad del SO

```bash
sudo apt update && sudo apt upgrade -y
sudo reboot   # si el kernel lo requiere
```

Docker Desktop no aplica: aquí es Docker Engine nativo.

---

## 10. Limitaciones conocidas a resolver antes de dinero real

1. **`php yii serve` es el servidor embebido de PHP**: monohilo, sin pool de
   workers. Sirve para la demo; para un piloto hay que migrar a `php-fpm` +
   Nginx/Caddy FastCGI.
2. **Sin rate limiting de login** en la aplicación: añadir límite en Caddy o en
   el balanceador.
3. **Secreto TOTP sin cifrar** en base de datos.
4. **Sin integraciones bancarias reales**: depósitos y payouts son simulados.
5. **`SEDERG_AUTH_MODE=development`** no es autenticación real; el piloto exige
   OIDC/MFA.
6. **TiDB Cloud Starter está en Public Preview**: para producción, PostgreSQL
   gestionado de UpCloud (9 €/mes) o PostgreSQL propio con backups probados.

---

## 11. Troubleshooting

| Síntoma | Causa probable | Solución |
|---|---|---|
| Caddy no emite certificado | DNS sin propagar o 80/443 cerrados | `dig +short API_DOMAIN`; revisar firewall UpCloud |
| `502 Bad Gateway` | backend no sano | `docker compose ... logs backend` |
| `/health` da timeout a la BD | egress bloqueado o DSN mal formado | probar `sslmode=require`, revisar credenciales |
| Migraciones fallan | usuario sin permisos o esquema previo | `yii migrate/history`, revisar `SEDERG_DB_USER` |
| OOM al arrancar ClamAV | RAM insuficiente | plan de 4 GB o swap de 2 GB |
| `no space left on device` | imágenes y volúmenes | `docker system prune -af --volumes` (¡ojo con datos!) |
| Frontend con error CORS | origen distinto al declarado | `SEDERG_CORS_ORIGINS` debe ser el origen exacto, sin `/` final |

---

## 12. Automatización opcional vía API de UpCloud

Si prefieres provisión como código, UpCloud expone una API REST
(`https://developers.upcloud.com/`) y permite crear el servidor con un token
de subcuenta. Exige:

- una cuenta con método de pago verificado,
- un token de API con permisos de creación,
- decidir zona, plan y clave SSH.

Con esas tres cosas, la creación del servidor es un `POST /1.3/server` y el
resto de este runbook se ejecuta igual. **No subas el token a Git.**

> Notas del despliegue real (2026-11-11): en una cuenta *trial* solo funcionan
> algunas zonas (Madrid sí; Frankfurt y Helsinki devuelven
> `ZONE_FORBIDDEN_IN_TRIAL`), y las plantillas cloud-init exigen
> `metadata: "yes"`. Las claves SSH se registran en
> `login_user.ssh_keys.ssh_key` (no en la raíz del servidor). El servidor
> creado quedó con `uuid 000ef598-948c-4593-9781-21b5e9b5a34f`.

---

## 13. Checklist de aceptación

Estado verificado el **2026-11-11** sobre el despliegue real:

- [x] `https://5-22-219-30.sslip.io/health` responde 200 con `environment: sandbox`
- [x] `POST /api/v1/quotes` devuelve una cotización válida
      (20 000 RUB → 195 531,00 CUP · comisión 600 RUB · tasa 9,77655 del BCC)
- [x] Registro + verificación de email + login funcionan contra la API pública
- [x] El frontend de GitHub Pages consume la API pública
      (`VITE_API_BASE_URL=https://5-22-219-30.sslip.io`, variable del repo)
- [x] CORS responde `Access-Control-Allow-Origin` para los orígenes declarados
- [x] Las 16 migraciones están aplicadas (`yii migrate/history`)
- [x] Puertos 5432, 3310 y 8080 **no** accesibles desde Internet (sin
      `listener` en el host y `ufw` con `deny incoming`)
- [x] Certificado TLS válido (`CN=5-22-219-30.sslip.io`, ZeroSSL, caduca el
      2027-02-09) y redirección 80 → 443 automática
- [x] Secretos solo en `.env` (permisos 600) y en GitHub Secrets
- [ ] Backup ejecutado y **restauración probada**
- [ ] `SEDERG_AUTH_MODE=production` + OIDC configurado (hoy es sandbox)
- [ ] Rate limiting de login en Caddy

---

## 14. Registro del despliegue y operación diaria

### Estado de los contenedores

```
sederg-pay-postgres-1   postgres:17-alpine   healthy   (sin puertos publicados)
sederg-pay-clamav-1     clamav/clamav:stable healthy   (sin puertos publicados)
sederg-pay-backend-1    sederg-pay-backend   up        (sin puertos publicados)
sederg-pay-caddy-1      caddy:2-alpine       up        80/tcp, 443/tcp
```

Consumo real medido: ClamAV ~950 MB, PostgreSQL ~37 MB, backend ~23 MB,
Caddy ~13 MB (límite 3,8 GB).

> El frontend **no** se despliega aquí: vive en GitHub Pages y consume la API
> por `VITE_API_BASE_URL=https://5-22-219-30.sslip.io`. UpCloud publica solo
> la API: `Caddy → backend:8080` para todas las rutas.

### Comandos habituales

```bash
ssh deploy@5.22.219.30
cd ~/sederg-pay

docker compose -f docker-compose.yml -f docker-compose.production.yml ps
docker compose -f docker-compose.yml -f docker-compose.production.yml logs -f backend
bash deploy/deploy-upcloud.sh          # redespliegue idempotente + verificación
sudo systemctl restart docker          # si Docker queda colgado
```

### Copia del `.env` para GitHub Actions (secret `DEPLOY_ENV`)

El archivo vive solo en el servidor. Para copiarlo al secret del workflow:

```bash
ssh deploy@5.22.219.30 'cat ~/sederg-pay/.env'
```

Pégalo tal cual (multilínea) en *Settings → Secrets and variables → Actions →
Secrets → DEPLOY_ENV*.

### Avisos de seguridad

1. **Rota el token de la API de UpCloud.** Se usó en texto plano en el
   chat durante la provisión: revócalo en *Hub → Account → API tokens* y crea
   otro con permisos mínimos.
2. **El servidor está en modo sandbox**: `SEDERG_AUTH_MODE=development` permite
   actuar como cualquier usuario conocidas su `public_id`; `SEDERG_KYC_REQUIRED=0`
   desactiva el escaneo obligatorio; los correos no se envían (van al log).
   Es válido para una demo, **no** para operar dinero real.
3. **SSH abierto a Internet** (puerto 22) con autenticación por clave. Restringe
   el origen en el firewall de UpCloud o en `ufw` cuando tengas la IP fija.
4. **El plan STARTER-1xCPU-4GB no tiene backups incluidos**: activa *Simple
   Backups* en el Hub o programa el `pg_dump` diario de §8.
