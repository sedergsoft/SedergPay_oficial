# Integración de pagos bancarios — preparación previa a contratos

Estado: PREPARACIÓN TÉCNICA; SOLO SANDBOX. Este documento define el trabajo que puede completarse antes de recibir los anexos técnicos. No habilita cobros ni desembolsos reales.

## 1. Objetivo y alcance

Separar fondeo (dinero que paga el remitente a una cuenta receptora de SEDERG_PAY), desembolso (dinero entregado al beneficiario) y liquidación/liquidez (fondos realmente disponibles en la moneda y país del desembolso).

Recibir RUB en Rusia no implica automáticamente disponer de liquidez en Cuba. El circuito RUB/CUP, operadores, plazos, comisiones y disponibilidad de efectivo/transferencias deben verificarse por separado.

## 2. Matriz de canales

| País | Canal | Uso | Estado actual |
|---|---|---|---|
| Rusia | Transferencia bancaria a cuenta SEDERG_PAY | Fondeo | Catálogo sandbox; sin conciliación bancaria real |
| Rusia | СБП C2B QR dinámico | Fondeo comercial | QR sandbox no enrutable; producción pendiente del contrato técnico |
| Cuba | Transferencia bancaria | Fondeo o entrega según sentido | Catálogo sandbox; integración pendiente |
| Cuba | QR local | Solo si banco/proveedor y operación lo permiten | URI local sandbox; no es QR bancario |
| Cuba | Efectivo | Desembolso | Requiere operador, cobertura, límites, comprobante y conciliación |

No utilizar СБП C2C entre particulares como sustituto de un canal comercial C2B contratado.

## 3. Arquitectura objetivo

1. FundingController valida usuario, remesa, importe, moneda, método e idempotencia.
2. Un selector elige adaptador según provider_code y entorno.
3. El adaptador solicita al adquirente una orden/QR dinámico y devuelve identificador externo, payload, importe, moneda y vencimiento.
4. Se persiste una instantánea inmutable de cuenta, método, referencia, importe y proveedor.
5. El proveedor comunica el resultado mediante webhook autenticado y/o consulta de estado.
6. Un procesador idempotente registra el evento y consulta el estado definitivo cuando corresponda.
7. La conciliación compara identificador externo, referencia, cuenta receptora, importe, moneda, dirección y estado.
8. Solo una operación liquidada y conciliada puede generar el asiento de crédito en el ledger.
9. El desembolso se procesa por separado, con reserva de liquidez, idempotencia, comprobante y conciliación propios.

## 4. Información técnica que se incorporará al recibir los contratos

- Entidad contratante y producto contratado (СБП C2B, acquiring, API de cuenta, etc.).
- Entornos sandbox y producción; base URLs oficiales.
- Autenticación: OAuth2, mTLS, certificados, firma de petición u otro mecanismo.
- OpenAPI/Postman y versiones.
- Creación de pago/QR: campos, importe, moneda, merchant ID, cuenta destino, referencia y expiración.
- Consulta de estado y semántica de estados finales.
- Webhooks: eventos, reintentos, orden, firma, timestamp, nonce y tolerancia temporal.
- Identificador externo único y política de idempotencia.
- Devoluciones, cancelaciones, expiraciones, pagos parciales, excedentes y reversos.
- Límites, tarifas, reservas, plazos de liquidación y ventanas de mantenimiento.
- Certificación, soporte e incidentes.
- Para extractos: formatos, paginación/cursor, zona horaria, identificadores estables y correcciones.

No incluir en Git ni en documentación compartida secretos, tokens, contraseñas, claves privadas o certificados privados.

## 5. Reglas de seguridad y contabilidad

- HTTPS/TLS; validar certificados y hostname.
- Secretos en variables de entorno protegidas o secret manager, nunca en MySQL ni repositorio.
- Verificar criptográficamente la firma del webhook conforme al contrato real. No inventar algoritmos.
- Validar timestamp/nonce y proteger contra replay.
- Registrar eventos con acceso restringido y redacción de datos sensibles.
- Restricciones únicas para provider_event_id, provider_payment_id y claves de idempotencia.
- Nunca acreditar por escanear el QR, una captura de pantalla o un callback no autenticado.
- No acreditar dos veces. Los reversos se registran con asientos compensatorios.
- Pagos parciales, excedentes, duplicados, discrepancias de referencia/importe/moneda/cuenta y estados ambiguos pasan a revisión manual.
- Enmascarar identificadores bancarios al mostrarlos al cliente.
- Las solicitudes existentes conservan su instantánea aunque el administrador cambie la cuenta activa.

## 6. Consideraciones Rusia–Cuba

- Confirmar que el contrato permite cobrar pagos comerciales por СБП C2B a nombre de la entidad titular de SEDERG_PAY.
- Verificar límites, comisiones, liquidación y restricciones de transferencias transfronterizas y operadores.
- Definir quién mantiene y repone la liquidez en Cuba y qué ocurre si se recibe RUB pero no se puede desembolsar CUP.
- En Cuba, probar por separado transferencias y efectivo, cobertura territorial, horarios, límites y evidencia de entrega.
- Las cotizaciones mostrarán comisión, modalidad, base, importe debitado y neto del beneficiario antes de confirmar.
- No prometer tiempos de entrega dependientes de bancos, conectividad, efectivo u operadores sin SLA verificable.

## 7. Trabajo previo a los contratos

1. Mantener sandbox y cuentas de prueba aisladas.
2. Completar pruebas de idempotencia, duplicados, expiración, cambios de cuenta y concurrencia en depósitos.
3. Preparar un procesador interno de eventos con estados normalizados, sin aceptar todavía webhooks bancarios externos.
4. Preparar conciliación por extracto importado en formato controlado, inicialmente simulada y sin acreditación automática.
5. Añadir pruebas del ledger para asegurar que una confirmación conciliada produce exactamente un crédito.
6. Documentar revisión manual, reverso y recuperación ante caída del proveedor.
7. Al recibir contratos, implementar adaptador concreto, validadores criptográficos y pruebas de contrato contra sandbox bancario.

## 8. Criterios de habilitación productiva

Producción permanecerá deshabilitada hasta completar y aprobar: contrato y anexo técnico; credenciales/certificados en entorno protegido; certificación y pruebas sandbox; webhook autenticado y anti-replay; conciliación contra estado/extracto; ledger idempotente, reversos y alertas; circuito de desembolso y liquidez en Cuba; límites, comisiones, SLA, soporte y contingencia; revisión de seguridad y cumplimiento; aprobación explícita para activar producción y realizar una operación controlada.

No cambiar production_enabled a true ni exponer un QR real hasta satisfacer estos criterios.