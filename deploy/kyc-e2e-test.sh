#!/bin/bash
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN="47b0c534-7d39-4759-ac4f-73d472b500ba"
CASE="7602bab7-e9b7-475f-a9af-036313f14538"

echo "=== Documentos del caso demo ==="
LIST=$(curl -sS -m 20 -H "X-User-Public-Id: $ADMIN" "$BASE/api/v1/admin/kyc/$CASE/documents")
echo "$LIST"
DOC_ID=$(echo "$LIST" | grep -o '"public_id":"[0-9a-f-]*"' | head -1 | cut -d'"' -f4)
echo "doc_public_id = $DOC_ID"

if [ -n "$DOC_ID" ]; then
  echo ""
  echo "=== Descarga como admin ==="
  curl -sS -m 30 -w '\nHTTP:%{http_code} TYPE:%{content_type} SIZE:%{size_download}\n' \
    -o /tmp/kyc-e2e-download.bin \
    -H "X-User-Public-Id: $ADMIN" \
    "$BASE/api/v1/admin/kyc/documents/$DOC_ID"
  file /tmp/kyc-e2e-download.bin
  echo ""
  echo "=== storage_key en BD ==="
  docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -c "SELECT public_id, storage_key, file_size FROM kyc_documents ORDER BY created_at DESC LIMIT 3;"'
  echo "=== Archivos fisicos en el volumen ==="
  docker exec sederg-pay-backend-1 sh -c 'find /var/lib/sederg-private/kyc -type f | head; du -sh /var/lib/sederg-private/kyc 2>/dev/null'
fi
