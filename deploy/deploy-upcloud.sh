#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — despliegue en el servidor (manual o desde GitHub Actions)
#
# Ejecutar EN el servidor, como usuario de despliegue, desde ~/sederg-pay:
#   bash deploy/deploy-upcloud.sh
#
# Requisitos: .env presente y con permisos 600.
# =============================================================================
set -euo pipefail

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.production.yml)

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

if [[ ! -f .env ]]; then
  echo "Falta .env. Copia deploy/upcloud.env.example a .env y complétalo." >&2
  exit 1
fi

if [[ "$(stat -c '%a' .env)" != "600" ]]; then
  echo "Aviso: .env no tiene permisos 600. Corrigiendo..."
  chmod 600 .env
fi

log "Validando variables obligatorias"
required=(API_DOMAIN TLS_EMAIL SEDERG_DB_PASSWORD SEDERG_COOKIE_KEY SEDERG_PII_KEY SEDERG_ACCOUNT_ENCRYPTION_KEY)
missing=()
for key in "${required[@]}"; do
  if ! grep -Eq "^${key}=.+" .env; then
    missing+=("${key}")
  fi
done
if (( ${#missing[@]} > 0 )); then
  printf 'Variables vacías o ausentes en .env: %s\n' "${missing[*]}" >&2
  exit 1
fi

log "Construyendo y arrancando infraestructura (PostgreSQL + ClamAV)"
"${COMPOSE[@]}" up -d --build postgres clamav

log "Aplicando migraciones"
"${COMPOSE[@]}" run --rm backend php yii migrate --migrationPath=@app/migrations --interactive=0

log "Construyendo y arrancando API + Caddy"
"${COMPOSE[@]}" up -d --build backend caddy

log "Esperando /health"
domain="$(grep -E '^API_DOMAIN=' .env | cut -d= -f2- | tr -d '"')"
for i in $(seq 1 30); do
  if curl -fsS "https://${domain}/health" >/dev/null 2>&1; then
    echo "OK: https://${domain}/health"
    curl -fsS "https://${domain}/health"; echo
    "${COMPOSE[@]}" ps
    exit 0
  fi
  sleep 5
done

echo "No se pudo verificar https://${domain}/health tras 150 s." >&2
"${COMPOSE[@]}" ps >&2 || true
"${COMPOSE[@]}" logs --tail=80 caddy backend >&2 || true
exit 1
