#!/bin/bash
set -u
DOC="081eed8a-3624-4318-8dbb-ab1f894b8bc7"
CASE="7602bab7-e9b7-475f-a9af-036313f14538"
KEY="kyc/1/c73528673373f0a0f76fb9825c3b4e07dda7f80e36f5ddc5.jpg"

echo "=== Borrar documento de prueba y caso demo (creados por la prueba) ==="
docker exec sederg-pay-postgres-1 sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -c \"DELETE FROM kyc_documents WHERE public_id='$DOC'; DELETE FROM kyc_cases WHERE public_id='$CASE' AND status='draft';\""
echo "=== Borrar archivo fisico de prueba ==="
docker exec sederg-pay-backend-1 rm -f "/var/lib/sederg-private/$KEY"
echo "=== Estado final ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT k.public_id, u.email, k.status FROM kyc_cases k JOIN users u ON u.id=k.user_id;"'
docker exec sederg-pay-backend-1 sh -c 'find /var/lib/sederg-private/kyc -type f | wc -l'
