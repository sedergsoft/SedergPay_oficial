# Runbook: Corte a OIDC (Fase 1) — SEDERG_PAY

## Objetivo
Migrar autenticación de desarrollo (`X-User-Public-Id`) a OIDC/JWT Bearer en `SEDERG_AUTH_MODE=production` sin pérdida de acceso y con rollback definido.

## Prerrequisitos (obligatorios)
- IdP configurado (cliente SPA con Authorization Code + PKCE y audiencia de API). Token JWT RS256 con `iss`, `aud`, `sub`, `exp`.
- Valores conocidos y validados en staging: `SEDERG_OIDC_ISSUER`, `SEDERG_OIDC_AUDIENCE`, y `SEDERG_OIDC_PUBLIC_KEY` (PEM) o `SEDERG_OIDC_JWKS_URI` (recomendado por rotación).
- **Mapeo de identidades:** cada usuario activo/verificado/no borrado (incluido al menos un admin) con `users.oidc_subject = <sub del token>`.
- Frontend construido/desplegado en modo producción (`VITE_AUTH_MODE!=development`) enviando `Authorization: Bearer <token>` y orígenes CORS alineados.
- Backups verificados (BD y `.env`), imagen/commit conocido, ventana de cambio y responsables.

## Inventario y mapeo de `oidc_subject` (previo)
1. Exportar usuarios candidatos:
   ```sql
   SELECT id, public_id, email, role, status, email_verified_at, oidc_subject
   FROM users WHERE deleted_at IS NULL ORDER BY role, email;
   ```
2. Obtener `sub` por usuario desde el IdP/flujo de vinculación controlado (no inferir).
3. Aplicar en staging y validar login; luego en producción dentro de transacción controlada con respaldo:
   ```sql
   UPDATE users SET oidc_subject = :sub WHERE public_id = :public_id AND deleted_at IS NULL;
   ```
4. Verificar admin: `SELECT public_id,email,role FROM users WHERE role='admin' AND oidc_subject IS NOT NULL;`

## Validación en staging (criterio de salida)
- `SEDERG_AUTH_MODE=production`, `SEDERG_ALLOW_DEV_HEADER_AUTH=0`, `SEDERG_EXPOSE_AUTH_STATE=0`, OIDC configurado.
- Sin token o con `X-User-Public-Id` → 401/403. Token inválido/expirado/issuer-audience-firma incorrectos → rechazado.
- Token válido (cliente y admin) con `oidc_subject` vinculado → acceso correcto; sin acceso cruzado entre usuarios.
- CORS solo orígenes permitidos, `/health` OK, sin 5xx, flujos núcleo (perfil, cotización/remesa, fondeo sandbox, admin/KYC) operativos.

## Pasos de corte en producción
1. **Congelar ventana y respaldar:** dump verificado + copia de `.env` y manifiesto de versión.
2. **Actualizar `.env` (solo variables necesarias):**
   - `SEDERG_AUTH_MODE=production`
   - `SEDERG_ALLOW_DEV_HEADER_AUTH=0`
   - `SEDERG_EXPOSE_AUTH_STATE=0`
   - `SEDERG_OIDC_ISSUER`, `SEDERG_OIDC_AUDIENCE`, `SEDERG_OIDC_PUBLIC_KEY` o `SEDERG_OIDC_JWKS_URI`
   - `SEDERG_CORS_ORIGINS` mínimo (orígenes frontend reales)
3. **Redesplegar:**
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.production.yml up -d --build backend caddy
   ```
   (incluir frontend si se sirve en el mismo stack con build productivo).
4. **Verificación inmediata:** `/health`, login OIDC admin/cliente, rechazo de header dev, CORS, operaciones críticas.

## Rollback
- **Si falla autenticación/config y no es corregible de inmediato:** restaurar `.env` previo y redesplegar la imagen/commit anterior. Reabrir modo desarrollo solo como contingencia temporal, mínima y con riesgo aceptado explícitamente, cerrándola al corregir mapeo/OIDC.
- **Si hubo cambios de datos incompatibles:** restaurar desde backup verificado (ver `restore-postgres.sh` / runbook de restauración). Documentar impacto y reintentar en ventana siguiente.

## Post-corte
- Retirar dependencias/reenvío residual de `X-User-Public-Id` en rutas no-dev; auditar accesos/admins.
- Rotar secretos si hubo exposición; monitorear 401/403/5xx y latencia.
- Activar/verificar rotación de claves (JWKS) y mantener runbook actualizado.

> **Nota:** No ejecutar el corte sin IdP operativo, `oidc_subject` mapeado para admin/usuarios y validación en staging aprobada.
