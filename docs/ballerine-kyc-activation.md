# Activación real de Ballerine KYC

Este documento es el runbook para habilitar la integración con un despliegue
real de Ballerine. El repositorio **no despliega Ballerine**: el workflow service
y la UI de colección deben desplegarse, fijarse y revisarse por separado.

> Para probar el flujo completo sin desplegar Ballerine usa el
> [simulador local](ballerine-kyc-mock.md) (`docker compose --profile kyc-mock`).
> Valida contrato, webhook, idempotencia y revisión manual, pero no sustituye
> ninguno de los prerrequisitos de esta sección para tráfico real.

## 1. Prerrequisitos que no se resuelven en este repositorio

Antes de activar tráfico real:

- Fijar una versión o commit de Ballerine y completar la revisión de licencia,
  dependencias y seguridad descrita en [ADR-001](adr/001-ballerine-kyc-core.md).
- Desplegar workflow service y UI de colección con HTTPS fuera de desarrollo.
- Definir el tratamiento de datos y residencia aplicable a Rusia y Cuba.
- Confirmar cobertura de revisión manual y documentos aceptados.
- No usar identidades ni documentos reales durante las pruebas iniciales.

## 2. Variables de activación

La integración permanece apagada mientras `SEDERG_BALLERINE_ENABLED` no sea `1`.

```dotenv
SEDERG_BALLERINE_ENABLED=1
SEDERG_BALLERINE_BASE_URL=https://ballerine.internal
SEDERG_BALLERINE_API_KEY=<clave server-side>
SEDERG_BALLERINE_WORKFLOW_ID=<id del workflow KYC>
SEDERG_BALLERINE_COLLECTION_URL=https://collect.example
SEDERG_BALLERINE_WEBHOOK_SECRET=<secreto compartido de al menos 16 caracteres>
SEDERG_BALLERINE_RETURN_URL=https://app.example
```

Opcionales:

```dotenv
SEDERG_BALLERINE_COLLECTION_URL_TEMPLATE={collectionBaseUrl}?workflowRuntimeId={workflowRuntimeId}
SEDERG_BALLERINE_AUTH_HEADER=Authorization
SEDERG_BALLERINE_AUTH_SCHEME=Api-Key
SEDERG_BALLERINE_TIMEOUT_SECONDS=15
SEDERG_BALLERINE_WEBHOOK_TOLERANCE_SECONDS=300
SEDERG_BALLERINE_SESSION_TTL_SECONDS=3600
```

Reglas de seguridad:

- Fuera de `SEDERG_AUTH_MODE=development`, todas las URLs deben ser `https`.
- `SEDERG_BALLERINE_WEBHOOK_SECRET` debe coincidir con el `WEBHOOK_SECRET` del
  workflow service de Ballerine.
- Nunca commitear claves reales en `.env.example`, `render.yaml` ni el repo.

## 3. Aplicar la migración

```powershell
docker compose run --rm --no-deps backend php yii migrate --migrationPath=@app/migrations --interactive=0
```

La migración crea las columnas de proveedor en `kyc_cases`, la tabla idempotente
`kyc_provider_events` y amplía `kyc_cases.status` para estados como
`resubmission_required`.

## 4. Configurar el webhook en Ballerine

En la definición del workflow, suscribir:

```text
POST https://api.example/api/v1/kyc/webhooks/ballerine
```

Para pruebas locales con Ballerine en el host:

```text
http://host.docker.internal:8080/api/v1/kyc/webhooks/ballerine
```

Autenticación aceptada por SEDERG_PAY:

- `X-Authorization: <SEDERG_BALLERINE_WEBHOOK_SECRET>`
- `Authorization: <SEDERG_BALLERINE_WEBHOOK_SECRET>` como alias
- `X-Ballerine-Signature: sha256=<HMAC-SHA256 del cuerpo crudo>`

El timestamp del payload debe estar dentro de
`SEDERG_BALLERINE_WEBHOOK_TOLERANCE_SECONDS`.

## 5. Verificación antes de abrir tráfico

Ejecutar el chequeo de consola:

```powershell
docker compose run --rm --no-deps backend php yii kyc/provider-status
```

Debe reportar `enabled: yes`, `session configured: yes`, `webhook configured:
yes` y `events table: available`.

Como administrador, consultar también:

```http
GET /api/v1/admin/kyc/provider
```

Respuesta esperada (sin secretos):

```json
{
  "provider": {
    "configured": true,
    "enabled": true,
    "session_configured": true,
    "webhook_configured": true,
    "configuration_error": false,
    "events_available": true
  },
  "last_event_at": null,
  "events_last_24h": [],
  "pending_review_cases": 0,
  "webhook_path": "/api/v1/kyc/webhooks/ballerine"
}
```

## 6. Prueba de humo con datos sintéticos

1. Iniciar sesión con un usuario de prueba sin expediente KYC finalizado.
2. Abrir KYC y pulsar **Iniciar verificación**.
3. Confirmar que se devuelve una `collection_url` y que el usuario es redirigido.
4. Completar el flujo con datos ficticios.
5. Verificar que el webhook aparece en `kyc_provider_events` y que el estado
   interno pasa a `pending_verification` o `manual_review`, nunca a `approved`.
6. En el panel admin, confirmar que el expediente aparece como revisable y que
   muestra proveedor, estado y último evento.
7. Aprobar o rechazar manualmente. El proveedor no debe poder sobrescribir una
   decisión final.
8. Reenviar el mismo evento y confirmar HTTP 200 con `"duplicate": true`, sin
   nuevas transiciones ni auditorías.
9. Enviar un estado desconocido y confirmar que queda en cuarentena.

## 7. Monitoreo

- Panel admin KYC: estado del proveedor, último evento y excepciones de 24 h.
- `GET /api/v1/admin/kyc/provider`: `quarantined`, `unmatched` y `out_of_order`.
- Logs del backend: rechazos de webhook con firma, timestamp o payload inválido.
- Alertar si hay eventos en cuarentena, expedientes `pending_verification`
  acumulados o ausencia de eventos después de iniciar sesiones.

## 8. Rollback

Desactivar primero el tráfico nuevo:

```dotenv
SEDERG_BALLERINE_ENABLED=0
```

Con el flag apagado:

- `POST /api/v1/kyc/session` responde 503.
- El webhook responde 503 y deja de aplicar eventos.
- El flujo Yii2 de carga documental y revisión manual sigue disponible.

Después, revertir la migración solo si se ha confirmado que no hay datos de
proveedor necesarios:

```powershell
docker compose run --rm --no-deps backend php yii migrate/down 1 --migrationPath=@app/migrations --interactive=0
```

No revertir la migración con expedientes en estados de proveedor sin un plan de
conservación de evidencia.

## 9. Criterio de activación

Activar `SEDERG_BALLERINE_ENABLED=1` en producción solo cuando:

- Ballerine esté desplegado con versión fijada y revisión legal completada.
- La migración esté aplicada.
- El webhook esté suscrito y autenticado correctamente.
- `php yii kyc/provider-status` y el endpoint admin reporten todo en verde.
- La prueba de humo sintética haya pasado.
- Exista cobertura operativa para revisar manualmente los casos del proveedor.
