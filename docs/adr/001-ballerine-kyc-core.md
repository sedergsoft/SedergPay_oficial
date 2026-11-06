# ADR-001: Ballerine como motor de experiencia y orquestación KYC

- Estado: Adaptador implementado y desactivado por defecto; activación productiva pendiente de revisión legal, licencias y despliegue.
- Fecha: 2026-11-03 (actualizado 2026-11-05)
- Rama: `feat/ballerine-kyc-core`

## Decisión

SEDERG_PAY adoptará Ballerine como componente central del flujo de recopilación y orquestación KYC, manteniendo Yii2 como autoridad de dominio, persistencia, autenticación, autorización, auditoría y decisión final de cumplimiento.

Ballerine no será la fuente de verdad del estado financiero ni podrá aprobar por sí solo una remesa. La decisión de habilitar operaciones seguirá siendo responsabilidad de SEDERG_PAY, con controles de cumplimiento separados.

## Advertencia de adopción

El README del repositorio oficial `ballerine-io/ballerine` informa que la versión open source atraviesa una reconstrucción importante y no está activamente soportada. Por ello, antes de incorporar paquetes o desplegar servicios debemos:
1. fijar un commit/tag concreto y revisar su licencia por paquete;
2. realizar una prueba de concepto aislada, sin documentos ni datos personales reales;
3. revisar dependencias, vulnerabilidades, mantenimiento y compatibilidad;
4. mantener una interfaz adaptadora propia para poder sustituir Ballerine sin migrar el dominio KYC.

No se debe depender de una etiqueta flotante, CDN mutable ni servicio SaaS externo para procesar documentos sensibles.

## Arquitectura objetivo

```
React / Portal SEDERG_PAY
        |
        | sesión OIDC + solicitud de sesión KYC
        v
Yii2 API (KYC Orchestrator / Adapter)
        |-- valida usuario, país, consentimiento y elegibilidad
        |-- crea expediente y sesión de proveedor
        |-- entrega configuración efímera del flujo
        |
        +----> Ballerine Collection Flow (self-hosted, aislado)
        |          |-- captura datos/documentos
        |          |-- devuelve eventos de progreso
        |          +-- webhook firmado -> Yii2
        |
        +----> Almacenamiento privado de documentos
        |          |-- cuarentena / validación MIME / ClamAV
        |          +-- cifrado en reposo / retención controlada
        |
        +----> Revisión humana / proveedor de verificación
                   |-- resultado y evidencias
                   +-- decisión final controlada por SEDERG_PAY
```

Ballerine se integra detrás de una interfaz de proveedor; el resto del sistema no debe depender de sus modelos internos.

## Límites de responsabilidad

### Ballerine
- Presentar el flujo de recopilación y sus pasos.
- Orquestar estados técnicos del proceso y, cuando se valide, conectores de verificación.
- Proporcionar identificadores externos correlacionables y eventos de proceso.
- Facilitar una consola de revisión solo si se confirma su madurez, control de acceso y licencia.

### SEDERG_PAY / Yii2
- Identidad de usuario y autorización; nunca confiar en un `user_id` enviado libremente por el navegador.
- Consentimiento, finalidad, país de residencia/nacionalidad y trazabilidad.
- Vincular cada sesión externa a un expediente interno y a un usuario autenticado.
- Validar firma, timestamp, nonce/idempotencia y origen de cada webhook.
- Mantener los documentos fuera del directorio público, cifrados y escaneados.
- Gestionar estados de negocio, revisión manual, motivos, apelación/reenvío y auditoría.
- Bloquear operaciones hasta que las políticas KYC/AML/sanciones aplicables estén satisfechas.

## Modelo de estados propuesto

Estados internos estables, independientes del proveedor:
- `not_started`
- `in_progress`
- `pending_documents`
- `pending_verification`
- `manual_review`
- `approved`
- `rejected`
- `resubmission_required`
- `expired`
- `error`

Los estados/eventos de Ballerine se traducirán a estos estados mediante un mapper. Un webhook repetido no podrá duplicar transiciones ni auditorías. Los eventos desconocidos se guardarán como observaciones y no producirán aprobación.

## Contratos internos previstos

- `POST /api/v1/kyc/sessions`: crea o recupera una sesión para el usuario autenticado.
- `GET /api/v1/kyc`: devuelve estado y requisitos del expediente interno.
- `POST /api/v1/kyc/webhooks/ballerine`: receptor aislado; valida firma y protección anti-replay.
- `POST /api/v1/admin/kyc/{public_id}/review`: decisión humana autorizada y auditada.

