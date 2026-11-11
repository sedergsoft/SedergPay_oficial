#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — restauracion de un backup PostgreSQL
#
#   bash deploy/restore-postgres.sh --file ~/backups/sederg_2026-11-11.dump
#   bash deploy/restore-postgres.sh --file <dump> --target sederg_restore_test
#
# Por defecto restaura en una base NUEVA (no toca la de produccion) para poder
# probar la restauracion sin riesgo. Para restaurar sobre la base real hay que
# pasar --target sederg_pay y --force.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.production.yml)

FILE=""
TARGET=""
FORCE=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --file) FILE="${2:-}"; shift 2 ;;
    --target) TARGET="${2:-}"; shift 2 ;;
    --force) FORCE=true; shift ;;
    *) echo "Opcion desconocida: $1" >&2; exit 2 ;;
  esac
done

if [[ -z "$FILE" || ! -f "$FILE" ]]; then
  echo "Uso: bash deploy/restore-postgres.sh --file <dump> [--target <bd>] [--force]" >&2
  echo "Dumps disponibles:" >&2
  ls -1t "$HOME/backups" 2>/dev/null | head -10 | sed 's/^/  /' >&2
  exit 1
fi

db_user="$(grep -E '^SEDERG_DB_USER=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
db_user="${db_user:-sederg_app}"
prod_db="$(grep -E '^SEDERG_DB_NAME=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
prod_db="${prod_db:-sederg_pay}"
TARGET="${TARGET:-${prod_db}_restore_test}"

if [[ "$TARGET" == "$prod_db" && "$FORCE" != "true" ]]; then
  echo "ERROR: restaurar sobre '$prod_db' requiere --force." >&2
  exit 1
fi

log() { printf '[restore] %s\n' "$*"; }

log "Base destino: $TARGET (produccion: $prod_db)"
log "Dump: $FILE"

log "Comprobando el dump"
"${COMPOSE[@]}" exec -T postgres pg_restore --list < "$FILE" > /dev/null

log "Preparando la base $TARGET"
"${COMPOSE[@]}" exec -T postgres psql -U "$db_user" -d postgres -v ON_ERROR_STOP=1 \
  -c "DROP DATABASE IF EXISTS \"${TARGET}\" WITH (FORCE);" > /dev/null
"${COMPOSE[@]}" exec -T postgres psql -U "$db_user" -d postgres -v ON_ERROR_STOP=1 \
  -c "CREATE DATABASE \"${TARGET}\" OWNER \"${db_user}\";" > /dev/null

log "Restaurando"
"${COMPOSE[@]}" exec -T postgres pg_restore -U "$db_user" -d "$TARGET" --no-owner --no-acl --exit-on-error < "$FILE"

log "Verificando la restauracion"
"${COMPOSE[@]}" exec -T postgres psql -U "$db_user" -d "$TARGET" -c \
  "select (select count(*) from users) as usuarios, (select count(*) from remittance_quotes) as cotizaciones, (select count(*) from migration) as migraciones;"

log "OK: restauracion completada en $TARGET"
