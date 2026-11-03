# Módulo de métodos de pago y cuentas receptoras de SEDERG_PAY

## Objetivo

Implementar la segunda etapa del flujo de remesas: presentar al remitente los métodos de pago habilitados para el país/moneda de origen, seleccionar la cuenta bancaria receptora propiedad de la plataforma y generar instrucciones/QR específicos para el método seleccionado. El ingreso solo se confirma mediante una fuente bancaria/adaptador autorizado y conciliación; la lectura o presentación de un QR no confirma el pago.

## Hallazgos del repositorio (rama main, 2026-11-03)

### Separación necesaria: fondeo vs. entrega

Los catálogos actuales mezclan el contexto general de métodos con el uso concreto. El módulo debe distinguir explícitamente:

- **Método de fondeo (inbound):** cómo paga el remitente a SEDERG_PAY en el país de origen.
- **Método de entrega (outbound):** cómo recibe el dinero el beneficiario en el país de destino.

La cotización y el perfil de beneficiario ya trabajan con métodos de entrega; no deben reutilizarse como método de fondeo.

### Métodos de entrega actualmente definidos

- Cuba: `cash_pickup` (Pago en efectivo), `bank_account` (Abono en cuenta bancaria) y `card_transfer` (Transferencia a tarjeta; añadido por migración posterior).
- El catálogo inicial de la migración también incluía `card_or_wallet` (“Tarjeta o billetera autorizada”) inactivo. No se considera habilitado.
- La interfaz actual ofrece los tres primeros métodos. El beneficiario registra los datos del método y puede seleccionar otro método para una remesa concreta; la preferencia del perfil no es una restricción.

### Métodos de fondeo documentados

- La migración inicial contiene `bank_transfer` (“Transferencia bancaria”), país RU, dirección `inbound`, activo.
- No se ha encontrado en el esquema/catálogos del repositorio una lista de métodos de fondeo cubanos habilitados.
- El código actual genera un payload QR genérico en `RemittanceController::actionCreate()`, seleccionando la primera `bank_connections` activa. No selecciona un adaptador QR por método y no valida que la cuenta corresponda al corredor/moneda.
- `PaymentController` y `SimulatedPaymentRepository` son sandbox: generan depósitos simulados y una confirmación manual simulada. No conectan con bancos reales.

### Bancos mencionados en los documentos

- Rusia: Sberbank, VTB y Alfa-Bank aparecen en el alcance del modelo bancario. `bank_connections.bank_code` admite esos códigos como candidatos; los modos previstos son `api`, `host_to_host` y `statement_import`.
- La documentación no acredita que SEDERG_PAY tenga contrato, API habilitada, QR de comercio o permisos de recepción con ninguno de esos bancos. Se deben tratar como bancos candidatos a validar, no como integraciones activas.
- Cuba: los documentos técnicos del repositorio no especifican bancos cubanos contratados ni un catálogo de bancos de recepción. La entrega a cuenta bancaria está prevista funcionalmente, pero no identifica los bancos admitidos.
- No se debe presentar como disponible una integración de SBP, QR de comercio, Transfermóvil, EnZona o un banco cubano hasta validar proveedor, titularidad, contrato y disponibilidad para la entidad operadora.

## Matriz funcional de partida

| Corredor | País/moneda de fondeo | Método de fondeo documentado | Método de entrega documentado | Estado |
|---|---|---|---|---|
| Rusia → Cuba | Rusia / RUB | Transferencia bancaria (`bank_transfer`) | Efectivo, abono a cuenta o transferencia a tarjeta en Cuba | Métodos de entrega en catálogo/UI; canal de fondeo en sandbox |
| Cuba → Rusia | Cuba / CUP | No definido en el catálogo del repositorio | En la arquitectura general se prevé entrega a cuenta en Rusia, pero faltan métodos/proveedores específicos | Requiere decisión y validación operativa |

## Titularidad de las cuentas receptoras\n\nDecisión confirmada por el usuario: las cuentas receptoras estarán a nombre de **SEDERG_PAY**. El sistema debe guardar literalmente el titular legal validado para cada cuenta y mostrarlo en las instrucciones de pago. El valor predeterminado funcional será `SEDERG_PAY`, pero antes de activar una cuenta real se debe comprobar que ese sea el nombre legal exacto aceptado por el banco y que SEDERG_PAY sea la entidad jurídica autorizada para recibir los fondos en el país correspondiente. No se deben utilizar cuentas personales de administradores ni de terceros.\n\nLa titularidad no sustituye la verificación legal, bancaria ni regulatoria. En Rusia y Cuba pueden existir diferencias en la forma jurídica, documentación, moneda, restricciones de recepción y requisitos de identificación del titular. Cada cuenta se habilitará por separado después de verificar esos requisitos en su jurisdicción.\n\n## Diseño propuesto

### 1. Catálogo de métodos de fondeo

Cada método tendrá: código, país, moneda, tipo (bank_transfer, instant_qr, card, wallet, cash si se autoriza), proveedor/banco, estado, capacidades (QR dinámico, referencia, confirmación webhook/extracto), límites y configuración no secreta.

