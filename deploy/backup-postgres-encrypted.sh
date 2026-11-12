#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — backup PostgreSQL cifrado (wrapper)
#
#   bash deploy/backup-postgres-encrypted.sh            -> dump + cifrado
#   bash deploy/backup-postgres-encrypted.sh --keep 30
#
# Cifra el dump generado por deploy/backup-postgres.sh usando, en orden:
#   1) age  (recomendado) si SEDERG_BACKUP_AGE_RECIPIENT o SEDERG_BACKUP_AGE_IDENTITY_FILE está definido
#   2) gpg  si SEDERG_BACKUP_GPG_RECIPIENT está definido
# Si no hay herramienta/clave configurada, deja el dump sin cifrar y avisa.
#
# Para copia offsite/inmutable, suba el artefacto cifrado a su almacenamiento
# externo (Object Storage/S3 con versionado/bloqueo) según su política.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS=7
[[ "${1:-}" == "--keep" ]] && KEEP_DAYS="${2:-7}"

log() { printf '[backup-enc] %s\n' "$*"; }

# 1) Crear dump base (verificado)
bash deploy/backup-postgres.sh --keep "$KEEP_DAYS"

# 2) Localizar el dump más reciente
dump="$(ls -1t "$BACKUP_DIR"/sederg_*.dump 2>/dev/null | head -1 || true)"
if [[ -z "$dump" ]]; then
  log "ERROR: no se encontró ningún dump en $BACKUP_DIR"
  exit 1
fi
log "Dump a proteger: $dump"

# 3) Cifrar si hay backend disponible
if command -v age >/dev/null 2>&1 && { [[ -n "${SEDERG_BACKUP_AGE_RECIPIENT:-}" ]] || [[ -n "${SEDERG_BACKUP_AGE_IDENTITY_FILE:-}" ]]; }; then
  out="${dump}.age"
  if [[ -n "${SEDERG_BACKUP_AGE_RECIPIENT:-}" ]]; then
    age -r "$SEDERG_BACKUP_AGE_RECIPIENT" -o "$out" "$dump"
  else
    # Cifrado simétrico/asimétrico vía identidad no aplica directamente; usar recipient es lo recomendado.
    log "Defina SEDERG_BACKUP_AGE_RECIPIENT (recomendado). Sin recipient, no se cifra con age."
    exit 0
  fi
  rm -f "$dump"
  chmod 600 "$out"
  log "OK: cifrado con age -> $out"
elif command -v gpg >/dev/null 2>&1 && [[ -n "${SEDERG_BACKUP_GPG_RECIPIENT:-}" ]]; then
  out="${dump}.gpg"
  gpg --batch --yes --trust-model always -r "$SEDERG_BACKUP_GPG_RECIPIENT" -o "$out" --encrypt "$dump"
  rm -f "$dump"
  chmod 600 "$out"
  log "OK: cifrado con gpg -> $out"
else
  log "AVISO: dump NO cifrado (falta age/gpg o recipient)."
  log "  Para age: instale age y exporte SEDERG_BACKUP_AGE_RECIPIENT=<recipient>"
  log "  Para gpg: instale gpg y exporte SEDERG_BACKUP_GPG_RECIPIENT=<key-id/email>"
  log "  Proteja el archivo manualmente y copie a almacenamiento offsite/inmutable."
  exit 0
fi

log "Recuerde copiar el artefacto cifrado a almacenamiento offsite y verificar restauración periódicamente."
