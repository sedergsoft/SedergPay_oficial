#!/bin/bash
echo "=== 1. Datos del cliente (users + user_profiles) ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT u.email, p.first_name, p.last_name FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=1 LIMIT 1;"'
echo ""
echo "=== 2. Administradores (users + user_profiles) ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT u.email, p.first_name FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.role='"'"'admin'"'"' ORDER BY u.id;"'
echo ""
echo "=== 3. Conteo de documentos del caso ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT COUNT(*) FROM kyc_documents WHERE kyc_case_id=(SELECT id FROM kyc_cases WHERE user_id=1);"'
echo ""
echo "=== 4. Consulta del limite diario corregida ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT COALESCE(SUM(send_amount), 0) FROM remittances WHERE sender_user_id = 1 AND send_currency='"'"'RUB'"'"' AND created_at >= CURRENT_DATE AND status <> '"'"'cancelled'"'"';"'
