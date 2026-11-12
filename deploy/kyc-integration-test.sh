#!/bin/bash
# =============================================================================
# Pruebas de integracion del flujo KYC completo contra la API de produccion.
#
#   Cliente  : ver estado -> subir documentos -> enviar a revision
#   Admin    : listar casos -> listar documentos -> descargar -> revisar
#   Errores  : fichero demasiado grande, tipo no admitido
#   Correo   : se comprueba que el aviso de cambio de estado se genera
#
# El script restaura el estado original (documentos, caso KYC y ficheros).
# =============================================================================
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN="47b0c534-7d39-4759-ac4f-73d472b500ba"
DEMO="55676b51-b1a7-40a0-878e-80e82c6285fc"
ORIGIN="https://sedergsoft.github.io"
ADMIN_AUTH=(-H "X-User-Public-Id: $ADMIN")
DEMO_AUTH=(-H "X-User-Public-Id: $DEMO")

pass=0; fail=0
ok()   { echo "PASS  $1"; pass=$((pass+1)); }
ko()   { echo "FAIL  $1"; echo "      $2"; fail=$((fail+1)); }

# check <nombre> <codigo_esperado> <json_esperado> <cuerpo>
check() {
  local name="$1" want="$2" pattern="$3" body="$4"
  local code
  code=$(printf '%s' "$body" | sed -n 's/.*HTTP:\([0-9]*\)$/\1/p')
  local payload
  payload=$(printf '%s' "$body" | sed 's/HTTP:[0-9]*$//')
  if [ "$code" = "$want" ] && printf '%s' "$payload" | grep -q "$pattern"; then
    ok "$name (HTTP $code)"
  else
    ko "$name" "HTTP $code (esperaba $want) | $(printf '%s' "$payload" | tr -d '\n' | head -c 260)"
  fi
}

# --- ficheros de prueba -------------------------------------------------------
echo "/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==" | base64 -d > /tmp/kyc-identity.jpg
echo "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" | base64 -d > /tmp/kyc-address.png
cp /tmp/kyc-identity.jpg /tmp/kyc-3mb.jpg && head -c 3145728 /dev/urandom >> /tmp/kyc-3mb.jpg
cp /tmp/kyc-identity.jpg /tmp/kyc-huge.jpg && head -c 6500000 /dev/urandom >> /tmp/kyc-huge.jpg
printf 'esto no es un documento' > /tmp/kyc-notallowed.txt
ls -la /tmp/kyc-identity.jpg /tmp/kyc-address.png /tmp/kyc-3mb.jpg /tmp/kyc-huge.jpg /tmp/kyc-notallowed.txt | awk '{print $5, $9}'

upload() { # upload <fichero> <tipo> <campo>  -> cuerpo con HTTP:<code>
  curl -sS -m 90 -w 'HTTP:%{http_code}' -X POST "$BASE/api/v1/kyc/documents" \
    -H "Origin: $ORIGIN" "${DEMO_AUTH[@]}" \
    -F "file=@$1;type=$2" -F "document_type=$3"
}

echo ""
echo "=== 1. GET /api/v1/kyc (estado inicial del cliente) ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "${DEMO_AUTH[@]}" "$BASE/api/v1/kyc")
check "kyc-view" 200 '"documents"' "$body"

echo ""
echo "=== 2. Subida de documentos por el cliente ==="
body=$(upload /tmp/kyc-identity.jpg image/jpeg identity)
check "upload-identity" 200 '"uploaded":true' "$body"
body=$(upload /tmp/kyc-address.png image/png address)
check "upload-address" 200 '"uploaded":true' "$body"

echo ""
echo "=== 3. Subida de un documento de 3 MB (antes fallaba por el limite de 2 MB de PHP) ==="
body=$(upload /tmp/kyc-3mb.jpg image/jpeg selfie)
check "upload-3mb" 200 '"uploaded":true' "$body"

echo ""
echo "=== 4. Documento mayor de 5 MB -> error claro (no generico) ==="
body=$(upload /tmp/kyc-huge.jpg image/jpeg identity)
check "upload-too-large" 422 'exceeds the maximum allowed size' "$body"

echo ""
echo "=== 5. Tipo de fichero no admitido (TXT) ==="
body=$(upload /tmp/kyc-notallowed.txt text/plain identity)
check "upload-not-allowed" 422 'Only PDF, JPG and PNG' "$body"

echo ""
echo "=== 6. Documentos registrados en el expediente ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "${DEMO_AUTH[@]}" "$BASE/api/v1/kyc")
check "kyc-documents" 200 '"kyc-address.png\|kyc-identity.jpg' "$body"
DOCS=$(printf '%s' "$body" | grep -o '"original_name":"[^"]*"' | sed 's/.*:"//;s/"$//' | tr '\n' ' ')
echo "      documentos: $DOCS"
echo ""
echo "=== 7. El cliente envia el expediente a revision ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' -X POST "${DEMO_AUTH[@]}" -H 'Content-Type: application/json' -d '{}' "$BASE/api/v1/kyc/submit")
check "kyc-submit" 200 '"status":"pending_review"' "$body"

