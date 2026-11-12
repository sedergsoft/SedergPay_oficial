# Flujo personal por QR — СБП (C2P) para SEDERG_PAY

**Estado:** IMPLEMENTADO (sandbox) — `backend/src/Infrastructure/Payment/SbpPersonalQrProvider.php`,
método de financiación `ru_sbp_c2p`, rama en `FundingController::actionCreateDeposit`.

Este documento complementa a `backend/docs/tinkoff-integration.md` y a `backend/docs/sber-integration.md`
para el caso de **reparto personal entre individuos** (p. ej. un compañero de piso que reembolsa un préstamo
entre personas) sin empresa registrada en Rusia (sin INN / SNILS / cuenta jurídica).

## 1. Por qué el QR personal (C2P) es la vía viable sin INN

- Los métodos de pago con **acquiring de empresa** (СБП C2B, YooKassa/Tinkoff/CloudPayments como pagadores
  comerciales) requieren titularidad jurídica rusa y sus requisitos (INN, contrato de operación).
- **СБП (Sistema быстрых платежей) también soporta transferencias personales** (persona a persona): el QR
  lleva el identificador del beneficiario (teléfono), el monto, la finalidad y la referencia de la operación.
- Al escanear el QR con la app del banco emisor (Sberbank, Tinkoff, Alfa, VTB, ...), el banco abre la pantalla
  de transferencia con el monto, el destino y la referencia ya pre-cargados.

## 2. Flujo programado

1. El usuario crea una remesa en estado `awaiting_payment` (préstamo personal pendiente de reembolso).
2. Abre el modal de pago y selecciona el método de financiación **`ru_sbp_c2p`** («СБП — QR persona a persona (sandbox)»).
3. `POST /api/v1/remittances/{public_id}/deposit` recibe `method_code: "ru_sbp_c2p"` junto con
   `payee_phone_e164` (teléfono del receptor en dígitos E.164) y `purpose` (finalidad, máx. 160 caracteres).
4. `SbpPersonalQrProvider::createPersonalQr()` genera el payload:
   `sedergpay-sandbox://sbp-c2p?phone=<digits>&amount=<amount>&currency=RUB&purpose=<...>&reference=<...>`
   con `provider_payment_id = SBP-C2P-<hex>` y `expires_at` (+24h, igual que el resto de instrucciones).
5. El frontend renderiza el QR con el componente existente `PaymentQr` (`qrcode` → `toDataURL`).
6. El pagador escanea el QR → la app de su banco pre-carga monto/destino/referencia → confirma la transferencia.
7. El webhook/confirmación del rail o el reembolso manual (`SimulatedPaymentRepository::confirmDeposit`) cierran
   la remesa hacia `compliance_review`.

## 3. Semántica del QR C2P

| Campo | Contenido |
|---|---|
| `phone` | Teléfono del beneficiario (identificador СБП, dígitos, 8–15) |
| `amount` | Importe en RUB con hasta 2 decimales |
| `currency` | Siempre `RUB` |
| `purpose` | Finalidad del pago (p. ej. «Préstamo personal a compañero») |
| `reference` | Referencia de pago de la plataforma (`PAY-...` o `REM-...`) |

## 4. Estado y límites

- Método `ru_sbp_c2p` con `is_active = false` (activación vía panel admin: `PUT /api/v1/admin/funding-methods/ru_sbp_c2p`).
- Solo `sandbox` (`environment`); producción queda bloqueada hasta validar el contrato/acquirer.
- No se registra cuenta receptora: el rail C2P identifica al beneficiario por teléfono, por lo que no hace falta
  cuenta bancaria propia para emitir la instrucción.

## 5. Pendiente para producción

- Contrato con el rail/acquirer que emita los QR C2P reales (Sber/Tinkoff/YooKassa) y credenciales.
- Conversión del sandbox (`sedergpay-sandbox://sbp-c2p?...`) al payload aceptado por el rail emisor.
- Webhook de confirmación del pago del pagador y conciliación contra `deposit_requests`.
