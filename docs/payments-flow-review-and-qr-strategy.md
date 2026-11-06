# Revisión del flujo de transferencia y estrategia de QR de pago

Fecha: 2026-11-06
Rama revisada: `feat/ballerine-kyc-core` (árbol de trabajo actual)
Alcance: corredores RU→CU y CU→RU, fondeo (inbound), desembolso (outbound) y confirmación del pago.

---

## 1. Resumen ejecutivo

El flujo está bien modelado en dominios separados (cotización, remesa, depósito, cumplimiento, desembolso, conciliación, ledger), pero **no se puede completar de principio a fin desde la interfaz**: falta la confirmación del depósito y el QR que se muestra no es pagable. El módulo de métodos de pago existe y está integrado; lo que falta es la capa de *payment intent* (orden de cobro) con su QR y su confirmación automática.

Prioridades:

1. **P0** — Hacer que el pago se pueda completar en local: QR escaneable → página de pago → confirmación → remesa avanza.
2. **P0** — Corregir idempotencia y concurrencia en la creación del depósito.
3. **P1** — Webhook de pagos firmado + conciliación (la tabla `bank_webhook_events` ya existe y no se usa).
4. **P1** — Expiración/cancelación y reintento de pago.
5. **P2** — QR interoperable real (SBP C2B para RUB, EMVCo/EPC para otros corredores).

---

## 2. Flujo actual (as-is)

```
Cotización → Crear remesa → Elegir método → Crear depósito → (QR) → [stub] confirmar
   Quote        Remittance      Funding       DepositRequest        Payment     Compliance → Payout → Conciliación
```

### 2.1 Pasos y endpoints

| # | Acción | Endpoint | Estado resultante |
|---|---|---|---|
| 1 | Cotizar | `POST /api/v1/quotes` | `remittance_quotes.status = valid` (expira) |
| 2 | Crear remesa | `POST /api/v1/remittances` | `remittances.status = awaiting_payment` |
| 3 | Ver métodos de fondeo | `GET /api/v1/funding-methods/{RU\|CU}` | — |
| 4 | Crear instrucción de pago | `POST /api/v1/remittances/{id}/deposit` | `deposit_requests.status = pending` |
| 5 | Confirmar pago | `POST /api/v1/deposits/{id}/simulate-confirmation` | depósito `confirmed`, remesa `compliance_review` |
| 6 | Revisión de cumplimiento | `POST /api/v1/remittances/{id}/compliance-review` | `approved` / `rejected` |
| 7 | Crear payout | `POST /api/v1/remittances/{id}/payout` | `payout_orders.status = pending` |
| 8 | Completar payout | `POST /api/v1/payouts/{id}/simulate-completion` | `paid` → `reconciliation_pending` |
| 9 | Conciliar | `POST /api/v1/remittances/{id}/reconcile` | `completed` |

