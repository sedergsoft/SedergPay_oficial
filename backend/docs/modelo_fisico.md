# Modelo físico de datos — SEDERG_PAY

**Motor:** PostgreSQL 17 · **Framework:** Yii2 · **Codificación:** UTF-8  
**Convención:** `id` es PK numérica interna; `public_id` es identificador público no secuencial. Todos los importes usan `DECIMAL`, nunca coma flotante.

## Diccionario de tablas

| Tabla | Propósito y campos principales | PK | FKs e índices relevantes |
|---|---|---|---|
| `countries` | `id SMALLINT`, `iso2 CHAR(2)`, `iso3 CHAR(3)`, `name VARCHAR(100)`, `phone_code VARCHAR(8)`, `is_active BOOLEAN`, `created_at DATETIME` | `id` | `UNIQUE(iso2)`, `UNIQUE(iso3)` |
| `currencies` | `code CHAR(3)`, `name VARCHAR(60)`, `minor_units SMALLINT`, `is_active BOOLEAN`, `created_at DATETIME` | `code` | PK por código ISO |
| `users` | `id BIGINT`, `public_id CHAR(36)`, `email VARCHAR(190) NULL`, `phone_e164 VARCHAR(20) NULL`, `password_hash VARCHAR(255)`, `status VARCHAR(20)`, `preferred_language VARCHAR(10)`, `country_id SMALLINT NULL`, verificaciones, último acceso, fechas y borrado lógico | `id` | `UNIQUE(public_id/email/phone_e164)`, FK país; índices por estado/fecha |
| `user_profiles` | `user_id BIGINT`, nombre, apellidos, segundo nombre, fecha nacimiento, país residencia, dirección, ciudad, código postal y fechas | `user_id` | FK a usuario `CASCADE`; FK país `SET NULL` |
| `beneficiaries` | `id`, `public_id`, `sender_user_id`, `country_id`, nombre/apellidos, teléfono/email, `payout_method_code`, proveedor, token de cuenta, últimos 4 dígitos, estado y fechas | `id` | `UNIQUE(public_id)`, índice remitente/estado; FKs a usuario y país |
| `payment_methods` | `code VARCHAR(30)`, nombre, país opcional, dirección (`inbound/outbound/both`), si requiere cuenta, estado, configuración JSON y fecha | `code` | FK país; catálogo |
| `exchange_rates` | `id`, monedas base/cotizada, `rate DECIMAL(20,10)`, fuente, vigencia desde/hasta, usuario creador y fecha | `id` | FKs a monedas y creador; índice par/vigencia; `CHECK(rate > 0)` |
| `remittance_quotes` | `id`, `public_id`, usuario, países origen/destino, importe enviado/recibido, monedas, tasa, comisión, moneda comisión, total debitado, modalidad de pago, estado, vencimiento y fecha | `id` | `UNIQUE(public_id)`; FKs a usuario, países, monedas y modalidad; `CHECK` de importes |
| `remittances` | `id`, `public_id`, `reference`, remitente, beneficiario, cotización opcional, dirección, países, importes, monedas, tasa, comisión, total, estado, estado de cumplimiento, fechas de pago/completado/cancelación, código de fallo, metadata JSON y fechas | `id` | `UNIQUE(public_id/reference)`; FKs a remitente, beneficiario, cotización, países y monedas; índices por remitente, estado y beneficiario; `CHECK` importes/dirección |
| `compliance_checks` | `id`, remesa, tipo de control, proveedor, estado, riesgo, referencia externa, resumen, fecha de verificación y creación | `id` | FK remesa; índices por remesa/tipo y estado/riesgo |
| `bank_connections` | `id`, `bank_code` (`sberbank`, `vtb`, `alfa`), nombre conexión, entorno, token/referencia interna de cuenta, máscara, moneda, país, modo de integración (`api/host_to_host/statement_import`), estado, último éxito/error, configuración JSON y fechas | `id` | `UNIQUE(bank_code, account_token, environment)`; FK moneda/país; índice banco/estado |
| `deposit_requests` | `id`, `public_id`, remesa, conexión bancaria, importe esperado, moneda, referencia de pago única, nombre pagador, estado, vencimiento, movimiento bancario coincidente opcional, confirmación y fechas | `id` | `UNIQUE(public_id/payment_reference)`; FKs a remesa, conexión, moneda y movimiento; índices estado/vencimiento y remesa |
| `bank_webhook_events` | `id`, conexión, ID de evento del proveedor, tipo, firma válida, payload JSON, estado de procesamiento, intentos, error, recepción y procesamiento | `id` | `UNIQUE(bank_connection_id, provider_event_id)`; FK conexión; índice de cola de procesamiento |
| `bank_transactions` | `id`, conexión, ID de movimiento del proveedor, tipo, importe, moneda, contraparte, cuenta enmascarada, referencia, descripción, fecha contable/valor, estado proveedor, payload JSON, fuente y creación | `id` | `UNIQUE(bank_connection_id, provider_transaction_id)`; FK conexión/moneda; índices por fecha y referencia/importe/moneda; `CHECK(amount <> 0)` |
| `reconciliation_cases` | `id`, movimiento, solicitud de depósito opcional, remesa opcional, tipo de caso, estado, puntuación de coincidencia, código/notas de resolución, usuario resolutor y fechas | `id` | FKs a movimiento, depósito, remesa y usuario; índices por estado y depósito |
| `wallets` | `id`, usuario propietario opcional, tipo, moneda, estado y fechas | `id` | FK usuario/moneda; índice propietario/moneda |
| `wallet_ledger_entries` | `id`, billetera, remesa opcional, movimiento bancario opcional, tipo de asiento, dirección, importe, moneda, clave de idempotencia, descripción y fecha | `id` | `UNIQUE(idempotency_key)`; FKs a billetera, remesa, movimiento y moneda; índices por billetera/fecha y remesa; `CHECK` de importe y dirección |
| `payout_orders` | `id`, `public_id`, remesa, beneficiario, modalidad, proveedor, importe, moneda, estado, referencia externa, solicitud/pago, error y fechas | `id` | `UNIQUE(public_id)`; `UNIQUE(provider, provider_reference)`; FKs a remesa, beneficiario, modalidad y moneda; índice estado/fecha |
| `payout_attempts` | `id`, orden de pago, número de intento, ID petición proveedor, estado, payload solicitud/respuesta JSON, error y fechas | `id` | `UNIQUE(payout_order_id, attempt_no)`; FK orden; índice estado/fecha |
| `bank_sync_cursors` | `id`, conexión, flujo, cursor, última sincronización/operación, estado, error y actualización | `id` | `UNIQUE(bank_connection_id, stream_name)`; FK conexión |
| `idempotency_keys` | `id`, ámbito, clave, hash de solicitud, código y cuerpo de respuesta, tipo/ID recurso, expiración y creación | `id` | `UNIQUE(scope, idempotency_key)`; índice expiración |
| `audit_logs` | `id`, usuario actor opcional, acción, tipo/ID entidad, IP, agente, correlación, datos anteriores/posteriores JSON y fecha | `id` | FK usuario; índices entidad/fecha, actor/fecha y correlación |

