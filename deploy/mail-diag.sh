#!/bin/bash
echo "=== Contenido de /app/runtime/logs ==="
docker exec sederg-pay-backend-1 sh -c 'ls -la /app/runtime/logs/ 2>&1'
echo ""
echo "=== mail-dev.log (contenido) ==="
docker exec sederg-pay-backend-1 sh -c 'cat /app/runtime/logs/mail-dev.log 2>&1 | tail -10'
echo ""
echo "=== web.log (conenido) ==="
docker exec sederg-pay-backend-1 sh -c 'cat /app/runtime/logs/web.log 2>&1 | tail -10'
echo ""
echo "=== Codigo desplegado: existe sendKycStatusChange? ==="
docker exec sederg-pay-backend-1 sh -c 'grep -c "sendKycStatusChange" /app/src/Infrastructure/Identity/VerificationMailer.php; grep -n "notifyKycStatusChange" /app/controllers/AdminController.php | head -3'
echo ""
echo "=== Prueba directa a la API de Brevo (¿IP autorizada?) ==="
KEY=$(grep -E '^SEDERG_MAIL_API_KEY=' /home/deploy/sederg-pay/.env | cut -d= -f2- | tr -d '"')
FROM=$(grep -E '^SEDERG_MAIL_FROM=' /home/deploy/sederg-pay/.env | cut -d= -f2- | tr -d '"')
curl -sS -m 25 -o /tmp/brevo.json -w 'HTTP:%{http_code}\n' -X POST https://api.brevo.com/v3/smtp/email \
  -H "api-key: $KEY" -H 'Content-Type: application/json' \
  -d "{\"sender\":{\"name\":\"SEDERG_PAY\",\"email\":\"$FROM\"},\"to\":[{\"email\":\"$FROM\"}],\"subject\":\"Prueba de envio KYC\",\"htmlContent\":\"<p>Prueba</p>\"}"
cat /tmp/brevo.json | head -c 400
