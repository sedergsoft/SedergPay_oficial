#!/bin/bash
# Pruebas de integracion de endpoints admin contra la API en produccion
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN_PUBLIC_ID="47b0c534-7d39-4759-ac4f-73d472b500ba"
AUTH=(-H "Content-Type: application/json" -H "X-User-Public-Id: $ADMIN_PUBLIC_ID" -H "Idempotency-Key: test-admin-$(date +%s)")

pass=0; fail=0
check() {
  local name="$1" expected="$2" body="$3"
  local status
  status=$(echo "$body" | head -c 2000)
  local code
  code=$(echo "$body" | grep -o '"status":[0-9]*' | head -1)
  if echo "$body" | grep -q "$expected"; then
    echo "PASS  $name"
    pass=$((pass+1))
  else
    echo "FAIL  $name"
    echo "      respuesta: $(echo "$body" | tr -d '\n' | head -c 400)"
    fail=$((fail+1))
  fi
}

req() {
  local path="$1"
  curl -sS -m 20 -w '\nHTTP_CODE:%{http_code}' "${AUTH[@]}" "$BASE$path" 2>&1
}

echo "=== 1. GET /api/v1/admin/dashboard ==="
body=$(req /api/v1/admin/dashboard)
check "dashboard" '"total_users"' "$body"

echo "=== 2. GET /api/v1/admin/operations ==="
body=$(req /api/v1/admin/operations)
check "operations" '"reference"' "$body"

echo "=== 3. GET /api/v1/admin/users ==="
body=$(req /api/v1/admin/users)
check "users" '"role"' "$body"

echo "=== 4. GET /api/v1/admin/settings ==="
body=$(req /api/v1/admin/settings)
check "settings" '"settings"' "$body"

echo "=== 5. GET /api/v1/admin/kyc ==="
body=$(req /api/v1/admin/kyc)
check "kyc" '"cases"' "$body"

echo "=== 6. GET /api/v1/admin/rates/history ==="
body=$(req '/api/v1/admin/rates/history?base=RUB&quote=CUP')
check "rates-history" '"rates"' "$body"

echo "=== 7. GET /api/v1/admin/funding-methods ==="
body=$(req /api/v1/admin/funding-methods)
check "funding-methods" '"methods"' "$body"

echo "=== 8. GET /api/v1/admin/funding-accounts ==="
body=$(req /api/v1/admin/funding-accounts)
check "funding-accounts" '"accounts"' "$body"

echo "=== 9. PUT /api/v1/admin/users/<id>/toggle (y reversa) ==="
body=$(curl -sS -m 20 -w '\nHTTP_CODE:%{http_code}' -X PUT "${AUTH[@]}" "$BASE/api/v1/admin/users/$ADMIN_PUBLIC_ID/toggle")
check "user-toggle" '"status"' "$body"
# reversa para dejar el admin activo
body=$(curl -sS -m 20 -w '\nHTTP_CODE:%{http_code}' -X PUT "${AUTH[@]}" "$BASE/api/v1/admin/users/$ADMIN_PUBLIC_ID/toggle")
check "user-toggle-revert" '"status"' "$body"

echo ""
echo "=== RESULTADO: $pass PASS / $fail FAIL ==="