El catálogo de métodos de entrega permanecerá separado.

### 2. Cuentas receptoras propiedad de la plataforma

Crear un módulo administrativo para añadir, editar, activar/desactivar y sustituir cuentas receptoras. Datos mínimos:

- país y moneda;
- banco/proveedor;
- titular legal: SEDERG_PAY (con la denominación jurídica exacta validada para la cuenta);
- tipo y número de cuenta / IBAN / identificador bancario según el país;
- máscara para mostrar al cliente;
- método(s) de fondeo compatibles;
- entorno y estado;
- fechas de vigencia y observaciones.

El número completo y credenciales bancarias deben cifrarse o mantenerse en un almacén de secretos; no se devolverán al frontend sin necesidad. No se almacenarán tokens/API keys/certificados privados en claro. Las modificaciones serán auditadas. Las cuentas usadas por operaciones históricas se desactivan o sustituyen, nunca se eliminan físicamente.

Debe existir una única cuenta receptora activa por combinación de país + moneda + método + proveedor, salvo que se defina explícitamente un mecanismo de selección/ruteo.

### 3. QR por método de pago

El backend seleccionará una estrategia/adaptador por método y proveedor:

- Transferencia bancaria tradicional: mostrar datos bancarios y referencia única; generar QR solo si el estándar/formato del banco receptor lo soporta.
- QR bancario/instantáneo: generar payload dinámico específico del proveedor, con importe, moneda, referencia, destino y expiración.
- Tarjeta o billetera: solo generar QR si el adquirente/proveedor entrega un payload o URL de pago oficial; no codificar un JSON propio esperando que una aplicación bancaria lo interprete.

El QR se genera en backend o con un payload firmado/validado por el adaptador. El cliente puede renderizarlo visualmente, pero no construye el contenido financiero. Cada depósito tendrá referencia única e idempotente. El QR expira y debe estar ligado a una sola remesa/cuenta. Nunca marcar como pagado por escanearlo o subir una captura.

### 4. Datos históricos e integridad

Al crear la solicitud de depósito se guarda una instantánea inmutable de:
- método de fondeo y proveedor;
- cuenta receptora y titular;
- importe/moneda;
- referencia;
- payload/URL de QR y vencimiento;
- identificador externo del proveedor cuando exista.

Cambiar la cuenta desde administración afectará solo a depósitos nuevos; las instrucciones ya emitidas seguirán apuntando a la cuenta original hasta expirar/cancelarse. Se bloqueará la edición destructiva de cuentas con depósitos pendientes.

### 5. Seguridad y operación

- Endpoints administrativos protegidos por rol admin y auditoría.
- Idempotencia en creación de instrucciones y webhooks.
- Verificación de firma de webhooks, anti-replay y unicidad de transacción bancaria.
- Conciliación por cuenta, moneda, importe y referencia; coincidencias ambiguas a revisión manual.
- El modo sandbox seguirá separado del modo real.
- Activar una cuenta real requerirá verificar titularidad de la entidad, contrato bancario, autorización regulatoria, AML/KYC, sanciones, límites, devoluciones y disponibilidad del canal.

## Entregas técnicas propuestas para esta rama

1. Migración MySQL para catálogo de métodos de fondeo y cuentas receptoras de plataforma, con cifrado/mascarado, vigencia e índices.
2. API de administración para CRUD lógico de cuentas receptoras y catálogo de métodos.
3. API del cliente para listar métodos disponibles y crear instrucciones de pago.
4. Servicio de estrategia QR por método/proveedor, con simulador de sandbox y adaptadores reales deshabilitados hasta autorización.
5. UI administrativa para gestionar cuentas y métodos.
6. Wizard de pago para mostrar QR/instrucciones según método seleccionado, importe, referencia, vencimiento y estado.
7. Pruebas unitarias, integración HTTP/MySQL, idempotencia, cambio de cuenta, QR por método, rechazo de métodos deshabilitados y regresión de ambos corredores.

## Decisiones pendientes antes de activar métodos reales

- Confirmar métodos de fondeo para Cuba → Rusia y para Rusia → Cuba, separados de los métodos de entrega.
- Confirmar banco/cuenta receptora de SEDERG_PAY en cada país, denominación jurídica exacta que figura en el banco, moneda y datos bancarios requeridos.
- Confirmar con Sberbank, VTB o Alfa-Bank qué canal contractual está disponible (transferencia a cuenta, API de movimientos, webhook, QR/adquirencia); su mención documental no equivale a disponibilidad.
- Confirmar bancos cubanos de destino y requisitos para abono a cuenta/tarjeta.
- Determinar si los QR serán QR de transferencia bancaria, QR de cobro/adquirencia o deep links de aplicaciones bancarias. Son formatos distintos y no intercambiables.

## Restricción de alcance

El desarrollo y las pruebas iniciales se harán en Docker local/sandbox. No se habilitarán pagos reales, custodia de fondos ni despliegue productivo como consecuencia de esta rama.
