#!/bin/bash
# Prueba del camino de escritura: alta de beneficiario + cotizacion (limite diario)
set -u
BASE="https://5-22-219-30.sslip.io"
DEMO="55676b51-b1a7-40a0-878e-80e82c6285fc"

echo "=== Alta de beneficiario ==="
STAMP=$(date +%s)
KEY_BEN="ben-${STAMP}-0000-4000-8000-000000000001"
KEY_Q1="quo-${STAMP}-0000-4000-8000-000000000002"
KEY_Q2="quo-${STAMP}-0000-4000-8000-000000000003"
BEN=$(curl -sS -m 25 -w '\nHTTP:%{http_code}' -X POST "$BASE/api/v1/beneficiaries" \
  -H "X-User-Public-Id: $DEMO" -H 'Content-Type: application/json' -H "Idempotency-Key: $KEY_BEN" \
  -d '{"country_code":"CU","first_name":"Beneficiario","last_name":"Prueba","phone_e164":"+53555555555","email":"ben.prueba@sederg.test","payout_method_code":"bank_account","payout_provider":"Banco Popular","payout_account_number":"12345678901"}')
echo "$BEN" | head -c 600
BPUB=$(printf '%s' "$BEN" | grep -o '"public_id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo ""
echo "beneficiario: $BPUB"

if [ -z "$BPUB" ]; then echo "no se pudo crear el beneficiario"; exit 0; fi

echo ""
echo "=== Cotizacion (aplica el limite diario de 500 RUB) ==="
curl -sS -m 25 -w '\nHTTP:%{http_code}\n' -X POST "$BASE/api/v1/quotes" \
  -H "X-User-Public-Id: $DEMO" -H 'Content-Type: application/json' -H "Idempotency-Key: $KEY_Q1" \
  -d "{\"source_country_code\":\"RU\",\"destination_country_code\":\"CU\",\"send_amount\":\"300\",\"send_currency\":\"RUB\",\"receive_currency\":\"CUP\",\"payout_method_code\":\"bank_account\",\"fee_mode\":\"add_to_send\",\"beneficiary_public_id\":\"$BPUB\"}"

echo ""
echo "=== Cotizacion por encima del limite (1000 RUB sin KYC) ==="
curl -sS -m 25 -w '\nHTTP:%{http_code}\n' -X POST "$BASE/api/v1/quotes" \
  -H "X-User-Public-Id: $DEMO" -H 'Content-Type: application/json' -H "Idempotency-Key: $KEY_Q2" \
  -d "{\"source_country_code\":\"RU\",\"destination_country_code\":\"CU\",\"send_amount\":\"1000\",\"send_currency\":\"RUB\",\"receive_currency\":\"CUP\",\"payout_method_code\":\"bank_account\",\"fee_mode\":\"add_to_send\",\"beneficiary_public_id\":\"$BPUB\"}"

echo ""
echo "=== Limpieza del beneficiario de prueba ==="
curl -sS -m 20 -o /dev/null -w 'DELETE HTTP:%{http_code}\n' -X DELETE "$BASE/api/v1/beneficiaries/$BPUB" -H "X-User-Public-Id: $DEMO"
