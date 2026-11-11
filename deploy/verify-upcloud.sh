#!/usr/bin/env bash
# =============================================================================
# SEDERG_PAY — verificación del despliegue en el servidor
#
#   bash deploy/verify-upcloud.sh
#
# Falla (código != 0) solo si el servicio interno no está operativo.
# La comprobación pública es informativa: en dominios gratuitos como
# sslip.io, algunos orígenes (p. ej. los runners de GitHub) pueden ser
# filtrados en el camino y la petición no llega al servidor.
# =============================================================================
set -uo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.production.yml)

domain="$(grep -E '^API_DOMAIN=' .env 2>/dev/null | cut -d= -f2- | tr -d '"')"
if [[ -z "${domain}" ]]; then
  echo "No se encontro API_DOMAIN en .env" >&2
  exit 1
fi

echo "=== 1. Contenedores ==="
"${COMPOSE[@]}" ps

echo ""
echo "=== 2. Salud interna del backend ==="
if "${COMPOSE[@]}" exec -T backend php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") ? 0 : 1);'; then
  echo "backend OK"
else
  echo "ERROR: el backend no responde internamente" >&2
  "${COMPOSE[@]}" logs --tail=40 backend >&2 || true
  exit 1
fi

echo ""
echo "=== 3. Salud publica (https://${domain}/health) ==="
if curl -fsS --connect-timeout 10 --max-time 25 "https://${domain}/health"; then
  echo ""
  echo "API publica OK"
else
  echo ""
  echo "AVISO: la API publica no responde desde el propio servidor." >&2
  echo "       El servicio interno es correcto; revisa el DNS/TLS externo" >&2
  echo "       (los dominios gratuitos tipo sslip.io filtran algunos origenes)." >&2
fi

exit 0