Rutas en [web.php](../backend/config/web.php#L73-L83). Máquina de estados en [RemittanceStateMachine.php](../backend/src/Domain/Remittance/RemittanceStateMachine.php#L14-L27).

### 2.2 Problema central

En el paso 5 **no hay camino de usuario**. `api.confirmDeposit` está definido en [api.ts](../frontend/src/api.ts#L321) pero **no se invoca en ningún componente**. El endpoint además exige `SEDERG_AUTH_MODE=development` ([PaymentController.php](../backend/controllers/PaymentController.php#L88-L92)). Resultado: tras crear el depósito, la remesa se queda en `awaiting_payment` indefinidamente salvo que alguien llame al API a mano.

Además, al crear la remesa se renderiza el panel de pago **antes** de elegir método ([App.tsx](../frontend/src/App.tsx#L394)), con `payment_qr_payload = null` y sin datos bancarios: el usuario ve un panel vacío con guiones.

---

## 3. Hallazgos priorizados

### P0 — Bloquean el uso real del flujo

**P0-1. No existe confirmación de pago accesible.**
- `confirmDeposit` sin uso en el frontend.
- No hay `GET /deposits/{id}` para consultar estado ni polling.
- Solo funciona en `development`.
- Corrección: endpoint de consulta + botón "Confirmar pago (sandbox)" + polling en la vista de la remesa; en producción, webhook + job de conciliación.

**P0-2. El QR no es pagable ni escaneable con propósito.**
- [SbpSandboxQrProvider.php](../backend/src/Infrastructure/Payment/SbpSandboxQrProvider.php#L31-L33) genera `sedergpay-sandbox://...`; ninguna app lo abre.
- [GenericSandboxQrProvider.php](../backend/src/Infrastructure/Payment/GenericSandboxQrProvider.php#L20-L23) igual para CUP.
- Corrección: el QR debe codificar una URL accesible (p. ej. `http://<LAN-IP>:5173/pay/<token>`) que abra una página de pago con importe, referencia y botón de confirmación. En producción, la URL/payload lo emite el adquirente.

**P0-3. La idempotencia impide reintentar el pago.**
- La clave es fija: `deposit-${remittanceId}` ([api.ts](../frontend/src/api.ts#L316-L320)).
- El servidor devuelve la respuesta cacheada 24 h ([FundingController.php](../backend/controllers/FundingController.php#L241-L247)). Si el QR expiró, el usuario recibe el mismo QR muerto y no puede generar otro.
- Corrección: clave por intento (`crypto.randomUUID()`) y validación de que el intento cacheado siga vigente; o reutilizar el intento activo si existe.

**P0-4. Carrera al crear depósito.**
- El control `COUNT(*)` de depósitos activos se ejecuta dentro de la transacción pero **sin bloquear la remesa** ([FundingController.php](../backend/controllers/FundingController.php#L305-L315)). Dos peticiones concurrentes pueden crear dos depósitos (y dos QR) para la misma remesa.
- `SimulatedPaymentRepository` sí bloquea con `FOR UPDATE` ([SimulatedPaymentRepository.php](../backend/src/Infrastructure/Payment/SimulatedPaymentRepository.php#L74-L89)); `FundingController` no.
- Corrección: `SELECT id FROM remittances WHERE id = ? FOR UPDATE` antes del conteo, o índice único parcial equivalente (MySQL: columna generada `active_remittance_id` única).

**P0-5. Sin expiración ni cancelación.**
- `deposit_requests.expires_at` se guarda pero nada lo aplica; no hay comando de consola (solo `RatesController`).
- La remesa no puede cancelarse desde el API aunque la máquina de estados lo permite.
- Corrección: `php yii payments/expire` (cron cada minuto), estado `expired` en depósito, y endpoint/UI de cancelación. Al expirar, permitir nuevo intento sin crear otra remesa.

### P1 — Necesarios antes de un piloto

**P1-1. No hay webhook de pagos.**
- Solo existe el webhook de Ballerine. La tabla `bank_webhook_events` ([migración base](../backend/migrations/m261014_000001_create_sederg_pay_schema.php#L274-L289)) ya tiene `provider_event_id` único, `signature_valid` y estado de procesamiento, pero no se usa.
- Corrección: `POST /api/v1/webhooks/payments/{provider}`, cuerpo crudo, HMAC + timestamp + nonce, deduplicación por `(bank_connection_id, provider_event_id)`, y procesador idempotente.

**P1-2. La confirmación no valida importe, moneda ni referencia.**
- `confirmDeposit` acredita `total_debit_amount` sin comparar con lo recibido ([SimulatedPaymentRepository.php](../backend/src/Infrastructure/Payment/SimulatedPaymentRepository.php#L110-L164)).
- En real: pago parcial, excedente, moneda distinta o referencia ajena deben ir a revisión manual, nunca a acreditación automática.

**P1-3. El detalle de remesa puede duplicar filas.**
- `findForUser` hace `LEFT JOIN deposit_requests` y `payout_orders` con `LIMIT 1` ([RemittanceRepository.php](../backend/src/Infrastructure/Remittance/RemittanceRepository.php#L200-L216)). Con más de un depósito (reintentos), la fila devuelta es arbitraria.
- Corrección: subconsultas al último depósito/payout, o endpoints separados.

**P1-4. El payout no reserva liquidez.**
- `PayoutRepository::complete` pasa a `funds_confirmed` sin comprobar saldo ni reservar ([PayoutRepository.php](../backend/src/Infrastructure/Payout/PayoutRepository.php#L77-L125)).
- Corrección: reserva en ledger al aprobar, liberación/consumo al pagar, y bloqueo si no hay liquidez en Cuba.

**P1-5. En `development` cualquier usuario autenticado aprueba y paga.**
- `requireBackOfficeAccess` solo exige admin fuera de `development` ([ComplianceController.php](../backend/controllers/ComplianceController.php#L102-L109), [PayoutController.php](../backend/controllers/PayoutController.php#L101-L106)).
- Correcto para sandbox local, peligroso si un entorno compartido queda con `SEDERG_AUTH_MODE=development`. Añadir bandera explícita `SEDERG_SANDBOX_OPERATOR=1` y auditoría del actor.

**P1-6. Sin reversos ni devoluciones.**
- No hay endpoint de cancelación/reembolso ni asientos compensatorios. Un pago duplicado o un rechazo posterior no tiene circuito.

### P2 — Mejoras de producto y control

- Correo solo al crear la remesa; no se notifica `paid`, `payout_failed` o `requires_action`.
- Sin límites de velocidad por usuario, sin reglas antifraude ni screening de sanciones.
- Sin métricas/alertas de depósitos pendientes, QR expirados o discrepancias.
- Validación de datos de beneficiario limitada (Luhn para tarjeta, IBAN mod-97, formatos cubanos).
- `payment_status` en `metadata` se queda en `method_selection_required` aunque ya exista depósito.

---

## 4. Investigación: pagos rápidos y QR

### 4.1 Qué define a un sistema de pagos instantáneos

Un esquema de pagos instantáneos (SBP, Pix, UPI, SEPA Instant, FedNow, FPS) comparte cuatro piezas:

1. **Directorio/identificador** del beneficiario (teléfono, alias, IBAN, merchant ID).
2. **Mensajería** de crédito inmediato y confirmación (ISO 20022 o propietaria).
3. **Liquidación** 24/7 con finalidad en segundos.
4. **Canal de iniciación**: QR, deep link, API o alias.

El QR es solo el **canal de iniciación**; la confirmación siempre viene del esquema/proveedor, nunca del escaneo.

### 4.2 SBP / СБП (Rusia)

- Operado por el Banco de Rusia; NSPK actúa como cámara y operador técnico. Funciona 24/7/365 y soporta pago a comercios por QR ([CBR](https://www.cbr.ru/eng/psystem/sfp/), [sbp.nspk.ru](https://sbp.nspk.ru/)).
- Para cobrar como comercio (C2B) se necesita **contrato de adquirencia con un banco participante**; el comercio no genera el QR por su cuenta.
- El flujo técnico típico es:
  1. backend del comercio → API del adquirente: crear orden/QR con importe, moneda, referencia, descripción y vigencia;
  2. adquirente → `qrId`, `payload` (URL NSPK que reconocen las apps bancarias) e imagen;
  3. el cliente escanea y paga;
  4. adquirente → callback firmado y/o consulta de estado (`PAID`, `REVOKED`, `EXPIRED`);
  5. conciliación por `qrId`, importe, moneda y referencia.
- **Dinámico vs estático**: el dinámico lleva importe y referencia únicos y es de un solo uso; el estático identifica al comercio y el pagador introduce el importe. Para cobros de remesas el dinámico es el correcto.
- La comisión C2B es de adquirencia y se negocia; no confundir con C2C entre particulares. El repo ya documenta esta distinción ([payment-provider-integration-readiness.md](../backend/docs/payment-provider-integration-readiness.md#L13-L21)).
- Requisitos no técnicos: entidad rusa o figura admitida por el banco, KYC/AML del comercio, MCC, límites y cuenta de abono.

### 4.3 Modelos de referencia internacionales

| Esquema | País | Formato QR | Quién emite el QR | Confirmación |
|---|---|---|---|---|
| SBP C2B | Rusia | URL NSPK (dinámico/estático) | Banco adquirente | Callback + consulta |
| Pix | Brasil | BR Code (EMVCo TLV) | PSP del comercio | Webhook + consulta |
| UPI | India | Intent/QR del PSP | PSP | Callback |
| QRIS | Indonesia | EMVCo MPM | Adquirente | Callback |
| PromptPay | Tailandia | EMVCo | Banco | Callback |
| PayNow | Singapur | EMVCo | Banco | Callback |
| SEPA Instant | UE | EPC QR (`BCD` + SCT + IBAN) | Banco/PSP | Conciliación |
| FedNow/RTP | EE. UU. | Sin QR universal | Proveedor | API/webhook |

Conclusión: **no existe un QR universal**. Existe un estándar de codificación (EMVCo) y estándares regionales de payload. El backend debe abstraer el "payment rail" y no inventar el contenido.

### 4.4 Cuba

- `Transfermóvil` (ETECSA con Banco Metropolitano) y `EnZona` (XETID) operan pagos por QR a nivel doméstico.
- No hay documentación pública de API de comercio para entidades extranjeras; el acceso depende de contrato con el banco/operador cubano y de las condiciones vigentes.
- Para el corredor RU→CU, el fondeo ocurre en Rusia (SBP/transferencia) y la entrega en Cuba (efectivo, cuenta o tarjeta). El QR cubano no resuelve el fondeo del remitente.
- Riesgo operativo: cortes de conectividad y energía → la conciliación debe ser asíncrona y tolerante a reintentos.

### 4.5 Qué hace "siempre QR" posible

Un QR solo es pagable si lo emite el riel. La estrategia correcta es una **cadena de degradación explícita**:

| Nivel | Tipo | Cuándo | Qué codifica | Etiqueta UI |
|---|---|---|---|---|
| L1 | QR dinámico del proveedor | El método tiene adquirencia/API | Payload oficial (SBP, EMVCo, EPC) | "Escanea y paga" |
| L2 | QR estático del comercio | El proveedor lo permite | ID de comercio + referencia | "Escanea e introduce el importe" |
| L3 | QR informativo | Sin riel QR | URL firmada a nuestra página de pago | "Escanea para ver los datos de pago" |

Reglas:

- **Nunca** presentar L3 como QR bancario.
- L3 sigue siendo útil: abre una página con importe exacto, referencia, botón de copiar y deep link a la app del banco. También sirve para el sandbox.
- Todo QR debe ser de un solo uso, con importe, moneda, referencia y vencimiento.
- El payload se genera y firma en backend; el frontend solo lo renderiza.
- La confirmación nunca se deriva del escaneo: solo de webhook autenticado, consulta de estado o conciliación.

Detalle EMVCo (si se emite L1 para otros corredores): TLV con `00` versión, `01` modo (estático/dinámico), `26–51` cuenta de comercio, `52` MCC, `53` moneda ISO 4217 numérica, `54` importe, `58` país, `59` nombre, `60` ciudad, `62` datos adicionales (referencia) y `63` CRC16-CCITT.

---

## 5. Diseño propuesto

### 5.1 Modelo: `payment_intent`

Sustituir el uso directo de `deposit_requests` por una entidad de cobro explícita (o evolucionar la tabla existente):

```
payment_intents
  public_id, remittance_id, method_code, provider_code, environment
  amount, currency_code, reference
  qr_kind      provider_dynamic | provider_static | epc | link | none
  qr_payload, qr_image_url, qr_expires_at
  provider_payment_id, provider_status
  status       created | awaiting_payment | paid | expired | cancelled | failed
  paid_amount, paid_at, payer_reference
  idempotency_key, created_at, updated_at
  UNIQUE (provider_code, provider_payment_id)
  UNIQUE (remittance_id, idempotency_key)
```

- Snapshot inmutable de cuenta, método, importe y referencia (ya existe `account_snapshot`).
- Un solo intento activo por remesa; los anteriores pasan a `expired`/`cancelled`.
- `bank_webhook_events` para eventos crudos y `reconciliation_cases` para discrepancias.

### 5.2 Adaptador de proveedor

```php
interface PaymentRail
{
    public function createIntent(PaymentRequest $r): PaymentIntentResult; // payload + qr + expires
    public function getStatus(string $providerPaymentId): PaymentStatus;
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent;
    public function refund(string $providerPaymentId, string $amount): RefundResult;
}
```

Implementaciones: `SbpAcquirerRail` (real, cuando exista contrato), `SandboxRail` (local), `SepaEpcRail` (futuro), `ManualTransferRail` (sin QR, solo referencia).

### 5.3 Endpoints nuevos

| Método | Ruta | Uso |
|---|---|---|
| POST | `/api/v1/remittances/{id}/payment-intents` | Crear intento (idempotente por clave de cliente) |
| GET | `/api/v1/payment-intents/{id}` | Estado + QR vigente |
| POST | `/api/v1/payment-intents/{id}/cancel` | Cancelar y liberar la remesa |
| GET | `/api/v1/remittances/{id}/status` | Polling ligero para la UI |
| POST | `/api/v1/webhooks/payments/{provider}` | Webhook firmado |
| POST | `/api/v1/payment-intents/{id}/refund` | Solo admin |

### 5.4 Confirmación y estados

```
intent: created → awaiting_payment → paid
                              ├→ expired
                              ├→ cancelled
                              └→ failed
remesa: awaiting_payment → compliance_review (solo si paid y conciliado)
```

Validaciones al confirmar: `provider_payment_id` único, importe exacto, moneda, referencia, cuenta destino y que el intento no esté vencido/cancelado. Cualquier desviación → `reconciliation_exception` + revisión manual, sin acreditar.

### 5.5 Sandbox que sí permite pagar

Para que el QR sea realmente escaneable en local:

1. El QR codifica `http://<IP-LAN>:5173/pay/<token>` (token firmado, corto, de un solo uso).
2. Esa ruta abre una página con importe, referencia, cuenta y botón **Confirmar pago (sandbox)**.
3. El botón llama al endpoint de confirmación; la remesa avanza a `compliance_review`.
4. La página muestra el resultado y la UI de la remesa se actualiza por polling.

Configurar `SEDERG_PUBLIC_BASE_URL` (o `VITE_PUBLIC_BASE_URL`) para que el QR use la IP de la LAN y no `localhost`, de modo que un teléfono pueda escanearlo.

### 5.6 UI objetivo

- Un solo paso de pago tras crear la remesa: método → QR/datos → estado.
- Temporizador de vencimiento y botón **Generar nuevo QR**.
- Botones **Copiar importe / Copiar referencia / Copiar cuenta**, y **Abrir app del banco** (deep link cuando exista).
- Estado en vivo: `Esperando pago` → `Pago recibido, en revisión` → `Aprobado` → `Enviado`.
- Mensaje explícito del tipo de QR (pagable vs informativo).

---

## 6. Plan de implementación

### Fase 1 — P0 (local usable)

1. `PaymentIntent` mínimo reutilizando `deposit_requests` + `qr_kind`.
2. Página `/pay/:token` y QR con URL de LAN.
3. Botón de confirmación sandbox + polling de estado.
4. Clave de idempotencia por intento y bloqueo `FOR UPDATE` de la remesa.
5. Comando `payments/expire` y reintento de QR.
6. Pruebas de integración: crear → escanear (URL) → confirmar → `compliance_review`; expiración; doble confirmación; dos creaciones concurrentes.

### Fase 2 — P1 (piloto)

7. Webhook firmado + deduplicación con `bank_webhook_events`.
8. Validación estricta de importe/moneda/referencia y casos de conciliación.
9. Cancelación, reverso y asientos compensatorios.
10. Reserva de liquidez en payout y bloqueo por saldo.
11. Notificaciones por cambio de estado.

### Fase 3 — P2 (producción)

12. `SbpAcquirerRail` real con el anexo técnico del banco.
13. QR EMVCo/EPC para otros corredores.
14. Antifraude, límites de velocidad, screening y observabilidad.
15. Runbook de incidentes y conciliación diaria.

---

## 7. Ramas

Ramas locales existentes (verificadas en `.git/refs/heads`):

| Rama | Uso |
|---|---|
| `main` | línea principal |
| `feat/ballerine-kyc-core` | **actual**, trabajo KYC sin commitear |
| `fix/remittance-flow-integrity` | correcciones del flujo de remesas |
| `feature/admin-exchange-rate-source` | fuente de tasa administrable |
| `feature/payment-methods-and-funding-accounts-20261103` | **métodos de pago y cuentas de fondeo** (commit `44d07be`) |

**Sí existe rama de métodos de pago**: `feature/payment-methods-and-funding-accounts-20261103`. Su trabajo (migración `m261103_000014`, `FundingController`, panel admin y proveedores sandbox) ya está presente en el árbol actual, por lo que no hace falta crear otra rama para ese fin.

Recomendación: mantener esa rama como base histórica y crear una rama nueva para esta iniciativa, para no mezclarla con KYC:

```powershell
git switch main
git pull --ff-only
git switch -c feat/payments-qr-orchestration
```

Si se prefiere continuar sobre la rama existente:

```powershell
git switch feature/payment-methods-and-funding-accounts-20261103
```

---

## 8. Decisiones pendientes

1. ¿El fondeo RU se hará por SBP C2B (requiere adquirencia) o por transferencia a cuenta con referencia?
2. ¿Qué banco adquirente y qué producto exacto (QR dinámico, estático, API de estado)?
3. ¿Quién opera la entrega en Cuba y con qué evidencia de pago?
4. ¿Se admite pago parcial/excedente o se rechaza?
5. ¿Plazo máximo de espera antes de cancelar la remesa y devolver?
6. ¿Se expondrá una página de pago propia (L3) en producción o solo QR del proveedor?

Ninguna de estas decisiones requiere cambiar la arquitectura propuesta; solo parametrizan el adaptador.
