# SEDERG_PAY — Modelo físico MySQL y migración Yii2

## Alcance
Esquema inicial para una plataforma de remesas con operaciones en ambos sentidos, catálogo de beneficiarios y modalidades de pago, cotizaciones, órdenes de remesa, controles de cumplimiento, integración con bancos rusos (Sberbank, VTB y Alfa-Bank), recepción de notificaciones, importación/consulta de movimientos, conciliación y registro contable idempotente.

## Requisitos
- Yii 2.0 (basic o advanced).
- MySQL 8.0.16 o superior (para aplicar las restricciones `CHECK`).
- InnoDB y `utf8mb4`.
- Extensión PHP `bcmath` para validar saldos de doble partida sin pérdida de precisión.
- PHP compatible con la versión de Yii2 instalada.

## Instalación
1. Haga una copia de seguridad y cree una base de datos vacía.
2. Configure `components['db']` en `config/db.php` con el DSN de MySQL.
3. Copie la carpeta `migrations/` a la raíz de la aplicación Yii2 (o configure el `migrationPath`).
4. Ejecute:

   `php yii migrate --migrationPath=@app/migrations`

5. Compruebe las tablas y los catálogos insertados.

Para desarrollo local, el repositorio incluye `docker-compose.yml` en la raíz.
La instalación de Composer y la ejecución de Yii2 requieren PHP 8.3; no se debe
usar `--ignore-platform-req` en entornos de despliegue.

## Bancos y seguridad
`bank_connections` almacena referencias internas y metadatos no secretos. No guarde contraseñas, certificados privados, tokens de acceso ni claves API en texto claro en esta tabla. Use un gestor de secretos o un almacén cifrado y registre aquí únicamente un identificador seguro. La disponibilidad de API, extractos, webhooks y pagos debe validarse contractualmente con cada banco; el esquema no implica que el banco habilite esos productos para la empresa.

## Reglas de diseño
- Importes: `DECIMAL(20,2)`; tipos de cambio: `DECIMAL(20,10)`. No use `FLOAT`/`DOUBLE` para dinero.
- UUID públicos separados de las claves primarias internas.
- Claves de idempotencia para webhooks, creación de recursos y asientos de libro.
- Los movimientos bancarios originales se conservan para auditoría y conciliación.
- `wallet_ledger_entries` es un registro de movimientos inmutable por diseño de aplicación; correcciones deben hacerse con asientos compensatorios, no editando asientos históricos.
- Estados se guardan como `VARCHAR` para permitir evolución; validar sus valores mediante enums de aplicación o tablas catálogo según las necesidades finales.
- Los datos bancarios de beneficiarios se representan mediante `payout_account_token` y `payout_account_last4`; no se debe almacenar innecesariamente el número completo de cuenta o tarjeta.

## Tablas incluidas
1. `countries`
2. `currencies`
3. `users`
4. `user_profiles`
5. `beneficiaries`
6. `payment_methods`
7. `exchange_rates`
8. `remittance_quotes`
9. `remittances`
10. `compliance_checks`
11. `bank_connections`
12. `deposit_requests`
13. `bank_webhook_events`
14. `bank_transactions`
15. `reconciliation_cases`
16. `wallets`
17. `wallet_ledger_entries`
18. `payout_orders`
19. `payout_attempts`
20. `bank_sync_cursors`
21. `idempotency_keys`
22. `audit_logs`
23. `user_consents`
24. `remittance_state_events`
25. `ledger_accounts`
26. `ledger_transactions`
27. `ledger_entries`
28. `outbox_messages`
29. `kyc_cases`
30. `kyc_documents`

## Flujo operativo recomendado
1. El usuario se registra y completa su perfil.
2. El sistema crea una cotización con importes, tipo de cambio, comisión y vencimiento.
3. Se registra la remesa y se crea una `deposit_request` con referencia única.
4. Un adaptador bancario consulta movimientos y/o recibe webhook. Se verifica autenticidad, se guarda el evento y se normaliza la transacción.
5. El motor de conciliación compara referencia, importe, moneda, fecha y cuenta receptora. Los casos ambiguos quedan en revisión manual.
6. Al confirmar el ingreso, una transacción de base de datos cambia el estado de remesa y crea los asientos contables necesarios, con clave de idempotencia.
7. Se ejecuta el pago al beneficiario mediante el proveedor/modalidad autorizados y se registran intentos y resultado.
8. Se auditan los cambios sensibles y se gestionan devoluciones/reversiones mediante asientos compensatorios.

