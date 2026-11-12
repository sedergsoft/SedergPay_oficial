#!/bin/bash
echo "=== Limites PHP efectivos ==="
docker exec sederg-pay-backend-1 php -r 'foreach (["upload_max_filesize","post_max_size","memory_limit","display_errors","max_execution_time"] as $k) { echo $k, " = ", ini_get($k), PHP_EOL; }'
echo ""
echo "=== Fichero ini cargado ==="
docker exec sederg-pay-backend-1 sh -c 'ls -la /usr/local/etc/php/conf.d/ | grep -i sederg; cat /usr/local/etc/php/conf.d/zz-sederg.ini | head -12'
