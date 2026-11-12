#!/bin/bash
echo "=== Mounts del backend ==="
docker inspect sederg-pay-backend-1 --format '{{range .Mounts}}{{.Type}}: {{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
echo "=== Ruta privada dentro del backend ==="
docker exec sederg-pay-backend-1 sh -c 'ls -la "$SEDERG_PRIVATE_STORAGE_PATH" 2>&1 | head -6'
echo "=== Volumen docker ==="
docker volume ls | grep private
