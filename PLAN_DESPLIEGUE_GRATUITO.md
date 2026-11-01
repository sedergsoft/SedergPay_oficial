# SEDERG_PAY — plan de despliegue sin coste

## Objetivo

Publicar el MVP sandbox sin contratar servicios de pago, manteniendo el
frontend, la API y los datos persistentes separados y verificables.

Este plan no habilita dinero real. Los depósitos, payouts y bancos continúan
siendo simulados hasta completar los requisitos legales, regulatorios,
contractuales y de seguridad.

## Arquitectura elegida

```text
GitHub Pages
  └── React/Vite
      https://sedergsoft.github.io/SedergPay_oficial/

VM gratuita (Oracle Cloud Always Free u otra VM free elegible)
  ├── Nginx/Caddy + HTTPS
  ├── API Yii2/PHP 8.3
  ├── MySQL 8.4
  └── Redis

GitHub Actions
  ├── Publica el frontend
  └── Despliega la API por SSH en la VM
```

GitHub Pages aloja únicamente archivos estáticos. La API debe tener una URL
HTTPS independiente, por ejemplo `https://sederg-pay.duckdns.org`. DuckDNS,
Let's Encrypt y Cloudflare Tunnel son alternativas gratuitas; la elección
final depende de si la VM puede recibir tráfico en los puertos 80 y 443.

## Restricciones de coste

- No registrar dominios de pago.
- No crear recursos AWS, Azure u otro proveedor con facturación activa.
- Usar una VM Always Free elegible y configurar alertas de consumo.
- No almacenar secretos en Git ni en el bundle del frontend.
- Mantener MySQL y Redis sin puertos públicos.
- Conservar el MVP en sandbox y sin dinero real.

AWS queda documentado como alternativa, pero no es el proveedor base: la cuenta
actual devuelve `OptInRequired` para EC2 y el Free Tier de AWS no es
permanente.

## Fases de ejecución

### F0 — Código y contrato de despliegue

- [x] Workflow de GitHub Pages.
- [x] Validación que impide publicar si falta `VITE_API_BASE_URL`.
- [x] CORS configurable en el backend.
- [x] Build local de frontend verificado.
- [ ] Configurar `VITE_API_BASE_URL` como variable de Actions después de crear la
  API pública.

### F1 — VM gratuita

- Crear una VM Always Free en una región disponible.
- Crear un usuario SSH sin usar root.
- Instalar Docker Engine y Docker Compose.
- Abrir únicamente SSH restringido y HTTPS.
- Mantener MySQL y Redis en la red interna de Docker.

### F2 — API y persistencia

- Copiar el proyecto a `~/sederg-pay`.
- Crear `.env` directamente en la VM.
- Ejecutar `docker compose up -d --build`.
- Ejecutar migraciones con PHP 8.3.
- Verificar `GET /health`.
- Verificar persistencia después de reiniciar los contenedores.

### F3 — HTTPS y dominio gratuito

- Asignar un subdominio gratuito de DuckDNS, o usar Cloudflare Tunnel.
- Terminar TLS con Caddy/Let's Encrypt o Cloudflare.
- Configurar `SEDERG_CORS_ORIGINS` con la URL exacta de GitHub Pages.
- Configurar `VITE_API_BASE_URL` con la URL HTTPS de la API.

### F4 — CI/CD

- Configurar la variable `VM_DEPLOY=true`.
- Configurar `DEPLOY_HOST` y `DEPLOY_USER` como secrets.
- Configurar `DEPLOY_SSH_KEY` como secret, sin subirlo al repositorio.
- Configurar `DEPLOY_ENV` como secret multilínea con el `.env` privado de la
  VM, incluyendo dominio, correo TLS y secretos de la aplicación.
- Ejecutar el workflow manualmente.
- Confirmar frontend, API y health check.

### F5 — Validación E2E

- Registro de cliente.
- Alta de beneficiario.
- Cotización.
- Creación de remesa.
- Depósito sandbox.
- Revisión de cumplimiento.
- Payout simulado.
- Conciliación final.
- Reintentos idempotentes y aislamiento entre usuarios.

### F6 — Operación mínima

- Backup diario de MySQL.
- Prueba de restauración.
- Logs sin contraseñas, tokens ni datos bancarios sensibles.
- Alertas de disponibilidad y cuota.
- Procedimiento de apagado para evitar consumo inesperado.

## Variables y secretos

### Variables de GitHub Actions

```text
VITE_API_BASE_URL=https://<api-publica-https>
VITE_AUTH_MODE=development
VM_DEPLOY=true
```

`VITE_AUTH_MODE=development` solo es válido para el sandbox. Para un piloto
real se debe usar OIDC/MFA y `VITE_AUTH_MODE=oidc`.

### Secrets de GitHub Actions

```text
DEPLOY_HOST
DEPLOY_USER
DEPLOY_SSH_KEY
DEPLOY_ENV
```

### Variables privadas de la VM

```text
SEDERG_DB_PASSWORD
SEDERG_MYSQL_ROOT_PASSWORD
SEDERG_COOKIE_KEY
SEDERG_AUTH_MODE
SEDERG_OIDC_ISSUER
SEDERG_OIDC_AUDIENCE
SEDERG_OIDC_PUBLIC_KEY
SEDERG_CORS_ORIGINS
API_DOMAIN
TLS_EMAIL
```

## Criterio de finalización

El despliegue estará listo para la demo cuando el frontend de GitHub Pages
pueda completar el flujo sandbox contra una API HTTPS pública y el estado final
de la remesa sea `completed`. No se declarará listo para producción financiera
sin los gates externos indicados en [PLAN_DE_ACCION.md](./PLAN_DE_ACCION.md).
