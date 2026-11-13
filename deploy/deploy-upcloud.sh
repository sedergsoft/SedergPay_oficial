#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — despliegue en el servidor UpCloud (manual o desde GitHub Actions)
#
#   bash deploy/deploy-upcloud.sh [--full-rebuild=true|false] [--skip-migrations=true|false]
#
# Es idempotente y devuelve código de salida distinto de 0 si algo falla
# (para que GitHub Actions marque el job en rojo). Si el backend nuevo no
# pasa la comprobación de salud, se restaura automáticamente la imagen
# anterior.
#
# Requisitos: .env presente en el directorio de trabajo y con permisos 600.
# =============================================================================
set -euo pipefail

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.production.yml)
FULL_REBUILD=false
SKIP_MIGRATIONS=false
PROJECT="$(basename "$(pwd)")"
BACKEND_IMAGE="${DEPLOY_BACKEND_IMAGE:-${PROJECT}-backend}"
HEALTH_RETRIES="${DEPLOY_HEALTH_RETRIES:-40}"
HEALTH_INTERVAL="${DEPLOY_HEALTH_INTERVAL:-5}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --full-rebuild=*)  FULL_REBUILD="${1#*=}" ;;
    --skip-migrations=*) SKIP_MIGRATIONS="${1#*=}" ;;
    -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; exit 2 ;;
  esac
  shift
done

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[aviso] %s\033[0m\n' "$*"; }

if [[ ! -f .env ]]; then
  echo "Falta .env. Copia deploy/upcloud.env.example a .env y complétalo (o usa el secret DEPLOY_ENV del workflow)." >&2
  exit 1
fi
chmod 600 .env

log "Validando variables obligatorias"
required=(API_DOMAIN TLS_EMAIL SEDERG_DB_PASSWORD SEDERG_COOKIE_KEY SEDERG_PII_KEY SEDERG_ACCOUNT_ENCRYPTION_KEY)
missing=()
for key in "${required[@]}"; do
  grep -Eq "^${key}=.+" .env || missing+=("${key}")
done
if (( ${#missing[@]} > 0 )); then
  printf 'Variables vacías o ausentes en .env: %s\n' "${missing[*]}" >&2
  exit 1
fi

domain="$(grep -E '^API_DOMAIN=' .env | cut -d= -f2- | tr -d '"')"

log "Actualizando imágenes base (postgres, clamav, caddy)"
"${COMPOSE[@]}" pull postgres clamav caddy || warn "No se pudieron actualizar todas las imágenes base; se sigue con las locales."

log "Guardando la imagen actual para poder revertir"
if docker image inspect "${BACKEND_IMAGE}:latest" >/dev/null 2>&1; then
  docker tag "${BACKEND_IMAGE}:latest" "${BACKEND_IMAGE}:rollback"
  echo "Imagen de rollback: ${BACKEND_IMAGE}:rollback"
else
  warn "No hay imagen previa: este despliegue no podrá revertirse automáticamente."
fi

rollback() {
  echo "" >&2
  echo "=== DESPLIEGUE FALLIDO: revirtiendo a la imagen anterior ===" >&2
  if docker image inspect "${BACKEND_IMAGE}:rollback" >/dev/null 2>&1; then
    docker tag "${BACKEND_IMAGE}:rollback" "${BACKEND_IMAGE}:latest"
    "${COMPOSE[@]}" up -d --no-build --force-recreate backend caddy rate-updater >&2 || true
    sleep 10
    curl -fsS "https://${domain}/health" >&2 || true
    echo "" >&2
  else
    echo "No hay imagen de rollback disponible." >&2
  fi
  "${COMPOSE[@]}" ps >&2 || true
  "${COMPOSE[@]}" logs --tail=60 backend caddy >&2 || true
}
trap rollback ERR

log "Construyendo la imagen del backend"
if [[ "${FULL_REBUILD}" == "true" ]]; then
  "${COMPOSE[@]}" build --no-cache backend
else
  "${COMPOSE[@]}" build backend
fi

log "Levantando infraestructura (PostgreSQL + ClamAV)"
"${COMPOSE[@]}" up -d postgres clamav

if [[ "${SKIP_MIGRATIONS}" != "true" ]]; then
  log "Aplicando migraciones"
  "${COMPOSE[@]}" run --rm backend php yii migrate --migrationPath=@app/migrations --interactive=0
else
  warn "Migraciones omitidas por parámetros del workflow."
fi

log "Arrancando API + Caddy + scheduler de tasas (con espera de salud)"
"${COMPOSE[@]}" up -d --build --wait --wait-timeout 300 backend caddy rate-updater

log "Esperando /health en https://${domain}/health"
for i in $(seq 1 "${HEALTH_RETRIES}"); do
  if curl -fsS "https://${domain}/health" >/dev/null 2>&1; then
    trap - ERR
    echo "OK: https://${domain}/health"
    curl -fsS "https://${domain}/health"; echo
    "${COMPOSE[@]}" ps
    log "Despliegue completado correctamente"
    exit 0
  fi
  sleep "${HEALTH_INTERVAL}"
done

echo "No se pudo verificar https://${domain}/health tras $((HEALTH_RETRIES * HEALTH_INTERVAL)) s." >&2
exit 1
