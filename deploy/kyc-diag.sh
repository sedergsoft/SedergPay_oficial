#!/bin/bash
set -u
BASE="https://5-22-219-30.sslip.io"
ADMIN="47b0c534-7d39-4759-ac4f-73d472b500ba"
DEMO="55676b51-b1a7-40a0-878e-80e82c6285fc"
echo "=== Casos KYC existentes (usuarios) ==="
docker exec sederg-pay-postgres-1 sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT k.public_id, u.email, k.status FROM kyc_cases k JOIN users u ON u.id=k.user_id ORDER BY k.created_at;"'
