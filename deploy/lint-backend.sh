#!/bin/bash
# Lint de los ficheros PHP modificados (copia temporal, no toca produccion)
set -u
for f in controllers/KycController.php src/Infrastructure/Identity/VerificationMailer.php src/Infrastructure/Compliance/KycDailyLimit.php; do
  echo "--- $f"
  docker exec -i sederg-pay-backend-1 sh -c "cat > /tmp/lint-check.php && php -l /tmp/lint-check.php" < "/home/deploy/sederg-pay/backend/$f"
done
