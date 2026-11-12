#!/bin/bash
# Espera al despliegue del backend (2cb93a8) y repite la bateria completa
sleep 240
echo "===== PRUEBAS DE INTEGRACION ADMIN (rev 3) ====="
bash /home/deploy/admin-integration-test.sh
