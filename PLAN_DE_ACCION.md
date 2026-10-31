# SEDERG_PAY — Plan de acción de lanzamiento

## Objetivo del primer release

Construir un MVP con datos reales de clientes y operaciones de negocio que permita recorrer:

`cotización → solicitud → revisión de cumplimiento → depósito simulado → ledger → pago simulado → conciliación`

Los depósitos, pagos y bancos serán simulados inicialmente. El uso de datos personales
requiere consentimiento, minimización, control de acceso, cifrado, retención y respuesta
a incidentes. No se moverá dinero real ni se conectarán bancos productivos antes de los
gates jurídicos, contractuales, regulatorios y operativos.

## Decisiones de línea base

| Área | Decisión |
|---|---|
| Backend | Yii2 / PHP 8.3 |
| Frontend | React + TypeScript |
| Base de datos | MySQL 8.4 LTS / InnoDB / utf8mb4 |
| API | REST/JSON versionada |
| Identidad | Keycloak/OIDC y MFA; no se implementará autenticación propia |
| Integraciones | Adaptadores desacoplados, inicialmente simulados |
| Dinero | `DECIMAL`, moneda explícita y cotización versionada |
| Contabilidad | Ledger inmutable con idempotencia y asientos compensatorios |
| Operación | Outbox, reintentos, conciliación y auditoría |

## Hallazgos documentales consolidados

- Los documentos maestro, funcional y el PDF DER todavía declaran PostgreSQL; quedan
  como referencias de diseño históricas. El documento de plan, el modelo físico y la
  implementación activa usan MySQL.
- El DER conceptual incluye más entidades que la migración Yii2 actual, en especial
  `ledger_transactions`, `ledger_entries`, tesorería, compensación, soporte,
  notificaciones y configuración. No se deben presentar como implementadas.
- La migración existente cubre un núcleo inicial de usuarios, beneficiarios,
  cotizaciones, remesas, cumplimiento, depósitos, bancos, payouts, wallets,
  conciliación, idempotencia y auditoría.
- El acceso a Sberbank, VTB y Alfa-Bank no está confirmado. Solo se implementarán
  adaptadores simulados hasta contar con contratos, scopes, sandbox y evidencia
  autorizada.

## Fases y criterios de salida

### F0–F1 — Base controlada (implementación iniciada)

- Inventariar documentación y contradicciones.
- Marcar PostgreSQL como legado y usar MySQL como línea base activa.
- Materializar el paquete Yii2 inicial en `backend/`.
- Mantener una única migración autoritativa.
- Añadir consentimiento, eventos de estado, outbox y doble partida explícita.
- Añadir entorno local reproducible con MySQL 8.4 y Redis.
- Publicar reglas de dominio para transiciones y equilibrio contable.

**Progreso:** migración incremental, `docker-compose.yml`, contrato OpenAPI y reglas de
dominio implementados. Falta ejecutar la migración en una instancia MySQL 8.4 y completar
la instalación de dependencias con PHP 8.3.

**Salida:** estructura reproducible, controles financieros iniciales y decisión técnica registrada.

### F2 — Contrato funcional implementable (parcial)

- Cerrar estados y transiciones de cotización, transferencia, cumplimiento, depósito, payout y conciliación.
- Definir roles, permisos y claves de idempotencia.
- Publicar contrato OpenAPI para el flujo sandbox.
- Contrato inicial publicado en `backend/docs/openapi.yaml`.
- Política, cálculo y servicio de cotización implementados en `backend/src/Domain/Pricing/`.

**Salida:** endpoints y criterios de aceptación versionados.

### F3 — Núcleo transaccional (iniciado)

- Implementar cotizaciones, beneficiarios y transferencias.
- Validar importes, monedas, expiración y propiedad de recursos.
- Registrar eventos de estado y auditoría.
- El cálculo de cotización ya valida comisión, límites, precisión decimal y expiración.

**Salida:** flujo de creación de transferencia probado sin proveedor externo.

### F4 — Depósito, ledger y conciliación simulados

- Implementar proveedor bancario simulado.
- Procesar eventos repetidos de forma idempotente.
- Acreditar solo después de conciliación válida.
- Prohibir edición/borrado de asientos confirmados.

**Salida:** pruebas de duplicados, reintentos, ambigüedad y reversos.

### F5 — Frontend operativo (implementado en sandbox)

- Portal del remitente.
- Cola de revisión para operaciones, cumplimiento y conciliación.
- Panel de auditoría y estados.

**Salida:** demo E2E con datos reales introducidos por clientes y proveedores simulados.

### F6 — Preparación de piloto controlado (pendiente de gates externos)

- Validación jurídica y contractual en Rusia y Cuba.
- Confirmación escrita de productos, permisos, límites, monedas y métodos de pago.
- Pruebas de seguridad, backups, restauración, observabilidad y continuidad.

**Gate:** no se habilita dinero real sin aprobación formal.

## Backlog inmediato

1. Crear la aplicación Yii2 ejecutable alrededor de la migración existente.
2. Auditar la migración sobre MySQL 8.4 limpio y corregir incompatibilidades.
3. Definir estados y contratos OpenAPI del flujo sandbox.
4. Implementar la primera operación de negocio: crear y consultar cotizaciones.
5. Añadir pruebas de idempotencia y autorización.
6. Implementar registro de clientes, perfiles y consentimiento verificable.
7. Implementar beneficiarios propios con tokenización y aislamiento por usuario.
8. Implementar creación de remesas desde cotización y beneficiario vigentes.
9. Incorporar proveedor bancario simulado y conciliación.
10. Implementar revisión de cumplimiento sandbox y transiciones aprobada/rechazada.
11. Implementar payout simulado, ledger de doble partida y conciliación final.
12. Crear portal React/TypeScript y empaquetado Docker del MVP.

## Estado de cierre del MVP sandbox

- Flujo validado: registro → beneficiario → cotización → remesa → depósito
  simulado → cumplimiento → payout simulado → ledger → conciliación.
- Frontend React/TypeScript compilado para producción.
- Docker Compose validado con frontend, backend, MySQL y Redis.
- Health check disponible en `GET /health`.
- No se habilita dinero real, banca productiva ni autenticación de desarrollo
  como sustituto de OIDC.
- Se añadió verificación OIDC RS256 configurable; el subject debe estar
  provisionado y el modo de desarrollo no es válido para producción.

## Riesgos bloqueantes

- No hay autorización contractual ni credenciales sandbox para bancos.
- El modelo regulatorio y de licencias debe ser validado externamente.
- El ledger actual es una base inicial; debe evolucionar a doble partida explícita antes del piloto.
- Los datos sensibles deben permanecer fuera de la base salvo tokenización y cifrado aprobado.

## Definition of Done del MVP con datos reales

- Flujo E2E ejecutable con datos reales introducidos por clientes y proveedores simulados.
- Consentimiento, minimización, cifrado y acceso por rol para datos personales.
- Migraciones reproducibles en MySQL 8.4.
- API versionada con validación de entrada y autorización backend.
- Idempotencia probada ante reintentos y duplicados.
- Auditoría de acciones críticas.
- Ledger y conciliación cubiertos por pruebas.
- Sin secretos ni credenciales reales en código, logs o fixtures.