## Límites importantes antes de producción
- Completar reglas de negocio y estados válidos de cada entidad.
- Confirmar licencias, AML/KYC, sanciones, controles cambiarios, protección de datos y posibilidad legal de operar entre Rusia y Cuba.
- Confirmar el circuito real de liquidación, bancos corresponsales, monedas y modalidades de pago disponibles en ambos países.
- Definir el tratamiento de devoluciones, transferencias parciales, pagos duplicados, disputas, comisiones bancarias y conciliación de diferencias.
- Agregar cifrado de datos sensibles, retención de logs, política de acceso por roles, alertas y monitoreo.
- Los catálogos de moneda y métodos son iniciales; no representan una promesa de disponibilidad operativa.

## Relaciones principales
- `users` 1:N `beneficiaries`, `remittance_quotes`, `remittances`.
- `remittance_quotes` 1:N opcional con `remittances` (la remesa conserva su propia fotografía de importes y tasa).
- `remittances` 1:N `compliance_checks`, `deposit_requests`, `payout_orders`, `wallet_ledger_entries`.
- `bank_connections` 1:N `bank_webhook_events`, `bank_transactions`, `deposit_requests`, `bank_sync_cursors`.
- `bank_transactions` 1:N `reconciliation_cases`; pueden vincularse a una `deposit_request` y/o remesa.
- `payout_orders` 1:N `payout_attempts`.
- `wallets` 1:N `wallet_ledger_entries`.

## Nota sobre montos de remesa
El esquema no fuerza un límite fijo de importe por fila porque los límites dependen del país, canal, moneda, proveedor y regulación. Si se decide aplicar el rango operativo inicialmente estudiado de 15.000 a 50.000, debe definirse explícitamente la moneda y si el límite se aplica al importe enviado, recibido o total debitado, y después implementarse en la capa de negocio y/o una restricción específica.


## Gestión de la tasa RUB/CUP (actualización de esta rama)

- La selección activa se almacena en `platform_settings` como `pricing.exchange_rate_source`: `official` o `admin_override`.
- La API administrativa `PUT /api/v1/admin/exchange-rate/source` recibe JSON `{"source":"official"}` o `{"source":"admin_override"}`. Requiere rol administrador.
- Al guardar una tasa manual con `POST /api/v1/admin/exchange-rate`, se activa automáticamente la fuente manual. La tasa oficial queda conservada y puede reactivarse en cualquier momento.
- El refresco de fuentes oficiales se ejecuta con `php yii rates/update`. Instale en el servidor la tarea cron para ejecutarlo cada 12 horas: `0 */12 * * * cd /ruta/backend && php yii rates/update >> runtime/logs/rates-cron.log 2>&1`. El repositorio incluye el ejemplo en `backend/deploy/sederg-rates.cron`; la tarea debe instalarse en el host que ejecuta el backend.
- El refresco oficial no cambia la fuente activa. Si está seleccionada la tasa manual, la cotización continúa usando esa tasa aunque se actualicen los datos oficiales.
- Las tasas aplicadas a cotizaciones/remesas ya creadas no se reescriben; el cambio afecta a nuevas cotizaciones.


## Perfil de usuario y KYC (rama feature/user-profile-beneficiaries-kyc)

- Los usuarios pueden editar sus beneficiarios mediante `PUT /api/v1/beneficiaries/{public_id}`; la API limita cada consulta y actualización al propietario autenticado.
- El perfil se consulta y actualiza en `GET/PUT /api/v1/profile`.
- KYC: `GET /api/v1/kyc`, `POST /api/v1/kyc/documents` (multipart: `file`, `document_type`) y `POST /api/v1/kyc/submit`.
- Los tipos admitidos son identidad, comprobante de domicilio y selfie opcional. La carga admite PDF/JPG/PNG de hasta 5 MB. Hay dos límites de infraestructura que deben acompañar ese máximo: nginx (`client_max_body_size 10m` en `frontend/nginx.conf`) y PHP (`upload_max_filesize=8M`/`post_max_size=10M` en `backend/docker/php-uploads.ini`). Con los valores por defecto (1 MB en nginx y 2 MB en PHP) los archivos válidos se rechazaban con `HTTP 413` o `A valid document upload is required`. La revisión administrativa está disponible en `GET /api/v1/admin/kyc`, descarga protegida de documentos y `POST /api/v1/admin/kyc/{public_id}/review`.
- Ejecute `php yii migrate --migrationPath=@app/migrations` para crear las tablas `kyc_cases` y `kyc_documents` y añadir nacionalidad al perfil.
- Configure `SEDERG_PRIVATE_STORAGE_PATH` como directorio persistente **fuera del document root**, con permisos restringidos para el usuario del proceso PHP. Los documentos se cifran con `SEDERG_PII_KEY` antes de guardarse. En producción, `SEDERG_PII_KEY` es obligatorio y debe ser un secreto fuerte y estable; perderlo impide descifrar los documentos existentes. El volumen Docker `sederg_private_data` conserva los archivos entre despliegues. No exponga este directorio mediante Apache/Nginx ni backups públicos.
- `SEDERG_KYC_REQUIRED=1` obliga a tener KYC aprobado antes de crear remesas. Fuera del modo de autenticación development, esta condición se aplica por defecto. En sandbox/desarrollo se puede probar el flujo sin bloquear remesas.
- La política documental y los controles de KYC/AML deben validarse para cada corredor RU-CU y CU-RU con asesoría legal y los operadores autorizados. Esta implementación aporta el flujo técnico; no certifica por sí sola el cumplimiento regulatorio.


