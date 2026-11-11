#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — consola psql del PostgreSQL del servidor UpCloud
#
#   bash deploy/db-shell.sh                     -> consola interactiva
#   bash deploy/db-shell.sh -c "select 1"       -> una consulta
#   cat consulta.sql | bash deploy/db-shell.sh   -> fichero SQL por entrada estandar
#
# El acceso es local al servidor: PostgreSQL no esta publicado a Internet.
# Para un cliente grafico (DBeaver, TablePlus) usa un tunel SSH al IP del
# contenedor; ver deploy/UPCLOUD.md.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.production.yml)

db_user="$(grep -E '^SEDERG_DB_USER=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
db_user="${db_user:-sederg_app}"
db_name="$(grep -E '^SEDERG_DB_NAME=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
db_name="${db_name:-sederg_pay}"

if ! "${COMPOSE[@]}" ps --services 2>/dev/null | grep -qx postgres; then
  echo "El servicio postgres no esta definido en este directorio." >&2
  exit 1
fi

echo "Base de datos: $db_name (usuario $db_user) en ${DEPLOY_HOST:-el servidor local}"
exec "${COMPOSE[@]}" exec postgres psql -U "$db_user" -d "$db_name" "$@"
