# Integración con Sber API — diseño para SEDERG_PAY (RUB)

**Estado:** DISEÑO / ANÁLISIS TÉCNICO. No habilita cobros ni desembolsos reales. Complementa `backend/docs/payment-provider-integration-readiness.md`.

**Fuentes:** [developers.sber.ru — Sber API](https://developers.sber.ru/portal/products/sber-api), documentación oficial (consultada 2026-11-12).

---

## 1. Alcance acordado (lo que sí integraremos con Sber)

Según tu indicación, **Sber se usa únicamente para la pata RUB**:

1. **Recibir pagos** (fondeo: el cliente paga a SEDERG_PAY en RUB).
2. **Hacer pagos a clientes rusos** (desembolso: enviamos RUB al beneficiario/destinatario en Rusia).
3. **Verificar que el pago se hizo y se recibió correctamente** (conciliación + webhooks).

La pata CUP (Cuba) se mantiene por el canal ya existente (operador local / efectivo / transferencia). El circuito RUB/CUP, la liquidez y el FX siguen siendo problema de operación y no de esta integración.

## 2. Qué набор (set) de Sber API elegir

Sber API se vende por "наборы" (sets). Sólo uno tiene webhooks:

| Set | Webhooks | Платёжные поручения | Выписки | СБП | Cuándo usar |
|---|---|---|---|---|---|
| **Компаниям** (Host2Host) | ✅ | ✅ | ✅ | ✅ | **El que necesitamos** — única opción con webhooks. |
| Холдингам | ✅ | ✅ | ✅ | ✅ | Si SEDERG_PAY tuviera filiales (no aplica ahora). |
| Платформам (B2BSaaS) | ❌ | ❌ (solo ссылки СБП B2B) | ❌ | ✅ (B2B/B2C) | Para el escenario de "ссылка СБП B2B" (cobro por link). |
| Моментальные платежи | ❌ | ❌ (черновики РПП) | ❌ | ❌ | Sólo si queremos que el cliente firme por QR/SMS. |
| СберБизнес ID | ❌ | ❌ | ❌ | ❌ | Sólo autorización.

**Decisión:** contratar el set **"Компаниям"** (el único con webhooks). Si más adelante queremos cobrar con links СБП B2B, añadimos el set "Платформам" (es compatible, ambos se pueden contratar a la vez).

## 3. Cómo se mapea Sber API a nuestro modelo de negocio

Nuestro flujo ya está modelado (ver `SimulatedPaymentRepository`, `bank_connections`, `deposit_requests`, `bank_transactions`, `bank_webhook_events`, `bank_sync_cursors`). Esa abstracción es, literalmente, el adaptador de Sber.

| Leg de SEDERG_PAY | Sber API | Recurso / método | Scope |
|---|---|---|---|
| **Recibir** (fondeo RUB del cliente) | Платёжные поручения (el cliente nos paga) — o mejor, СБП por número de teléfono / QR | Cliente paga a nuestras requisites → **Выписки** (`GET /v2/statement/transactions`, `/increment`) | `GET_STATEMENT_ACCOUNT` |
| **Recibir** (alternativa determinística) | СБП B2B link (Платформам set) | `POST /v1/payment-link/sbp-b-2-b-link-create` + `GET /.../transaction-list` | `PAYMENT_LINK` |
| **Pagar a clientes rusos** | Платёжное поручение RUB | `POST /v1/payments` (scope `PAY_DOC_RU`) | `PAY_DOC_RU` |
| **Pagar a clientes rusos (individual, tarjeta)** | СБП B2C (platform set) | `POST /v1/transfer/sbp/b-2-c-execute` + `GET .../status` | `SBP_B2C` |
| **Verificar** (push) | Вебхук: "Уведомление о финальном статусе РПП" | `POST` a nuestro endpoint público | — |
| **Verificar** (pull / backstop) | Статус РПП | `GET /v1/payments/{externalId}/state` | `PAY_DOC_RU` |
| **Verificar** (extracto) | Выписки | `GET /v2/statement/transactions`, `/increment`, `/summary` | `GET_STATEMENT_ACCOUNT` |
| Referencia | Справочники (БИК, валюты, страны) | `GET /v1/dicts/...` | `GET_DICTIONARIES` |
| Contrapartes | Контрагенты | `GET /v1/correspondents/...` | `GET_CORRESPONDENTS` |

**Recomendación principal:**
- **Recibir pagos → Выписки (pull) + opcionalmente СБП link/QR.** Sber no documenta un webhook de "crédito entrante"; el webhook disponible es solo para el estado final de un РПП (es decir, de un pago que *nosotros* iniciamos). Por eso la conciliación de entradas se hace por extracto incremental (`/v2/statement/increment`), con `payment_reference` + `amount` + `currency` como clave de cruce.
- **Pagar a clientes rusos → РПП RUB con ЭП (digestSignatures)**, para que el banco lo ejecute sin intervención humana. Sin ЭП, el documento queda en `CREATED` y alguien debe firmarlo en СберБизнес → no escala.
- **Verificar → webhook (push) para los pagos que nosotros iniciamos + Выписки (pull) para todo lo demás + `GET .../state` como fallback** si no llega el webhook en 24 h.

## 4. Mapeo a nuestro código (ya preparado)

La capa de pagos ya está abstraída. Lo que falta es el adaptador `SberBankProvider`:

| Componente existente | Reutilización |
|---|---|
| `bank_connections` (`bank_code`, `integration_mode`, `account_token`, `account_mask`, `account_currency`, `country_id`, `configuration` JSON, `environment`) | Añadir fila `bank_code='sber'`, `integration_mode='api'`, `environment='sandbox'/'production'`, con `configuration` conteniendo `client_id`, `client_secret`, `cert_path`, `accounts[]`. |
| `deposit_requests` (`payment_reference` UNIQUE, `expected_amount`, `currency_code`, `status`, `matched_transaction_id`, `confirmed_at`) | Reutilizado tal cual para la pata de entrada. |
| `bank_transactions` (`provider_transaction_id` UNIQUE, `payment_reference`, `amount`, `currency_code`, `booked_at`, `provider_status`) | Reutilizado para las líneas del extracto. |
| `bank_webhook_events` (`provider_event_id` UNIQUE, `event_type`, `signature_valid`, `payload`, `processing_status`, `attempts`) | Reutilizado para el webhook de Sber. |
| `bank_sync_cursors` (`stream_name`, `cursor_value`, `last_synced_at`) | Reutilizado para el cursor incremental del extracto. |
| `reconciliation_cases` (`bank_transaction_id`) | Reutilizado para entradas no conciliadas (revisión manual). |
| `wallet_ledger_entries` (`bank_transaction_id`) | Reutilizado: el crédito se asienta cuando el depósito queda `confirmed` y conciliado. |
| `payout_orders` / `payout_attempts` | Reutilizado para los pagos salientes. |
| `SimulatedPaymentRepository` (`bank_code = 'simulator'`) | Patrón a copiar: `SberPaymentRepository` con `bank_code = 'sber'`. |
| Endpoints existentes: `POST /api/v1/remittances/{id}/deposit`, `/simulate-confirmation`, `/payout`, `/simulate-completion`, `/reconcile` | Cambiar `simulate-*` por adaptadores reales; los endpoints públicos no cambian. |
| Admin: `funding-accounts`, `funding-methods` | Añadir el método `sber` y la cuenta Sber. |

## 5. Flujo operativo detallado

### A. Recibir RUB del cliente (fondeo)

1. El cliente crea la remesa → `POST /api/v1/remittances` → `awaiting_payment`.
2. `POST /api/v1/remittances/{id}/deposit` con `bank_code='sber'` y el `payment_reference` (único, p.ej. `REM-2026...-XXXX`).
3. La plataforma muestra al cliente: **número de cuenta Sber + referencia** (o un QR СБП / link).
4. El cliente paga desde su banco (cualquier banco ruso) por СБП a nuestro número de teléfono o a la cuenta.
5. **Reconciliación**: un job periódico (`bank_sync_cursors` con `stream_name='transactions'`) llama `GET /v2/statement/increment` (o `/transactions` con fecha) → por cada línea:
   - Si `payment_reference` + `amount` + `currency` coinciden con un `deposit_request` pendiente → `matched_transaction_id`, `status=confirmed`, `confirmed_at=now`, y la remesa pasa a `compliance_review` + asiento en `wallet_ledger_entries`.
   - Si no hay match → fila en `reconciliation_cases` (revisión manual).
6. Opcionalmente, si el cliente pagó con una tarjeta Sber a nuestra cuenta, el extracto contiene el `operationId` y la referencia.

### B. Pagar a clientes rusos (desembolso)

1. Remesa en estado `compliance_review` → aprobada → `payout/create` → `payout_order` (status `created`).
2. El adaptador llama `POST /v1/payments` con:
   - `externalId` = nuestro `payout_order.public_id` (UUID),
   - `payerAccount` = nuestra cuenta Sber,
   - `payeeAccount`, `payeeBankBic`, `payeeBankCorrAccount`, `payeeInn` (12 dígitos para física), `payeeName`,
   - `amount`, `currency` (RUB), `purpose` (con `{VOXXXXX}` si el beneficiario es no-residente — no aplica para RUB dentro de Rusia),
   - `digestSignatures` con el certificado de nuestra КЭП (test UUID en sandbox: `bb014b5d-...`) → la firma se calcula sobre el digest (orden de campos fijo).
3. Si firmamos con ЭП: `bankStatus: IMPLEMENTED` inmediato → `payout_attempt.status=completed`, `remittance.status=completed`, asiento de débito.
4. Si no firmamos: `bankStatus: CREATED` (черновик) → un empleado firma en СберБизнес (no recomendado para volumen).
5. **Para individuos (tarjeta/СБП):** usar `POST /v1/transfer/sbp/b-2-c-execute` (СБП B2C, límite 1 000 000 ₽/operación) → más simple que el РПП y sin ЭП por operación (la firma es por contrato de plataforma).

### C. Verificar que el pago se hizo y se recibió

1. **Push (saliente):** el webhook `POST https://5-22-219-30.sslip.io/api/v1/webhooks/sber` recibe el evento `event_type = ...` (final status del РПП). Headers `X-WH-Webhook-Time`, `X-WH-Request-Id`. Validar:
   - mTLS: verificar el certificado del cliente contra la cadena Sber CA (`SberCA_Root_Ext.crt` + `SberCA_Ext.crt`).
   - Dedup por `X-WH-Request-Id` (índice único ya existe en `bank_webhook_events.provider_event_id`).
   - Reglas de dedup del evento: externalId nuevo → aceptar; externalId igual + eventTime más reciente → aceptar; externalId y bankStatus iguales → ignorar.
   - Responder `2xx` en < 60 s.
2. **Pull (entrante + backstop):** el job de conciliación corre cada N minutos: `GET /v2/statement/increment` → upsert en `bank_transactions` → matching → `reconciliation_cases` para los no emparejados.
3. **Fallback:** si pasan 24 h sin webhook para un РПП → `GET /v1/payments/{externalId}/state`.
4. **Audit:** cada evento queda en `bank_webhook_events` + `audit_logs` (acción `bank.webhook.received`).

## 6. Bloqueadores y prerrequisitos (la parte honesta)

1. **Contrato + набор «Компаниям»** (o «Платформам» si usamos links B2B). Sólo se abre con contrato firmado con Sber. Sin contrato no hay producción; el sandbox sí está disponible con una cuenta de prueba.
2. **mTLS**: certificado cliente `.p12` emitido por un УЦ acreditado (НУЦ Минцифры, НСПК) o generado en el ЛК Sber API. El servidor debe presentarlo en cada llamada. Nuestro Caddy actual no hace mTLS; habría que añadir la verificación del cliente (o poner un pequeño proxy).
3. **OAuth 2.0**: `access_token`/`refresh_token` con scopes `openid`, `PAY_DOC_RU`, `GET_STATEMENT_ACCOUNT`, `GET_STATEMENT_TRANSACTION`, `GET_CLIENT_ACCOUNTS` (y `PAYMENT_LINK` si usamos СБП link).
4. **ЭП (КЭП)** de la empresa para firmar automáticamente los РПП (digestSignatures). Sin ella, los pagos quedan en `CREATED` y un humano los firma en СберБизнес → no escala.
5. **ГОСТ crypto**: si elegimos el formato JOSE para los webhooks, necesitamos un proveedor ГОСТ 34.10-2012 en el servidor (no está en nuestra imagen PHP). **Recomendación: elegir el formato JSON plano + mTLS**, evitando ГОСТ en nuestro stack.
6. **Webhook endpoint**: nuestro backend ya es HTTPS público en el 443 con certificado válido ✓ — sólo falta el endpoint y la verificación mTLS del cliente.
7. **Licencia regulatoria**: Sber API no sustituye la licencia de operador de transferencias. SEDERG_PAY debe tener la autorización para captar y pagar en RUB. También aplica el 115-ФЗ (currency control) si se toca el lado ВЭД — pero nosotros nos quedamos en RUB, así que eso no nos afecta.
8. **Sandbox**: `sbi-test.sberbank.ru`, con `certificateUuid` fijos (ver la doc de `create-payment`). Ideal para construir y probar el adaptador antes del contrato.

## 7. Plan de implementación (fases)

### Fase 0 — Preparación (sin contrato, ya)
- [x] Esquema BD ya soporta `bank_connections`, `bank_transactions`, `bank_webhook_events`, `bank_sync_cursors`, `reconciliation_cases`, `wallet_ledger_entries` (migración `m261014_000001`).
- [x] `SimulatedPaymentRepository` como plantilla del adaptador.
- [ ] Crear `backend/src/Infrastructure/Payment/SberBankProvider.php` con la interfaz `BankProviderInterface` (createPayment, getPaymentState, syncStatements, verifyWebhookSignature) — primero contra un mock que responde como Sber, para poder escribir tests.
- [ ] Endpoint `POST /api/v1/webhooks/sber` (controlador `WebhookController`) que valida mTLS, dedupe por `X-WH-Request-Id`, persiste en `bank_webhook_events` y responde 200 en < 60 s.
- [ ] Job de conciliación (console command `sber:sync-statements`) con cursor incremental, idempotente.
- [ ] Pruebas unitarias contra fixtures JSON de Sber (sin red).

### Fase 1 — Sandbox (con cuenta de prueba Sber)
- [ ] Obtener TLS `.p12` de la песочница y configurar mTLS en el cliente HTTP (Guzzle/curl con `CURLOPT_SSLCERT`).
- [ ] Registrar la `bank_connection` sandbox (`environment='sandbox'`, `integration_mode='api'`).
- [ ] Probar end-to-end: crear РПП → webhook `IMPLEMENTED` → conciliación → ledger → remesa `completed`.
- [ ] Probar el flujo СБП B2C (si lo necesitamos para pagos a individuos).

### Fase 2 — Producción (con contrato)
- [ ] Sustituir credenciales sandbox por producción (secret manager).
- [ ] Activar `production_enabled` (el mismo patrón que `SEDERG_KYC_REQUIRED`).
- [ ] Operación controlada (una transacción real) + monitoreo.

## 8. Decisión recomendada (respuesta directa)

**Sí, es factible, y el método más adecuado para nuestro alcance es:**

1. **Set «Компаниям»** (el único con webhooks).
2. **Recibir (fondeo):** el cliente paga por СБП a nuestras requisites (o por link/QR) → confirmamos mediante **Выписки** (`/v2/statement/increment`) + matching por `payment_reference` + `amount`. Webhooks no cubren créditos entrantes, así que el extracto es el mecanismo correcto para esta pata. (Opcional: СБП B2B link por cliente para hacer el match determinista.)
3. **Pagar a clientes rusos (desembolso):** `POST /v1/payments` con ЭП (automatico, `IMPLEMENTED`) o, para individuos, **СБП B2C** (por número de teléfono, límite 1 000 000 ₽, sin ЭП por operación).
4. **Verificar:** webhook push (para lo que nosotros iniciamos) + Выписки pull (para entradas y backstop) + `GET /v1/payments/{externalId}/state` como fallback a las 24 h.

**El mayor ahorro de trabajo es que nuestro esquema (`bank_connections`, `deposit_requests`, `bank_transactions`, `bank_webhook_events`, `bank_sync_cursors`, `reconciliation_cases`, `wallet_ledger_entries`) y la máquina de estados ya están diseñados para esto** — sólo falta el adaptador `SberBankProvider` y el endpoint de webhook.

**El verdadero bloqueador no es técnico: es el contrato con Sber, el TLS cliente (mTLS) emitido por un УЦ ruso, la КЭП para la firma automática y la licencia de operador de transferencias.** Mientras eso no esté, la integración debe correr en sandbox con un adaptador mock y `production_enabled = false` (el mismo patrón que `SEDERG_KYC_REQUIRED`).

## 9. Acciones inmediatas sugeridas

1. Solicitar en SberBusiness la apertura de cuenta y la firma del anexo técnico (set «Компаниям»).
2. Obtener el TLS `.p12` de la песочница y probar `GET /v2/oauth/user-info` con mTLS desde nuestro servidor (UpCloud, es-mad1) — esto valida que el canal funciona end-to-end.
3. Implementar Fase 0 (adaptador mock + webhook endpoint + sync job) con pruebas unitarias, sin red.
4. Confirmar en el ЛК qué eventos de webhook existen para créditos entrantes (si los hay, simplificaría la pata de recepción).
5. Verificar la licencia regulatoria (operador de transferencias) antes de cualquier operación real.