echo ""
echo "=== 8. El admin ve el caso en revision ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "${ADMIN_AUTH[@]}" "$BASE/api/v1/admin/kyc")
check "admin-kyc-list" 200 '"pending_review"' "$body"
CASE=$(printf '%s' "$body" | tr '{' '\n' | grep "$DEMO" | grep -o '"public_id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "      caso demo: $CASE"
if [ -z "$CASE" ]; then
  ko "admin-kyc-case-found" "no se encontro el caso del cliente demo en la respuesta"
  echo ""
  echo "=== RESULTADO: $pass PASS / $fail FAIL ==="
  exit 1
fi

echo ""
echo "=== 9. El admin lista los documentos del caso ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "${ADMIN_AUTH[@]}" "$BASE/api/v1/admin/kyc/$CASE/documents")
check "admin-kyc-documents" 200 '"documents"' "$body"
DOC_ID=$(printf '%s' "$body" | grep -o '"public_id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "      documento: $DOC_ID"

echo ""
echo "=== 10. El admin descarga el documento (fichero real, no vacio) ==="
code=$(curl -sS -m 30 -o /tmp/kyc-download.bin -w '%{http_code}' "${ADMIN_AUTH[@]}" "$BASE/api/v1/admin/kyc/documents/$DOC_ID")
ctype=$(file -b --mime-type /tmp/kyc-download.bin)
size=$(stat -c %s /tmp/kyc-download.bin)
if [ "$code" = "200" ] && [ "$size" -gt 100 ] && [ "$ctype" = "image/jpeg" ]; then
  ok "admin-kyc-download (HTTP 200, $ctype, $size bytes)"
else
  ko "admin-kyc-download" "HTTP $code, $ctype, $size bytes"
fi

echo ""
echo "=== 11. Revision: se solicita informacion adicional ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' -X POST "${ADMIN_AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"decision":"more_information","reason":"Falta el comprobante de domicilio"}' "$BASE/api/v1/admin/kyc/$CASE/review")
check "review-more-information" 200 '"status":"requires_action"' "$body"

echo ""
echo "=== 12. El cliente ve el estado y el motivo ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "${DEMO_AUTH[@]}" "$BASE/api/v1/kyc")
check "kyc-requires-action" 200 '"requires_action"' "$body"
check "kyc-review-reason" 200 'Falta el comprobante de domicilio' "$body"

echo ""
echo "=== 13. El cliente vuelve a subir y reenviar el expediente ==="
body=$(upload /tmp/kyc-address.png image/png address)
check "re-upload" 200 '"uploaded":true' "$body"
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' -X POST "${DEMO_AUTH[@]}" -H 'Content-Type: application/json' -d '{}' "$BASE/api/v1/kyc/submit")
check "re-submit" 200 '"status":"pending_review"' "$body"

echo ""
echo "=== 14. Revision: expediente denegado ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' -X POST "${ADMIN_AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"decision":"rejected","reason":"Los documentos no son legibles"}' "$BASE/api/v1/admin/kyc/$CASE/review")
check "review-rejected" 200 '"status":"rejected"' "$body"

echo ""
echo "=== 15. El cliente recupera el expediente y lo reenvia ==="
body=$(upload /tmp/kyc-identity.jpg image/jpeg identity)
check "re-upload-2" 200 '"uploaded":true' "$body"
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' -X POST "${DEMO_AUTH[@]}" -H 'Content-Type: application/json' -d '{}' "$BASE/api/v1/kyc/submit")
check "re-submit-2" 200 '"status":"pending_review"' "$body"

echo ""
echo "=== 16. Revision: expediente aprobado ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' -X POST "${ADMIN_AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"decision":"approved","reason":""}' "$BASE/api/v1/admin/kyc/$CASE/review")
check "review-approved" 200 '"status":"approved"' "$body"

echo ""
echo "=== 17. El cliente ve la verificacion completada ==="
body=$(curl -sS -m 20 -w 'HTTP:%{http_code}' "${DEMO_AUTH[@]}" "$BASE/api/v1/kyc")
check "kyc-approved" 200 '"approved"' "$body"

echo ""
echo "=== 18. Aviso por correo del cambio de estado ==="
docker exec sederg-pay-backend-1 sh -c 'tail -40 /app/runtime/logs/mail-dev.log 2>/dev/null | grep -iE "kyc-status|kyc-review-requested" | tail -8' > /tmp/kyc-mail.log 2>&1
if grep -qE "kyc-status.*enviado=si|kyc-review-requested.*enviado=si" /tmp/kyc-mail.log; then
  ok "kyc-mail-notification (correo enviado)"
  sed 's/^/      /' /tmp/kyc-mail.log | tail -5
else
  ko "kyc-mail-notification" "sin trazas de envio en mail-dev.log: $(cat /tmp/kyc-mail.log | tr '\n' ' ' | head -c 300)"
fi

echo ""
echo "=== 19. Auditoria: el cambio de estado queda registrado ==="
body=$(docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -c "SELECT COUNT(*) FROM audit_logs WHERE action='"'"'kyc.case.reviewed'"'"';"')
if printf '%s' "$body" | grep -qE '[1-9]'; then
  ok "audit-kyc-reviewed ($body)"
else
  ko "audit-kyc-reviewed" "sin registros: $body"
fi

echo ""
echo "=== RESTAURACION DEL ESTADO ORIGINAL ==="
docker exec sederg-pay-postgres-1 sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -q -c \"DELETE FROM kyc_documents WHERE kyc_case_id=(SELECT id FROM kyc_cases WHERE user_id=(SELECT id FROM users WHERE public_id='$DEMO')); DELETE FROM kyc_cases WHERE user_id=(SELECT id FROM users WHERE public_id='$DEMO');\""
docker exec sederg-pay-backend-1 sh -c "find /var/lib/sederg-private/kyc/1 -type f -delete 2>/dev/null; rm -rf /var/lib/sederg-private/kyc/1 2>/dev/null; true"
echo "      documentos y caso de prueba eliminados"
rm -f /tmp/kyc-identity.jpg /tmp/kyc-address.png /tmp/kyc-3mb.jpg /tmp/kyc-huge.jpg /tmp/kyc-notallowed.txt /tmp/kyc-download.bin

echo ""
echo "=== RESULTADO: $pass PASS / $fail FAIL ==="
