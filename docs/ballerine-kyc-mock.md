# Simulador local de Ballerine KYC

`kyc-mock` es un servicio de desarrollo que emula la parte de Ballerine que
SEDERG_PAY consume: creación de sesiones de colección y emisión de eventos
firmados hacia el webhook. Permite probar **el flujo real del backend** sin
desplegar Ballerine, sin licencias y sin datos de terceros.

No sustituye a Ballerine ni valida identidades: genera eventos sintéticos para
ejercitar la sesión, la autenticación HMAC, la idempotencia, el mapeo de estados
y la revisión manual del administrador.

## Arquitectura

```text
Navegador
  ├─ http://localhost:5173        Portal SEDERG_PAY
  └─ http://localhost:5174        kyc-mock (UI de colección)
          │  POST /api/sessions/{runtimeId}/events
          ▼
  kyc-mock (contenedor)
          │  POST http://backend:8080/api/v1/kyc/webhooks/ballerine
          │  X-Authorization: <secreto>  +  X-Ballerine-Signature: sha256=<hmac>
          ▼
  backend (Yii2) → MySQL (kyc_cases, kyc_provider_events, audit_logs)
```

El servicio vive detrás del profile `kyc-mock`, se publica solo en
`127.0.0.1:5174` y nunca forma parte de un despliegue productivo.

## Inicio rápido

Requisitos: `.env` creado, backend y MySQL levantados al menos una vez.

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\Enable-BallerineMock.ps1
```

El script:

1. Escribe la configuración `SEDERG_BALLERINE_*` en `.env` y genera un secreto
   de webhook aleatorio si falta.
2. Construye y arranca `kyc-mock` con `docker compose --profile kyc-mock`.
3. Recrea el backend para que cargue las variables.
4. Aplica las migraciones pendientes.
5. Ejecuta `php yii kyc/provider-status` y falla si el proveedor no queda listo.

Salida esperada al final:

```text
Mock listo: http://localhost:5174
```

Si tu `.env` ya tenía una configuración Ballerine real, el script se detiene.
Usa `-Force` solo si quieres sustituirla conscientemente por el simulador.

### Variables que escribe

```dotenv
SEDERG_BALLERINE_ENABLED=1
SEDERG_BALLERINE_BASE_URL=http://kyc-mock:3000
SEDERG_BALLERINE_API_KEY=local-mock-api-key
SEDERG_BALLERINE_WORKFLOW_ID=kyc-manual-review
SEDERG_BALLERINE_COLLECTION_URL=http://localhost:5174
SEDERG_BALLERINE_WEBHOOK_SECRET=<32 bytes aleatorios>
SEDERG_BALLERINE_RETURN_URL=http://localhost:5173
SEDERG_BALLERINE_WEBHOOK_TOLERANCE_SECONDS=3600
SEDERG_BALLERINE_MOCK_PORT=5174
```

`SEDERG_BALLERINE_COLLECTION_URL` es obligatoria: el adaptador ya no usa la URL
de la API como fallback, porque la API y la UI de colección son hosts distintos.

## Prueba de humo

1. Entra en `http://localhost:5173` con un usuario de prueba.
2. Completa país de residencia y fecha de nacimiento en el perfil.
3. Abre **KYC** y pulsa **Iniciar verificación**.
4. El navegador abre `http://localhost:5174/?workflowRuntimeId=...` con la sesión
   creada por el backend.
5. Pulsa **Proveedor aprueba**. El backend responde `HTTP 200`; el expediente
   pasa a `pending_verification`, no a `approved`.
6. Abre **Administración → KYC**, localiza el expediente y apruébalo. La
   decisión final sigue siendo del administrador.
7. Pulsa **Reenviar último evento**: el backend responde `"duplicate": true` y
   no genera transiciones ni auditorías nuevas.
8. Repite con **Solicitar nueva documentación** (`resubmission_required`) o
   **Estado desconocido** (cuarentena).

> El paso 5 es el que habilita la aprobación administrativa sin documentos
> locales: la revisión exige evidencia del proveedor con
> `provider_status = approved`.

## Prueba automática (integración)

`backend/tests/integration/ballerine_kyc_smoke.php` recorre el flujo completo
contra el backend real: crea la sesión, emite eventos firmados desde el mock,
comprueba la idempotencia y la cuarentena, rechaza firmas y timestamps
inválidos y valida la aprobación administrativa usando solo la evidencia del
proveedor. Es repetible: reinicia el expediente del usuario de CI antes de
empezar.