El endpoint de webhook no usa la identidad de desarrollo ni acepta decisiones desde el navegador. La sesión KYC será de vida corta, de un solo uso cuando sea viable, y vinculada al usuario/expediente.

## Requisitos específicos Rusia ↔ Cuba

La implementación no asumirá que un proveedor, método de verificación, transferencia internacional o tratamiento transfronterizo de datos es legal o está disponible en ambos países. Antes de producción hay que validar con asesoría especializada y cada proveedor:
- país de residencia, nacionalidad, documento emitido y país de emisión;
- formatos de pasaporte/identidad y direcciones de Rusia y Cuba;
- disponibilidad de OCR, prueba de vida y revisión humana para ambos países;
- idioma español/ruso, accesibilidad y conectividad móvil de bajo ancho de banda;
- ubicación del procesamiento, subencargados, transferencias internacionales y retención;
- obligaciones locales de protección de datos, AML/CFT, sanciones y conservación de evidencias;
- costes por verificación, reintentos, revisión manual y conversión RUB/CUP.

El diseño debe permitir revisión manual cuando un proveedor no admita documentos o verificaciones de uno de los países. No se usará geolocalización ni nacionalidad como sustituto de una evaluación legal individual.

## Fases de implementación

1. **Inventario y evaluación**: fijar versión Ballerine, licencia, dependencias, seguridad, endpoints, webhooks y capacidad real de self-hosting.
2. **Adaptador Yii2**: definir interfaz `KycProviderInterface`, configuración por entorno y persistencia de identificadores externos.
3. **Prueba de concepto local**: despliegue aislado con datos sintéticos; validar flujo web, eventos, errores, reintentos y revisión.
4. **Integración de documentos**: acordar si el SDK envía documentos a almacenamiento propio o a Ballerine; evitar duplicación y exposición pública.
5. **Webhooks y estados**: firma HMAC/clave rotatoria, timestamp, idempotencia, allowlist cuando proceda y pruebas de replay.
6. **Panel de revisión**: roles separados, MFA, acceso mínimo, motivo obligatorio y auditoría inmutable.
7. **Piloto controlado**: primero sandbox; después piloto limitado solo tras validación legal, contractual, de seguridad y privacidad en ambos países.

## Estado de implementación (2026-11-05)

- `BallerineConfig` valida URLs y secretos solo desde variables de entorno.
- `BallerineKycProvider` crea workflows mediante
  `POST /api/v1/external/workflows/run` y construye una URL de colección
  configurable.
- `POST /api/v1/kyc/session` vincula una sesión corta al expediente del usuario
  autenticado y la audita.
- `POST /api/v1/kyc/webhooks/ballerine` valida `X-Authorization` o firma
  HMAC-SHA256, ventana temporal y tamaño del cuerpo.
- `kyc_provider_events` impone idempotencia por evento; las repeticiones no
  duplican transiciones ni auditorías.
- Los estados desconocidos quedan en cuarentena y los estados finales internos
  no pueden ser sobrescritos por Ballerine.
- La integración está desactivada si `SEDERG_BALLERINE_ENABLED` no es `1` o si
  faltan las variables `SEDERG_BALLERINE_*`.
- `php yii kyc/provider-status` y `GET /api/v1/admin/kyc/provider` permiten
  verificar configuración, último evento y excepciones sin exponer secretos.
- Todavía no se ha validado un despliegue Ballerine concreto, su licencia, sus
  dependencias ni el tratamiento transfronterizo de datos.

## Criterios de aceptación

- Ningún documento real se procesa en la PoC.
- El frontend no puede marcar una identidad como aprobada.
- La ausencia o fallo de Ballerine no provoca aprobación por defecto.
- Webhooks inválidos, repetidos o fuera de ventana se rechazan.
- La API interna conserva compatibilidad con el flujo actual durante la migración.
- Los documentos siguen privados, escaneados y con acceso auditado.
- Los casos Rusia/Cuba no soportados por el proveedor pasan a revisión manual.
- CI ejecuta pruebas de mapeo de estados, autorización, firma e idempotencia.

## Referencias

- Repositorio oficial: https://github.com/ballerine-io/ballerine
- Documentación de introducción: https://docs-dev.ballerine.com/en/getting_started/introduction/
- Guía de flujo KYC: https://docs-dev.ballerine.com/en/learn/creating_a_kyc_flow_and_deploying_it/

