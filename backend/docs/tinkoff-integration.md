# Integración con Tinkoff Pay (T-Bank) — diseño para SEDERG_PAY (RUB)

**Estado:** DISEÑO / ANÁLISIS TÉCNICO. No habilita cobros ni desembolsos reales. Complementa `backend/docs/payment-provider-integration-readiness.md`.

**Fuentes:** [developer.tbank.ru](https://developer.tbank.ru/), [tinkoff.ru/kassa/develop/api](https://www.tinkoff.ru/kassa/develop/api/) (consultado 2026-11-12).

---

## 1. Por qué Tinkoff Pay en lugar de Sber API

| Aspecto | Sber API | Tinkoff Pay (T-Bank) |
|---|---|---|
| Autenticación | mTLS (certificado `.p12` de un УЦ ruso) + OAuth2 | Terminal key + password (HMAC-SHA256) |
| Certificado de УЦ ruso | **Obligatorio** (НУЦ Минцифры / НСПК) | **No** — T-Bank emite las credenciales |
| ЭП (КЭП) para firmar pagos | **Obligatorio** para РПП automáticos | **No** — la firma es HMAC con el password del terminal |
| ГОСТ crypto | Necesario si se usa JOSE | **No** — HMAC-SHA256 estándar |
| INN en el CSR | **Obligatorio** (campo OU) | No en el CSR (el terminal se emite a la cuenta empresarial) |
| Webhooks | Solo en набор «Компаниям» (mTLS + ГОСТ) | ✅ HTTP POST con firma HMAC-SHA256 (simple) |
| Sandbox | `sbi-test.sberbank.ru` (requiere mTLS) | `developer.tbank.ru` (sin certificado TLS de cliente) |
| Contrato | Anexo técnico + licencia de operador | Contrato estándar de эквайринг/массовые выплаты |

**Conclusión:** Tinkoff Pay elimina los bloqueadores técnicos más duros de Sber (mTLS, ЭП, ГОСТ, УЦ ruso). La integración es **mucho más simple** y se puede prototipar en sandbox en días, no semanas.

**Lo que NO cambia:** sigue necesitando una **persona jurídica rusa (ООО/ИП)** con cuenta en T-Bank y licencia de operador de transferencias. T-Bank facilita la apertura de cuenta (https://www.tbank.ru/business/registration/) pero la licencia regulatoria es responsabilidad de SEDERG_PAY.

---

## 2. Qué métodos usar para nuestro alcance

### A. Recibir pagos (fondeo RUB)

Tinkoff Pay ofrece 3 canales. El más adecuado para nosotros:

| Canal | Método | Cómo funciona | Verificación |
|---|---|---|---|
| **Интернет-эквайринг** (API) | `POST /v2/Init` + `POST /v2/GetState` | Creamos un pago con monto y referencia → el cliente paga en la forma de T-Bank (tarjeta, СБП, T-Pay, Mir Pay) → T-Bank nos notifica por webhook | Webhook `Status` + `GetState` |
| **СБП по QR-коду** | `POST /v2/Init` (с `PayType: "O"`) | Generamos un QR dinámico → el cliente escanea desde su app bancaria → pago instantáneo | Webhook + `GetState` |
| **Платежи по ссылке** | Crear link en el ЛК T-Бизнес | Enviamos un link al cliente → paga en la forma de T-Bank | Webhook + `GetState` |

**Recomendación:** usar **Интернет-эквайринг** (API) con `Init` → `GetState` → webhook. Es el más flexible y se integra con nuestro flujo existente (deposit request → confirmación → ledger). El СБП QR es un complemento excelente para pagos rápidos.

### B. Pagar a clientes rusos (desembolso)

Tinkoff Pay ofrece **Массовые выплаты** (mass payouts) — pagos a tarjetas/cuentas de individuos:

- API de выплаты: https://www.tbank.ru/kassa/dev/payouts/
- Permite enviar dinero a tarjetas bancarias y cuentas de individuos.
- Webhook de notificación de estado de cada pago.
- Límites y tarifas según el contrato.

**Recomendación:** usar **Массовые выплаты** para pagar a beneficiarios rusos (tarjeta o cuenta). Es más simple que un РПП de Sber (no necesita ЭП) y el webhook notifica el resultado.

### C. Verificar que el pago se hizo y se recibió

| Mecanismo | Tinkoff Pay | Sber API |
|---|---|---|
| **Webhook (push)** | ✅ POST a nuestro endpoint con firma HMAC-SHA256. Eventos: `Status` (succeeded/failed/canceled) | Solo para РПП final status |
| **Polling (pull)** | `POST /v2/GetState` por `PaymentId` | `GET /v1/payments/{externalId}/state` |
| **Extracto** | No hay endpoint de extracto en la API de эквайринг; se puede consultar en el ЛК | `GET /v2/statement/transactions` |
| **Reconciliación** | Webhook + `GetState` + matching por `OrderId` | Webhook + Выписки |

**Recomendación:** usar **webhook + `GetState`** para verificar cada pago. Tinkoff notifica en tiempo real el estado final de cada pago, lo que cubre nuestro requisito "verificar si el pago se hizo y se recibió".

---

## 3. Mapeo a nuestro código (ya preparado)

La misma abstracción que diseñamos para Sber sirve para Tinkoff:

| Componente existente | Reutilización con Tinkoff |
|---|---|
| `bank_connections` (`bank_code`, `integration_mode`, `account_token`, `configuration` JSON) | Añadir fila `bank_code='tinkoff'`, `integration_mode='api'`, con `configuration` conteniendo `terminal_key`, `password`, `webhook_url` |
| `deposit_requests` (`payment_reference` UNIQUE, `expected_amount`, `status`, `matched_transaction_id`) | Reutilizado tal cual para la pata de entrada |
| `bank_transactions` (`provider_transaction_id`, `payment_reference`, `amount`, `currency_code`, `provider_status`) | Reutilizado para las notificaciones webhook |
| `bank_webhook_events` (`provider_event_id` UNIQUE, `event_type`, `signature_valid`, `payload`, `processing_status`) | Reutilizado para los webhooks de Tinkoff |
| `reconciliation_cases` | Reutilizado para pagos no emparejados |
| `wallet_ledger_entries` | Reutilizado: crédito se asienta cuando el depósito queda `confirmed` |
| `payout_orders` / `payout_attempts` | Reutilizado para los pagos salientes |
| `FundingPaymentProviderFactory` | Añadir `'tinkoff' => new TinkoffPaymentProvider()` |
| Endpoints existentes: `deposit`, `payout`, `reconcile` | Cambiar `simulate-*` por adaptadores reales |

**Ventaja clave:** Tinkoff no requiere mTLS, ЭП, ni certificados de УЦ ruso. La autenticación es un simple HMAC-SHA256 con el password del terminal. Esto simplifica enormemente el código del adaptador.

---

## 4. Flujo operativo detallado

### A. Recibir pagos (fondeo)

1. El cliente crea la remesa → `POST /api/v1/remittances` → `awaiting_payment`.
2. `POST /api/v1/remittances/{id}/deposit` con `bank_code='tinkoff'` y `payment_reference` (único, p.ej. `REM-2026...-XXXX`).
3. El adaptador llama `POST /v2/Init` con:
   - `TerminalKey`, `Amount` (en kopeks), `OrderId` (= `payment_reference`), `Description`, `SuccessURL`, `FailURL`, `NotificationURL` (nuestro webhook)
   - T-Bank devuelve `PaymentId` y `PaymentURL` (forma de pago).
4. La plataforma muestra al cliente el link/QR de pago.
5. El cliente paga (tarjeta, СБП, T-Pay, Mir Pay).
6. **T-Bank envía webhook** `POST https://5-22-219-30.sslip.io/api/v1/webhooks/tinkoff` con `Status: "CONFIRMED"` (o `REJECTED`, `CANCELED`, `REFUNDED`).
7. El webhook handler verifica la firma HMAC-SHA256, persiste en `bank_webhook_events`, y si `Status == "CONFIRMED"` → `deposit_request.status = 'confirmed'`, `matched_transaction_id`, `confirmed_at = now`, la remesa pasa a `compliance_review` + asiento en `wallet_ledger_entries`.
8. **Backstop:** si no llega webhook en 5 minutos → `POST /v2/GetState` por `PaymentId`.

### B. Pagar a clientes rusos (desembolso)

1. Remesa en estado `compliance_review` → aprobada → `payout/create` → `payout_order` (status `created`).
2. El adaptador llama la API de **Массовые выплаты** (https://www.tbank.ru/kassa/dev/payouts/) con:
   - `TerminalKey`, `OrderId` (= `payout_order.public_id`), `Amount`, `RecipientCard` (o `RecipientAccount`), `Description`
   - T-Bank devuelve `PaymentId`.
3. T-Bank procesa el pago → webhook `Status: "CONFIRMED"` (o `REJECTED`).
4. Si confirmado → `payout_attempt.status = 'completed'`, `remittance.status = 'completed'`, asiento de débito en el ledger.
5. Si rechazado → `payout_attempt.status = 'failed'`, la remesa vuelve a `compliance_review` para revisión manual.

### C. Verificar que el pago se hizo y se recibió

| Mecanismo | Detalle |
|---|---|
| **Webhook (push)** | T-Bank envía `POST` con firma HMAC-SHA256 (Token = HMAC-SHA256 de los parámetros ordenados + password del terminal). Responder `200 OK` en < 60 s. |
| **Polling (pull)** | `POST /v2/GetState` por `PaymentId` — para verificar el estado on-demand. |
| **Reconciliación** | Webhook + `GetState` + matching por `OrderId` contra `deposit_requests.payment_reference`. |
| **Auditoría** | Cada evento en `bank_webhook_events` + `audit_logs`. |

**Dedup:** T-Bank puede enviar el mismo webhook varias veces. Usar `X-...` o el `PaymentId` + `Status` como clave de dedup en `bank_webhook_events.provider_event_id`.

---

## 5. Seguridad del webhook (HMAC-SHA256)

Tinkoff Pay usa un mecanismo mucho más simple que Sber:

1. T-Bank envía un POST con un campo `Token` en el cuerpo.
2. Para verificar: ordenar alfabéticamente todos los parámetros del cuerpo (excepto `Token` y `Receipt`), concatenar sus valores + el password del terminal, calcular SHA-256 → comparar con `Token`.
3. Si coinciden → el webhook es legítimo.

**No se necesita mTLS ni certificados de УЦ.** Solo el password del terminal (que ya tenemos en las credenciales).

Esto se implementa en PHP con `hash_hmac('sha256', $concatenated, $password)`.

---

## 6. Bloqueadores y prerrequisitos

### Lo que ya NO es bloqueador (vs Sber)

| Bloqueador de Sber | Tinkoff Pay |
|---|---|
| mTLS (certificado `.p12` de УЦ ruso) | ❌ No necesario |
| ЭП (КЭП) para firmar pagos | ❌ No necesario |
| ГОСТ crypto (ГОСТ 34.10-2012) | ❌ No necesario (HMAC-SHA256 estándar) |
| INN en el CSR | ❌ No necesario para el API (el terminal se emite a la cuenta empresarial) |
| Contrato complejo con anexo técnico | ✅ Contrato estándar de эквайринг/массовые выплаты |

### Lo que sigue siendo necesario

1. **Persona jurídica rusa (ООО/ИП)** con cuenta en T-Bank. T-Bank facilita la apertura de cuenta (https://www.tbank.ru/business/registration/) y puede ayudar con el registro.
2. **Terminal key + password** — se emiten en el ЛК T-Бизнес tras abrir la cuenta.
3. **Licencia de operador de transferencias** (ЦБ РФ) — igual que con Sber. Sin esto, no se puede operar remesas.
4. **Sandbox** — disponible en https://developer.tbank.ru/ sin certificado TLS de cliente. Se puede probar con credenciales de prueba.

---

## 7. Plan de implementación (fases)

### Fase 0 — Preparación (sin contrato, ya)
- [x] Esquema BD ya soporta `bank_connections`, `deposit_requests`, `bank_transactions`, `bank_webhook_events`, `bank_sync_cursors`, `reconciliation_cases`, `wallet_ledger_entries`.
- [x] `SimulatedPaymentRepository` como plantilla del adaptador.
- [x] `FundingPaymentProviderFactory` con `production_enabled` bloqueado hasta validar el contrato.
- [ ] Crear `backend/src/Infrastructure/Payment/TinkoffPaymentProvider.php` — adaptador HTTP con HMAC-SHA256.
- [ ] Endpoint `POST /api/v1/webhooks/tinkoff` (controlador `WebhookController`) que verifica la firma, persiste en `bank_webhook_events`, responde 200.
- [ ] Pruebas unitarias contra fixtures JSON de Tinkoff (sin red).

### Fase 1 — Sandbox (con cuenta de prueba T-Bank)
- [ ] Obtener terminal key + password de prueba en https://developer.tbank.ru/.
- [ ] Registrar la `bank_connection` sandbox (`bank_code='tinkoff'`, `environment='sandbox'`, `integration_mode='api'`).
- [ ] Probar end-to-end: crear pago → webhook CONFIRMED → conciliación → ledger → remesa `completed`.
- [ ] Probar payout a individuo (массовые выплаты).

### Fase 2 — Producción (con cuenta real T-Bank)
- [ ] Sustituir credenciales sandbox por producción (secret manager).
- [ ] Activar `production_enabled` (el mismo patrón que `SEDERG_KYC_REQUIRED`).
- [ ] Operación controlada (una transacción real) + monitoreo.

---

## 8. Decisión recomendada

**Tinkoff Pay es significativamente más factible que Sber API para nuestro alcance.**

Razones:
1. **Sin mTLS, sin ЭП, sin ГОСТ, sin УЦ ruso** — la integración es un cliente HTTP estándar con HMAC-SHA256.
2. **Sandbox real** sin certificados de cliente — se puede probar hoy.
3. **Webhooks simples** con firma HMAC — más fácil de implementar y verificar.
4. **Массовые выплаты** — pagos a individuos por tarjeta/cuenta sin РПП.
5. **Документация en ruso e inglés** con ejemplos de código.

**El verdadero bloqueador sigue siendo el mismo:** la licencia de operador de transferencias y la persona jurídica rusa. Pero Tinkoff facilita la apertura de cuenta y no exige certificados de УЦ ruso, lo que reduce el tiempo de implementación de semanas a días.

**Acción inmediata sugerida:**
1. Abrir una cuenta en T-Bank para negocio (https://www.tbank.ru/business/registration/) — T-Bank puede ayudar con el registro de la ООО/ИП.
2. Obtener las credenciales de sandbox en https://developer.tbank.ru/.
3. Implementar Fase 0 (adaptador mock + webhook + sync job) con pruebas unitarias.
4. Verificar la licencia de operador de transferencias (ЦБ РФ: https://www.cbr.ru/psystems/reestropers/) antes de cualquier operación real.

## 9. Referencias

- Documentación API: https://developer.tbank.ru/
- Internet-эквайринг: https://www.tinkoff.ru/kassa/develop/api/
- Массовые выплаты: https://www.tbank.ru/kassa/dev/payouts/
- СБП: https://www.tbank.ru/kassa/dev/sbp/
- Webhooks: https://developer.tbank.ru/eacq/notifications
- Sandbox: https://developer.tbank.ru/eacq/intro/connection
- T-Bank для бизнеса: https://www.tbank.ru/business/
- Регистрация бизнеса: https://www.tbank.ru/business/registration/
- Лицензия ЦБ РФ (операторы переводов): https://www.cbr.ru/psystems/reestropers/
