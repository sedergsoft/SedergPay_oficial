#!/bin/bash
# =============================================================================
# Test de humo: recorre todos los endpoints GET publicos y comprueba que
# ninguno devuelve 5xx. Sirve para detectar errores SQL silenciosos.
# =============================================================================
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN="47b0c534-7d39-4759-ac4f-73d472b500ba"
DEMO="55676b51-b1a7-40a0-878e-80e82c6285fc"

pass=0; fail=0

check_get() { # check_get <nombre> <ruta> <header_public_id>
  local name="$1" path="$2" who="$3"
  local code body
  body=$(curl -sS -m 25 -w 'HTTP:%{http_code}' -H "X-User-Public-Id: $who" "$BASE$path")
  code=$(printf '%s' "$body" | sed -n 's/.*HTTP:\([0-9]*\)$/\1/p')
  if [ "$code" = "200" ]; then
    echo "PASS  $name (HTTP 200)"
    pass=$((pass+1))
  else
    echo "FAIL  $name (HTTP $code) $(printf '%s' "$body" | sed 's/HTTP:[0-9]*$//' | tr -d '\n' | head -c 200)"
    fail=$((fail+1))
  fi
}

echo "=== Endpoints del cliente ==="
check_get "perfil"            "/api/v1/profile"            "$DEMO"
check_get "kyc"               "/api/v1/kyc"                "$DEMO"
check_get "seguridad"         "/api/v1/account/security"   "$DEMO"
check_get "beneficiarios"     "/api/v1/beneficiaries"      "$DEMO"
check_get "remesas"           "/api/v1/remittances"        "$DEMO"
check_get "metodos-pago-cuba" "/api/v1/funding-methods/CU" "$DEMO"
check_get "metodos-pago-rusia" "/api/v1/funding-methods/RU" "$DEMO"
check_get "tipo-cambio"       "/api/v1/exchange-rates/current" "$DEMO"

echo ""
echo "=== Endpoints del administrador ==="
check_get "admin-panel"       "/api/v1/admin/dashboard"    "$ADMIN"
check_get "admin-operaciones" "/api/v1/admin/operations"   "$ADMIN"
check_get "admin-usuarios"   "/api/v1/admin/users"        "$ADMIN"
check_get "admin-ajustes"     "/api/v1/admin/settings"     "$ADMIN"
check_get "admin-kyc"        "/api/v1/admin/kyc"          "$ADMIN"
check_get "admin-tasas"       "/api/v1/admin/rates/history?base=RUB&quote=CUP" "$ADMIN"
check_get "admin-metodos"     "/api/v1/admin/funding-methods" "$ADMIN"
check_get "admin-cuentas"     "/api/v1/admin/funding-accounts" "$ADMIN"

echo ""
echo "=== Endpoints publicos ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "$BASE/health")
code=$(printf '%s' "$body" | sed -n 's/.*HTTP:\([0-9]*\)$/\1/p')
if [ "$code" = "200" ]; then echo "PASS  health (HTTP 200)"; pass=$((pass+1)); else echo "FAIL  health (HTTP $code)"; fail=$((fail+1)); fi

echo ""
echo "=== RESULTADO: $pass PASS / $fail FAIL ==="
