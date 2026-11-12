# Checklist de validación staging/local (tipo staging) — 2026-11-12

Rama: `security/hardening-audit-remediation` (HEAD validado localmente, incluye corrección `2fefe4a`).

## 1. Preparación / aprovisionamiento (ejecutado en local como staging-like)

- [x] Backend reconstruido y desplegado en stack local aislado (`docker compose --env-file .env -f deploy/local/docker-compose.local.yml up -d --build backend`), contenedor saludable.
- [x] Datos de prueba preparados en BD local (solo entorno local): cliente activo/verificado para flujos, beneficiario de prueba, cuenta de fondeo RU/RUB sandbox activa vinculada a `ru_sbp_c2p`, caso KYC de prueba aprobado para habilitar flujo de alto monto.
- [x] **Simulación de provisión OIDC (staging-like, sin IdP externo):** generación de par de claves RS256 y JWT de prueba firmado; contenedor efímero en `SEDERG_AUTH_MODE=production` con `SEDERG_OIDC_ISSUER/AUDIENCE/PUBLIC_KEY` y `oidc_subject` mapeado al usuario admin de prueba.
  - **Resultado:** Bearer válido → `200` en `/api/v1/auth/me` (identidad correcta). Sin token → `403`. `X-User-Public-Id` en modo producción → rechazado (`403`). Validación de firma/claims operativa (fail-closed).
- [ ] **Staging real con IdP externo (pendiente, requiere acción externa):** proveedor OIDC configurado (Authorization Code + PKCE), `issuer/audience/JWKS` reales, mapeo de `oidc_subject` para usuarios reales (incl. admin), frontend productivo y CORS/orígenes finales. Ver `docs/runbooks/oidc-cutover.md`.

## 2. Pruebas funcionales y de seguridad (local)

### Salud, cabeceras y CORS
- [x] `GET /health` → `200` (`{"status":"ok"...}`).
- [x] Cabeceras: `Cache-Control: no-store`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`.
- [x] CORS origen permitido (`http://localhost:5173`) → `Access-Control-Allow-Origin` correcto, `Vary: Origin`, credenciales no permitidas, métodos/headers esperados (incl. `X-User-Public-Id` solo en dev).
- [x] CORS origen no permitido → sin reflejo de `Access-Control-Allow-Origin`.

### Autenticación y autorización (RBAC/negativos)
- [x] Endpoint protegido sin identidad → `403`.
- [x] Dev header válido (admin/cliente) en modo dev permitido → acceso correcto.
- [x] Cliente bloqueado en endpoints admin (`/api/v1/admin/users`, `/api/v1/admin/operations`) → `403`.
- [x] Modo producción (simulado): sin bearer → `403`; bearer OIDC válido/mapeado → `200`; header dev → rechazado.

### Anti-enumeración / respuestas
- [x] Registro con contacto existente → `409` genérico (“Registration could not be completed…”), sin revelar “ya registrado”.
- [x] Login con credenciales inválidas/inexistentes → fallo genérico (`403`), sin oráculo de existencia.

### Aislamiento de datos / IDOR (negativos)
- [x] Cliente no lista beneficiarios de otro usuario (lista propia vacía/aislada).
- [x] Cliente no accede a remesa de admin (`GET /remittances/<id ajeno>`) → `404`.
- [x] Usuario no accede al detalle de remesa de otro usuario → `404/403`.

### Flujos núcleo (funcionales)
- [x] Perfil (lectura/actualización de cliente) → `200`.
- [x] Tasa de cambio (`/api/v1/exchange-rates/current`) → `200`.
- [x] Métodos de fondeo RU y admin de métodos/cuentas (lectura) → `200`, `ru_sbp_c2p` activo y configuración como objeto.
- [x] Beneficiarios (creación y listado propio, cliente) → `201/200`.
- [x] Cotización RU→CU (15,000 RUB, KYC aprobado, dentro de mínimos) → `201`.
- [x] Creación de remesa → `201` (estado `awaiting_payment`).
- [x] **Fondeo C2P sandbox (SBP persona a persona):** `POST /remittances/:id/deposit` (`ru_sbp_c2p`) → `201`, `qr_payload` `sedergpay-sandbox://sbp-c2p?...` con `phone`, `amount`, `currency=RUB`, `purpose=Fondeo%20SEDERG_PAY`, `reference=PAY-*`.
- [x] Detalle de remesa (propietario) → `200`.
- [x] Admin: listado de operaciones y dashboard → `200`.

### KYC y controles de negocio
- [x] Estado KYC accesible para cliente → `200` (`not_started`/según caso).
- [x] Lista admin KYC → `200`.
- [x] Límite diario sin KYC verificado aplicado (cotización > límite diario no verificado → `422` “Daily limit exceeded…”).
- [x] Mínimo de monto aplicado (por debajo del mínimo configurado → `422`).

### Abuso / limitación de tasa
- [x] Throttling operativo en endpoint sensible: tras superar umbral → `429 Too Many Requests` con `Retry-After` y cabeceras seguras. (Defecto de construcción de excepción corregido y revalidado, commit `2fefe4a`).

### SCA (cadena de suministro)
- [x] `composer audit` (backend) → sin vulnerabilidades conocidas.
- [x] `npm audit --omit=dev` (frontend) → 0 vulnerabilidades.

## 3. Hallazgos y acciones durante la validación
- [x] **Corregido:** `RateLimiter` devolvía `500` al exceder límite por construcción incorrecta de `TooManyRequestsHttpException`; ajustado a `429` + `Retry-After` (`2fefe4a`) y revalidado.

## 4. Pendiente para staging real y corte a producción (no ejecutado)

- [ ] Provisionar IdP OIDC y configurar app/SPA (PKCE), issuer/audience y JWKS/clave.
- [ ] Mapear `oidc_subject` de todos los usuarios (y admin) y validar en staging.
- [ ] Desplegar rama en staging representativo, integrar frontend productivo (Bearer, sin header dev) y ajustar `SEDERG_CORS_ORIGINS`/CSP mínimos.
- [ ] Ejecutar suite completa en staging (incl. negativas de firma/issuer/audience/exp, acceso cruzado, flujos con datos representativos, C2P, KYC con almacenamiento/ClamAV según entorno, carga/abuso) y afinar umbrales/CSP.
- [ ] Planificar y aprobar cutover controlado a producción (backup verificado, ventana, responsables, rollback) y solo entonces aplicar Fase 1 en prod; post-corte: retirar dependencias dev-header, auditoría y rotación según política.
- [ ] Completar operativos: protección distribuida/borde, rotación con re-cifrado compatible, backups cifrados offsite/inmutables con pruebas de restauración, hardening runtime/host con regresión, KYC operativo en prod, y pagos reales (externo).

## 5. Conclusión
- Validación local/staging-like: **aprobada** para los controles y flujos probados en este entorno (auth endurecida y OIDC verificable, headers/CORS, RBAC/IDOR, anti-enumeración, throttling, y flujo C2P sandbox end-to-end).
- **No apto aún para declarar Fase 1 cerrada en producción**: requiere OIDC real, mapeos, staging representativo y cutover controlado. Recomendación: proceder con aprovisionamiento OIDC de staging y, tras aprobación de la suite completa, planificar el corte a producción según runbook.
