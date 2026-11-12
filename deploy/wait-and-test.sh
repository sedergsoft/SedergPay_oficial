#!/bin/bash
# Espera al despliegue y repite el flujo KYC completo
sleep 300
echo "===== PRUEBAS DE INTEGRACION DEL FLUJO KYC (rev 4) ====="
bash /home/deploy/kyc-integration-test.sh
echo ""
echo "===== TEST DE HUMO DE LA API ====="
bash /home/deploy/api-smoke-test.sh
