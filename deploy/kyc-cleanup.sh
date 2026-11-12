#!/bin/bash
echo "=== Limpieza de cotizaciones de prueba ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -t -c "DELETE FROM remittance_quotes WHERE created_at >= now() - interval '"'"'2 hours'"'"' AND public_id IN ('"'"'82a2c1e2-e6d7-4799-86f2-17956c77114a'"'"');"'
echo ""
echo "=== Estado final de KYC ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT k.public_id, u.email, k.status, (SELECT COUNT(*) FROM kyc_documents d WHERE d.kyc_case_id=k.id) AS docs FROM kyc_cases k JOIN users u ON u.id=k.user_id;"'
echo "=== Beneficiarios de prueba restantes ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT public_id, email, status FROM beneficiaries WHERE email='"'"'ben.prueba@sederg.test'"'"';"'
echo "=== Ficheros en el volumen privado ==="
docker exec sederg-pay-backend-1 sh -c 'find /var/lib/sederg-private -type f | wc -l'
echo "=== Registro de correos KYC ==="
docker exec sederg-pay-backend-1 sh -c 'tail -4 /app/runtime/logs/mail-dev.log 2>/dev/null'
