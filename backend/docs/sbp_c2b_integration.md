# Integración de СБП (Sistema de pagos rápidos) — cobros C2B en Rusia

## Decisión
Incorporar СБП / SBP C2B (Customer-to-Business) como método de fondeo para remesas cuyo origen sea Rusia y cuya moneda de pago sea RUB. No confundirlo con transferencias C2C por número de teléfono: SEDERG_PAY necesita contratar recepción comercial C2B mediante un banco participante.

## Flujo funcional
1. El cliente crea una remesa con origen Rusia y elige «СБП — pago por QR».
2. El backend selecciona una cuenta receptora activa de SEDERG_PAY y una conexión bancaria/adquirente habilitada para SBP C2B.
3. El adaptador solicita al banco un QR dinámico por el importe exacto, referencia única y vencimiento.
4. El banco devuelve el payload/URL oficial del QR y el identificador de pago. El frontend representa el payload recibido; la plataforma no inventa el formato QR.
5. El cliente escanea desde la aplicación de su banco participante y confirma el pago.
6. El banco notifica el resultado o permite consultar su estado. El backend valida autenticidad, importe, moneda RUB, merchant, referencia e ID externo.
7. Solo el estado final confirmado y conciliado permite marcar el depósito como pagado y continuar la remesa. Los eventos duplicados se procesan idempotentemente.
8. QR expirado/cancelado no confirma el depósito. Un pago tardío pasa a conciliación manual.

## Seguridad y operación
- QR dinámico por operación, no reutilizable, con importe y expiración.
- QR emitido por el banco adquirente participante de SBP/NSPK.
- Verificación del webhook según contrato bancario: firma, mTLS o autenticación acordada; timestamp y protección anti-replay.
- No almacenar claves privadas, contraseñas ni tokens bancarios en la tabla de cuentas.
- Importes DECIMAL, moneda explícita y comparación exacta.
- No aceptar capturas de pantalla ni el retorno del navegador como prueba de pago.
- Devoluciones como operaciones separadas y asientos compensatorios.
- El titular legal debe coincidir con la entidad que contrate el servicio. SEDERG_PAY es el nombre comercial previsto; el banco exigirá la razón social y documentación legal.

## Condiciones para producción
1. Entidad/estructura legal admisible en Rusia y cuenta empresarial en RUB.
2. Contrato de acquiring SBP C2B con un banco participante (Sberbank, VTB, Alfa-Bank u otro).
3. Alta de merchant/terminal, credenciales, API/webhooks, sandbox y procedimiento de devolución.
4. Confirmar comisiones, límites, liquidación, fiscalidad y obligaciones de caja/recibo según actividad y forma jurídica.
5. Revisión legal del modelo Rusia–Cuba, AML/KYC, sanciones, controles cambiarios y transferencias transfronterizas.

## Impacto económico y tecnológico Rusia–Cuba
- SBP reduce fricción para el remitente en Rusia: paga desde su aplicación bancaria sin introducir manualmente datos de cuenta.
- La comisión C2B del acquiring no es la comisión de una transferencia C2C. Depende del banco, categoría de actividad y contrato; debe incorporarse al coste operativo y a la cotización.
- SBP liquida RUB en Rusia; no convierte ni envía automáticamente fondos a Cuba. La conversión, liquidez y desembolso cubano requieren un circuito separado, autorizado y conciliado.
- En Cuba se mantienen inicialmente los canales definidos: efectivo y transferencia a cuentas bancarias cubanas. La disponibilidad, liquidez y coste de cada canal deben reflejarse en límites y cotización.

## Sandbox
Implementar el adaptador `SbpSandboxQrProvider` que genere un payload claramente ficticio y permita simular estados. No debe producir QR pagables en producción. La activación real queda deshabilitada hasta disponer de contrato y credenciales bancarias.
