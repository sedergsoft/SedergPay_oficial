#!/bin/bash
# Pruebas de integracion de endpoints admin contra la API en produccion
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN_PUBLIC_ID="47b0c534-7d39-4759-ac4f-73d472b500ba"
CUSTOMER_PUBLIC_ID="55676b51-b1a7-40a0-878e-80e82c6285fc"
AUTH=(-H "Content-Type: application/json" -H "X-User-Public-Id: $ADMIN_PUBLIC_ID" -H "Idempotency-Key: test-admin-$(date +%s)")

pass=0; fail=0

check() {
  local name="$1" expected="$2" body="$3"
  local code
  code=$(printf '%s' "$body" | sed -n 's/.*HTTP_CODE:\([0-9]*\)$/\1/p')
  local payload
  payload=$(printf '%s' "$body" | sed 's/HTTP_CODE:[0-9]*$//')
  if [ "$code" = "200" ] && printf '%s' "$payload" | grep -q "$expected"; then
    echo "PASS  $name (HTTP $code)"
    pass=$((pass+1))
  else
    echo "FAIL  $name (HTTP $code)"
    echo "      respuesta: $(printf '%s' "$payload" | tr -d '\n' | head -c 300)"
    fail=$((fail+1))
  fi
}

check_status() {
  # check_status <nombre> <http_esperado> <json_esperado> <respuesta>
  local name="$1" want_code="$2" want_json="$3" body="$4"
  local code
  code=$(printf '%s' "$body" | sed -n 's/.*HTTP_CODE:\([0-9]*\)$/\1/p')
  if [ "$code" = "$want_code" ] && printf '%s' "$body" | grep -q "$want_json"; then
    echo "PASS  $name (HTTP $code, $want_json)"
    pass=$((pass+1))
  else
    echo "FAIL  $name (HTTP $code, esperaba $want_code y $want_json)"
    echo "      respuesta: $(printf '%s' "$body" | tr -d '\n' | head -c 300)"
    fail=$((fail+1))
  fi
}

req() {
  curl -sS -m 20 -w 'HTTP_CODE:%{http_code}' "${AUTH[@]}" "$BASE$1" 2>&1
}

echo "=== 1. GET /api/v1/admin/dashboard ==="
check "dashboard" '"total_users"' "$(req /api/v1/admin/dashboard)"

echo "=== 2. GET /api/v1/admin/operations ==="
check "operations" '"reference"' "$(req /api/v1/admin/operations)"

echo "=== 3. GET /api/v1/admin/users ==="
check "users" '"role"' "$(req /api/v1/admin/users)"

echo "=== 4. GET /api/v1/admin/settings ==="
check "settings" '"settings"' "$(req /api/v1/admin/settings)"

echo "=== 5. GET /api/v1/admin/kyc ==="
check "kyc" '"cases"' "$(req /api/v1/admin/kyc)"

echo "=== 6. GET /api/v1/admin/rates/history ==="
check "rates-history" '"rates"' "$(req '/api/v1/admin/rates/history?base=RUB&quote=CUP')"

echo "=== 7. GET /api/v1/admin/funding-methods ==="
check "funding-methods" '"methods"' "$(req /api/v1/admin/funding-methods)"

echo "=== 8. GET /api/v1/admin/funding-accounts ==="
check "funding-accounts" '"accounts"' "$(req /api/v1/admin/funding-accounts)"

echo "=== 9. Toggle de estado sobre un CLIENTE (no admin) ==="
b1=$(curl -sS -m 20 -w 'HTTP_CODE:%{http_code}' -X PUT "${AUTH[@]}" "$BASE/api/v1/admin/users/$CUSTOMER_PUBLIC_ID/toggle")
check_status "user-toggle-suspend" 200 '"status":"suspended"' "$b1"
b2=$(curl -sS -m 20 -w 'HTTP_CODE:%{http_code}' -X PUT "${AUTH[@]}" "$BASE/api/v1/admin/users/$CUSTOMER_PUBLIC_ID/toggle")
check_status "user-toggle-reactivate" 200 '"status":"active"' "$b2"

echo "=== 10. Toggle con usuario no admin debe ser 403 ==="
b3=$(curl -sS -m 20 -w 'HTTP_CODE:%{http_code}' -X PUT -H "Content-Type: application/json" -H "X-User-Public-Id: $CUSTOMER_PUBLIC_ID" "$BASE/api/v1/admin/users/$CUSTOMER_PUBLIC_ID/toggle")
check_status "user-toggle-forbidden" 403 'error\|Forbidden\|403' "$b3"

echo "=== 11. Toggle sobre una cuenta ADMIN debe ser 403 (protegida) ==="
b4=$(curl -sS -m 20 -w 'HTTP_CODE:%{http_code}' -X PUT "${AUTH[@]}" "$BASE/api/v1/admin/users/$ADMIN_PUBLIC_ID/toggle")
check_status "user-toggle-admin-protected" 403 'administrator\|Forbidden\|403' "$b4"

echo ""
echo "=== RESULTADO: $pass PASS / $fail FAIL ==="