## Tipos, claves y restricciones

- **PK:** todas las claves numéricas `id` son `BIGSERIAL` (secuencia propia), excepto `countries.id` (`SMALLINT GENERATED BY DEFAULT AS IDENTITY`) y las claves naturales `currencies.code`, `payment_methods.code` y `platform_settings.setting_key`.
- **FK:** definidas en la migración, con `RESTRICT` para impedir borrar datos financieros referenciados, `CASCADE` para datos subordinados y `SET NULL` para referencias opcionales.
- **Importes:** `DECIMAL(20,2)`. **Tasas:** `DECIMAL(20,10)`. **JSON:** payloads, configuraciones y metadata.
- **Restricciones CHECK:** tasas e importes positivos, importe bancario distinto de cero, dirección contable válida, dirección de remesa válida y entorno bancario permitido.
- **Unicidad e idempotencia:** IDs públicos, referencias de remesa/depósito, IDs externos bancarios, eventos webhook, intentos de pago, claves de libro y claves de idempotencia.
- **Estados:** almacenados como cadenas para permitir evolución. La aplicación debe validar transiciones de estado explícitamente.

## Diagrama lógico resumido

```text
users ──< beneficiaries ──< remittances >── remittance_quotes
  │                              │
  ├──< user_profiles             ├──< compliance_checks
  └──< remittance_quotes         ├──< deposit_requests >── bank_connections
                                 │          │                     ├──< bank_webhook_events
                                 │          └── bank_transactions <┤
                                 │                    │            └──< bank_sync_cursors
                                 │                    └──< reconciliation_cases
                                 ├──< payout_orders ──< payout_attempts
                                 └──< wallet_ledger_entries >── wallets

currencies ──< exchange_rates / remittance_quotes / remittances /
               bank_transactions / wallets / payout_orders / ledger
audit_logs, idempotency_keys: soporte transversal
```

## Decisiones pendientes de negocio
1. Determinar moneda y base exacta de los límites por operación; los límites no se codifican arbitrariamente en la base.
2. Definir los métodos de desembolso realmente disponibles y legales en Cuba y los canales de fondeo disponibles en Rusia.
3. Definir la política de devoluciones, reversos, transferencias parciales y pagos duplicados.
4. Definir reglas de comisiones, límites por usuario, liquidación y conciliación.
5. Confirmar por contrato qué API o canal técnico ofrece cada banco a esta empresa.
