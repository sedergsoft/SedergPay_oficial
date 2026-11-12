#!/bin/bash
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN="47b0c534-7d39-4759-ac4f-73d472b500ba"
AUTH=(-H "X-User-Public-Id: $ADMIN")

echo "=== Casos KYC pendientes de revision ==="
CASES=$(curl -sS -m 20 "${AUTH[@]}" "$BASE/api/v1/admin/kyc")
echo "$CASES" | head -c 600
echo ""
CASE=$(printf '%s' "$CASES" | tr '{' '\n' | grep "sedergsoft@gmail.com" | grep -o '"public_id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "caso: $CASE"

echo ""
echo "=== Documentos del caso ==="
DOCS=$(curl -sS -m 20 "${AUTH[@]}" "$BASE/api/v1/admin/kyc/$CASE/documents")
echo "$DOCS"
echo ""
for DOC in $(printf '%s' "$DOCS" | grep -o '"public_id":"[^"]*"' | cut -d'"' -f4); do
  echo "--- descarga $DOC"
  curl -sS -m 30 -o /tmp/kyc-real.bin -w 'HTTP:%{http_code} TYPE:%{content_type} SIZE:%{size_download}\n' "${AUTH[@]}" "$BASE/api/v1/admin/kyc/documents/$DOC"
  file -b /tmp/kyc-real.bin
  echo ""
done