### Ballerine KYC adapter

- Prueba local sin Ballerine: `scripts/Enable-BallerineMock.ps1` levanta el simulador `kyc-mock` bajo el profile `kyc-mock`, configura `.env` y aplica migraciones. Guía completa: `../docs/ballerine-kyc-mock.md`.
- La integración está desactivada por defecto mediante `SEDERG_BALLERINE_ENABLED=0`. Aun con el resto de variables configuradas, no se activa hasta establecer el flag en `1` y completar `SEDERG_BALLERINE_BASE_URL`, `SEDERG_BALLERINE_API_KEY`, `SEDERG_BALLERINE_WORKFLOW_ID` y `SEDERG_BALLERINE_COLLECTION_URL`.
- `POST /api/v1/kyc/session` crea o recupera una sesión de colección vinculada al usuario autenticado. Devuelve `collection_url` y `expires_at`.
- `POST /api/v1/kyc/webhooks/ballerine` es un receptor aislado sin identidad de cliente. Autentica `X-Authorization` con `SEDERG_BALLERINE_WEBHOOK_SECRET` o una firma HMAC `X-Ballerine-Signature`, valida la ventana temporal y aplica idempotencia por `provider_event_id` en `kyc_provider_events`.
- Los eventos `approved`/`rejected` de Ballerine se mapean a `pending_verification`; nunca aprueban solos. Estados desconocidos se guardan en cuarentena. El administrador conserva la decisión final.
- Ejecute las migraciones para añadir las columnas de proveedor y la tabla de eventos: `php yii migrate --migrationPath=@app/migrations`.
- El workflow de Ballerine debe configurar su suscripción webhook hacia `/api/v1/kyc/webhooks/ballerine` y compartir el mismo `WEBHOOK_SECRET`.
- Verificación operativa antes de activar: `php yii kyc/provider-status` y `GET /api/v1/admin/kyc/provider`. El panel admin muestra el estado del proveedor, el último evento y las excepciones de las últimas 24 horas.
- Runbook de activación y rollback: `../docs/ballerine-kyc-activation.md`.
- Detalles de contrato, variables y límites: `docs/ballerine-kyc-spike/INTEGRATION_CONTRACT.md` y `docs/adr/001-ballerine-kyc-core.md`.

### KYC hardening and deployment

- KYC uploads are validated against actual MIME, file size (1 byte–5 MB), and file signatures. The whole path must allow 5 MB: nginx uses `client_max_body_size 10m` and PHP uses `upload_max_filesize=8M`/`post_max_size=10M` from `docker/php-uploads.ini`. Their defaults (1 MB and 2 MB) rejected valid uploads with 413 or a misleading message. Documents are scanned through ClamAV using the daemon INSTREAM protocol before encryption and storage.
- Upload regression check through the portal (`nginx -> backend -> ClamAV`): `docker compose exec -T backend php tests/integration/kyc_upload_smoke.php`. It verifies a 3 MB file is accepted and a 6 MB file is rejected with the 5 MB message.
- The Compose stack includes an internal-only ClamAV service with a persistent virus-definition volume. It is not published to the host.
- Production must set a dedicated random `SEDERG_PII_KEY` (at least 32 characters), `SEDERG_KYC_REQUIRED=1`, and must keep the private storage volume and encryption key in protected backups. Never reuse `SEDERG_COOKIE_KEY` as the document encryption key.
- Malware scanning fails closed whenever KYC is required or auth mode is not development. If ClamAV is down, uploads are rejected rather than accepted without scanning.
- Administrative KYC downloads are attachment-only, disable caching, validate path containment, decrypt, verify SHA-256 and create an audit event.
- Configure backup retention, access controls and a documented key-rotation procedure before onboarding real customers. Key rotation must re-encrypt all retained files; simply replacing the key makes existing files unreadable.
- These controls are technical safeguards, not a determination that KYC/AML or privacy obligations in Russia and Cuba have been satisfied. Legal, data-localization, retention, sanctions-screening and reporting requirements must be confirmed separately for each corridor.