```powershell
docker compose --profile kyc-mock up -d
docker compose exec -T backend php tests/integration/ballerine_kyc_smoke.php
```

Salida esperada: `All Ballerine KYC smoke checks passed.` (52 comprobaciones).
Requiere `SEDERG_AUTH_MODE=development`. El script crea los usuarios
`ci-ballerine-customer@sederg.invalid` y `ci-ballerine-admin@sederg.invalid`.

## Eventos disponibles

| Acción en la UI | `state` enviado | Estado interno |
| --- | --- | --- |
| Documentos solicitados | `pending_documents` | `pending_documents` |
| Verificación en curso | `in_progress` | `in_progress` |
| Enviar a revisión | `pending_review` | `pending_verification` |
| Proveedor aprueba | `approved` | `pending_verification` |
| Proveedor rechaza | `rejected` | `pending_verification` |
| Solicitar nueva documentación | `revision` | `resubmission_required` |
| Marcar expirada | `expired` | `expired` |
| Marcar error | `failed` | `error` |
| Estado desconocido | `provider_state_unknown` | cuarentena (`internal_status = null`) |

Cada evento usa un `id` UUID distinto. **Reenviar último evento** reenvía el
cuerpo original con su `id` original para comprobar la idempotencia.

## API del simulador

```http
GET  /api/health
GET  /api/sessions
GET  /api/sessions/{runtimeId}
POST /api/sessions/demo
POST /api/sessions/{runtimeId}/adopt
POST /api/sessions/{runtimeId}/events   {"action":"approved"}
POST /api/v1/external/workflows/run      (contrato consumido por el backend)
```

El endpoint de workflows exige `Authorization: Api-Key <SEDERG_BALLERINE_API_KEY>`
y responde con `workflowRuntimeId` y `ballerineEntityId`.

Las sesiones viven en memoria (máximo 200). Si el contenedor se reinicia, abre
de nuevo el enlace de colección guardado en el expediente: la UI registra el
`workflowRuntimeId` automáticamente y los eventos siguen asociándose por ese
identificador.

## Operación

```powershell
# Estado del servicio y del proveedor
curl.exe http://localhost:5174/api/health
docker compose exec backend php yii kyc/provider-status

# Logs del simulador (incluye cada entrega de webhook)
docker compose --profile kyc-mock logs -f kyc-mock

# Recrear ambos servicios tras cambiar .env
docker compose up -d --force-recreate backend
docker compose --profile kyc-mock up -d --build kyc-mock
```

## Problemas frecuentes

| Síntoma | Causa y solución |
| --- | --- |
| La UI avisa de que falta el secreto | `SEDERG_BALLERINE_WEBHOOK_SECRET` vacío. Vuelve a ejecutar `Enable-BallerineMock.ps1`. |
| Webhook `HTTP 403` | Secreto distinto entre backend y mock, o reloj muy desviado. Recrea ambos servicios; la tolerancia local es de 3600 s. |
| `POST /api/v1/kyc/session` responde `503` | `SEDERG_BALLERINE_ENABLED=0` o falta alguna variable. Ejecuta `php yii kyc/provider-status`. |
| `collection_url` abre la API y no la UI | `SEDERG_BALLERINE_COLLECTION_URL` vacía o incorrecta. Debe ser `http://localhost:5174`. |
| `HTTP 502` al iniciar sesión | El backend no resuelve `kyc-mock`. Comprueba que el profile está activo: `docker compose ps`. |
| El puerto 5174 está ocupado | `.\scripts\Enable-BallerineMock.ps1 -Port 5184` (actualiza también la URL de colección). |
| «Sesión no encontrada» tras reiniciar el mock | Pulsa **Abrir** con el `workflowRuntimeId` del expediente; la UI lo registra y continúa. |

## Desactivar

```powershell
.\scripts\Disable-BallerineMock.ps1
```

Pone `SEDERG_BALLERINE_ENABLED=0`, recrea el backend y detiene `kyc-mock`.
Conserva el secreto y los datos para poder reactivarlo. El flujo de carga
documental de Yii2 vuelve a ser el único activo.

## Límites

- Solo desarrollo local; no se publica fuera de loopback.
- No procesa documentos, selfies ni datos reales.
- No implementa la lógica antifraude, listas de sanciones ni retención de
  Ballerine. Es un emulador de contrato, no un proveedor KYC.
- Antes de activar Ballerine real sigue siendo obligatorio completar
  [el runbook de activación](ballerine-kyc-activation.md) y la revisión legal
  del [ADR-001](adr/001-ballerine-kyc-core.md).
