#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — backup PostgreSQL (dump comprimido + verificacion)
#
#   bash deploy/backup-postgres.sh            -> backup diario (rotacion 7 dias)
#   bash deploy/backup-postgres.sh --keep 30  -> otra retencion
#
# Los dumps se guardan en ~/backups y se verifican con pg_restore --list.
# Nunca sube nada a Internet: la copia externa es responsabilidad del
# operador (UpCloud Object Storage, S3, o descarga manual cifrada).
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.production.yml)

BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS=7
[[ "${1:-}" == "--keep" ]] && KEEP_DAYS="${2:-7}"

db_user="$(grep -E '^SEDERG_DB_USER=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
db_user="${db_user:-sederg_app}"
db_name="$(grep -E '^SEDERG_DB_NAME=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
db_name="${db_name:-sederg_pay}"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

stamp="$(date -u +%Y%m%d-%H%M%S)"
target="$BACKUP_DIR/sederg_${db_name}_${stamp}.dump"

log() { printf '[backup] %s\n' "$*"; }

log "Volcando $db_name -> $target"
"${COMPOSE[@]}" exec -T postgres pg_dump -U "$db_user" -d "$db_name" -Fc --no-owner --no-acl > "$target"

size="$(stat -c '%s' "$target")"
if [[ "$size" -lt 1024 ]]; then
  echo "ERROR: el dump es demasiado pequeno ($size bytes); se descarta." >&2
  rm -f "$target"
  exit 1
fi

log "Verificando la integridad del dump"
if ! "${COMPOSE[@]}" exec -T postgres pg_restore --list < "$target" > /dev/null 2>&1; then
  echo "ERROR: el dump no supera la verificacion de pg_restore." >&2
  rm -f "$target"
  exit 1
fi

chmod 600 "$target"
log "OK: $(du -h "$target" | cut -f1) en $target"

log "Rotacion: conservando $KEEP_DAYS dias"
find "$BACKUP_DIR" -maxdepth 1 -name "sederg_${db_name}_*.dump" -type f -mtime "+${KEEP_DAYS}" -print -delete || true

log "Copias disponibles:"
ls -1t "$BACKUP_DIR" | head -5 | sed 's/^/  /'
