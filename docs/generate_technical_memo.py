# -*- coding: utf-8 -*-
"""Genera la memoria técnica descriptiva de SEDERG_PAY en formato DOCX."""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from doc_common import (
    AMBER,
    BLUE,
    BLUE_HEX,
    CYAN,
    CYAN_HEX,
    DARK,
    GREEN,
    GREEN_HEX,
    LIGHT_HEX,
    MID,
    NAVY,
    RED,
    SPACER,
    TBL,
    BUL,
    CALL,
    CODE,
    H1,
    H2,
    H3,
    IMAGE,
    KPI,
    NOTE,
    P,
    PR,
    STEPS,
    TOC,
    add_cover,
    new_document,
    set_core_properties,
    setup_header_footer,
)

HERE = os.path.dirname(os.path.abspath(__file__))
ASSETS = os.path.join(HERE, "assets")
OUT = os.path.join(HERE, "..", "SEDERG_PAY_Memoria_Tecnica.docx")


def build() -> str:
    doc = new_document()
    setup_header_footer(
        doc.sections[0],
        header_left="SEDERG_PAY",
        header_right="Memoria técnica · v1.0",
        footer_left="Documento interno — commit e201a38",
    )
    set_core_properties(
        doc,
        title="SEDERG_PAY — Memoria técnica",
        subject="Arquitectura, módulos, modelo de datos y operación",
        keywords="memoria técnica, Yii2, PHP, React, PostgreSQL, remesas, seguridad",
        comments="Memoria técnica generada a partir del repositorio sedergsoft/SedergPay_oficial",
    )

    add_cover(
        doc,
        doc_kicker="Memoria técnica",
        title="SEDERG_PAY",
        subtitle="Arquitectura, módulos, modelo de datos y operación de la plataforma",
        doc_type="Memoria técnica descriptiva del proyecto",
        meta=[
            ("Preparado por", "Equipo técnico SEDERG_PAY"),
            ("Versión", "1.0"),
            ("Fecha", "Noviembre de 2026"),
            ("Repositorio", "sedergsoft/SedergPay_oficial"),
            ("Rama / commit", "main · e201a38"),
            ("Clasificación", "Interno — documentación de proyecto"),
        ],
        footer_note=(
            "Documento descriptivo del estado real del repositorio. Distingue entre "
            "componentes implementados, simulados y pendientes. No sustituye a la "
            "documentación contractual ni a los procedimientos operativos aprobados."
        ),
    )

    # --------------------------------------------------- Control del documento
    H1(doc, "Control del documento", page_break=False)
    TBL(
        doc,
        ["Campo", "Valor"],
        [
            ["Título", "SEDERG_PAY — Memoria técnica descriptiva del proyecto"],
            ["Versión", "1.0"],
            ["Estado", "Vigente"],
            ["Fecha de emisión", "Noviembre de 2026"],
            ["Autor", "Equipo técnico SEDERG_PAY"],
            ["Repositorio", "https://github.com/sedergsoft/SedergPay_oficial"],
            ["Rama", "main"],
            ["Commit de referencia", "e201a38"],
            ["Licencia", "Propietaria"],
            ["Clasificación", "Interno"],
        ],
        widths=[4.4, 11.6],
        font_size=9.0,
        first_col_bold=True,
    )
    SPACER(doc, 4)
    H2(doc, "Historial de versiones")
    TBL(
        doc,
        ["Versión", "Fecha", "Cambio", "Autor"],
        [
            ["1.0", "Noviembre de 2026", "Emisión inicial a partir del commit e201a38", "Equipo técnico"],
        ],
        widths=[2.0, 4.0, 8.0, 2.5],
        font_size=8.8,
        align_center_cols=(0,),
    )
    SPACER(doc, 4)
    CALL(
        doc,
        "Alcance del documento",
        "Esta memoria describe la arquitectura, los módulos funcionales, el modelo de "
        "datos, la API, los controles de seguridad, el frontend, el despliegue, la "
        "automatización y la deuda técnica del repositorio. Las afirmaciones se basan "
        "en el código y la documentación existentes en el commit indicado.",
        fill="E8F1FA",
        border=CYAN_HEX,
    )

    # ------------------------------------------------------------------ Índice
    H1(doc, "Índice")
    TOC(
        doc,
        [
            (0, "1. Resumen ejecutivo técnico"),
            (0, "2. Alcance y objetivos"),
            (0, "3. Arquitectura del sistema"),
            (1, "3.1 Vista general por capas"),
            (1, "3.2 Patrones de diseño aplicados"),
            (0, "4. Stack tecnológico"),
            (0, "5. Módulos funcionales"),
            (0, "6. Modelo de datos"),
            (1, "6.1 Dominios y tablas"),
            (1, "6.2 Convenciones y controles"),
            (0, "7. API REST"),
            (0, "8. Seguridad"),
            (1, "8.1 Controles implementados"),
            (1, "8.2 Limitaciones conocidas"),
            (0, "9. Frontend"),
            (0, "10. Despliegue e infraestructura"),
            (0, "11. CI/CD y calidad"),
            (0, "12. Riesgos, limitaciones y deuda técnica"),
            (0, "13. Hoja de ruta técnica"),
            (0, "14. Anexos"),
            (1, "A. Catálogo de rutas"),
            (1, "B. Inventario de tablas"),
            (1, "C. Variables de entorno"),
            (1, "D. Glosario técnico"),
        ],
    )

    # --------------------------------------------------- 1. Resumen
    H1(doc, "1. Resumen ejecutivo técnico")
    P(
        doc,
        "SEDERG_PAY es una plataforma de gestión, control y conciliación de remesas "
        "Rusia ↔ Cuba. El sistema se compone de un backend Yii2 sobre PHP 8.3, un "
        "frontend React 18 con TypeScript y Vite, y una base de datos PostgreSQL 17 "
        "(con despliegue previsto en TiDB Cloud Starter, compatible con PostgreSQL).",
        align="justify",
    )
    P(
        doc,
        "El diseño separa las reglas de negocio del framework: pricing, máquina de "
        "estados de remesas y validación de doble entrada viven en clases de dominio "
        "independientes y se cubren con pruebas unitarias. La API expone 53 rutas REST "
        "versionadas bajo /api/v1. El modelo físico consta de 37 tablas en nueve "
        "dominios, gestionadas mediante 16 migraciones idempotentes.",
        align="justify",
    )
    KPI(
        doc,
        [
            ("16", "MIGRACIONES"),
            ("37", "TABLAS"),
            ("16", "CONTROLADORES"),
            ("53", "RUTAS REST"),
            ("27", "PRUEBAS UNITARIAS"),
        ],
        fills=[LIGHT_HEX, "F5F8FC", LIGHT_HEX, "F5F8FC", LIGHT_HEX],
    )
    TBL(
        doc,
        ["Indicador", "Valor", "Observación"],
        [
            ["Lenguaje backend", "PHP 8.3", "Extensiones bcmath, openssl, pdo_pgsql, fileinfo"],
            ["Framework backend", "Yii2 2.0.52", "API REST/JSON versionada"],
            ["Frontend", "React 18.3 + TypeScript 5.6 + Vite 5.4", "SPA con 9 vistas e i18n ES/RU"],
            ["Base de datos", "PostgreSQL 17 / TiDB Cloud", "DECIMAL, FK con CASCADE, triggers updated_at"],
            ["Caché y colas", "Redis 7", "Previsto en docker-compose y despliegue"],
            ["Pruebas", "27 unitarias + 1 smoke de integración", "PHPUnit 10.5"],
            ["Idiomas", "Español y ruso", "Interfaz, formatos y mensajes"],
        ],
        widths=[3.6, 5.6, 7.2],
        font_size=8.6,
        first_col_bold=True,
    )
    NOTE(
        doc,
        "El backend público aún no está desplegado; el frontend se publica en modo "
        "demostración y las integraciones bancarias son simuladas. Esta distinción se "
        "detalla en las secciones 10 y 12.",
    )

    # --------------------------------------------------- 2. Alcance
    H1(doc, "2. Alcance y objetivos")
    P(
        doc,
        "El objetivo del proyecto es construir un MVP con datos reales de clientes y "
        "operaciones de negocio que permita recorrer el ciclo completo de una remesa: "
        "cotización, solicitud, revisión de cumplimiento, depósito, ledger, pago y "
        "conciliación. Los depósitos, pagos y bancos son simulados en esta etapa.",
        align="justify",
    )
    TBL(
        doc,
        ["Dentro del alcance", "Fuera del alcance (por ahora)"],
        [
            ["Registro, verificación de correo y autenticación con 2FA", "Movimiento de dinero real y custodia de fondos"],
            ["Perfil, KYC y revisión documental", "Conexión con bancos y proveedores de pago productivos"],
            ["Beneficiarios y métodos de fondeo/entrega", "Operación 24/7 con acuerdos de nivel de servicio"],
            ["Cotización con tasa, comisión y límites", "Cumplimiento regulatorio de jurisdicciones específicas"],
            ["Máquina de estados y eventos de remesa", "Aplicaciones móviles nativas"],
            ["Ledger de doble entrada e idempotencia", "Reportería regulatoria oficial"],
            ["Conciliación y casos de excepción", "Integración con sistemas core bancarios"],
            ["Panel administrativo y auditoría", "Alta disponibilidad multi-región"],
        ],
        widths=[8.0, 8.0],
        font_size=8.6,
    )
    CALL(
        doc,
        "Principio rector",
        "No se moverá dinero real ni se conectarán bancos productivos antes de superar "
        "los gates jurídicos, contractuales, regulatorios y operativos definidos en el "
        "plan de acción del proyecto.",
        fill="FFF4E5",
        border="F0A400",
    )

    # --------------------------------------------------- 3. Arquitectura
    H1(doc, "3. Arquitectura del sistema")
    H2(doc, "3.1 Vista general por capas")
    P(
        doc,
        "La arquitectura sigue un modelo por capas con dependencias dirigidas hacia el "
        "dominio. El frontend consume la API REST; la API orquesta casos de uso; el "
        "dominio concentra las reglas; la infraestructura implementa persistencia, "
        "seguridad, correo y adaptadores externos.",
        align="justify",
    )
    IMAGE(
        doc,
        os.path.join(ASSETS, "arquitectura.png"),
        "Figura 1. Arquitectura general de SEDERG_PAY.",
    )
    TBL(
        doc,
        ["Capa", "Responsabilidad", "Artefactos principales"],
        [
            ["Presentación", "Interfaz del cliente y panel administrativo", "frontend/src/App.tsx, api.ts, i18n.ts, styles.css"],
            ["API", "Exposición REST, validación de entrada y autorización", "backend/controllers/*, config/web.php"],
            ["Dominio", "Reglas de pricing, estados, ledger y cotizaciones", "backend/src/Domain/*"],
            ["Infraestructura", "Persistencia, autenticación, cifrado, correo y proveedores", "backend/src/Infrastructure/*"],
            ["Datos", "Almacenamiento transaccional y contable", "migraciones, PostgreSQL/TiDB, Redis"],
            ["Integraciones", "Adaptadores externos desacoplados", "tasas, SMTP, QR/SBP, OIDC"],
        ],
        widths=[3.0, 6.2, 7.2],
        font_size=8.6,
        first_col_bold=True,
    )

    H2(doc, "3.2 Patrones de diseño aplicados")
    BUL(
        doc,
        [
            "Separación por capas: controladores delgados que delegan en servicios y "
            "clases de dominio.",
            "Máquina de estados explícita para remesas, con transiciones permitidas y "
            "rechazo de saltos inválidos.",
            "Ledger de doble entrada: cada transacción exige al menos dos asientos y "
            "cuadre por moneda.",
            "Idempotencia mediante claves por operación para evitar duplicados por "
            "reintentos.",
            "Patrón outbox para publicar eventos de forma consistente con la transacción.",
            "Adaptadores para proveedores de pago, tasas y identidad, sustituibles sin "
            "modificar el dominio.",
            "Identificadores públicos UUID para no exponer claves secuenciales.",
        ],
    )

    # --------------------------------------------------- 4. Stack
    H1(doc, "4. Stack tecnológico")
    TBL(
        doc,
        ["Componente", "Tecnología", "Versión", "Notas"],
        [
            ["Backend", "PHP", "8.3", "Requiere bcmath, openssl, pdo_pgsql, fileinfo"],
            ["Framework", "Yii2", "2.0.52", "API REST/JSON, migraciones, seguridad"],
            ["Frontend", "React", "18.3", "Componentes funcionales y contexto de idioma"],
            ["Lenguaje frontend", "TypeScript", "5.6", "Tipado estricto en build"],
            ["Bundler", "Vite", "5.4", "Build SPA y variables de entorno"],
            ["Base de datos", "PostgreSQL", "17", "Esquema compatible con TiDB Cloud Starter"],
            ["Caché / colas", "Redis", "7", "Sesiones, caché y procesamiento asíncrono"],
            ["Antivirus", "ClamAV", "stable", "Análisis de documentos KYC"],
            ["Pruebas", "PHPUnit", "10.5", "Pruebas unitarias del dominio"],
            ["Contenedores", "Docker / docker-compose", "—", "Entorno local reproducible"],
            ["CI/CD", "GitHub Actions", "—", "Sintaxis, pruebas, despliegue y migraciones"],
            ["Hosting frontend", "GitHub Pages", "—", "SPA estática"],
            ["Hosting backend", "Northflank Sandbox", "Free", "Contenedor Docker, sin tarjeta"],
            ["Correo", "Brevo SMTP", "—", "Puertos 587/465/2525"],
            ["Identidad", "Keycloak / OIDC", "—", "JWT RS256 en producción"],
        ],
        widths=[3.0, 3.8, 2.2, 7.4],
        font_size=8.2,
        first_col_bold=True,
        align_center_cols=(2,),
    )

    # --------------------------------------------------- 5. Módulos
    H1(doc, "5. Módulos funcionales")
    P(
        doc,
        "El backend se organiza en dieciséis controladores. Cada uno agrupa las rutas de "
        "un ámbito funcional y delega las reglas en el dominio o en servicios de "
        "infraestructura.",
        align="justify",
    )
    TBL(
        doc,
        ["Controlador", "Responsabilidad", "Rutas principales"],
        [
            ["AuthController", "Login, verificación de correo, reset y 2FA de acceso", "/auth/*"],
            ["SecurityController", "Gestión de contraseña y 2FA de la cuenta", "/account/security/*"],
            ["RegistrationController", "Alta de cliente y consentimientos", "/registrations"],
            ["ProfileController", "Consulta y edición del perfil", "/profile"],
            ["KycController", "Estado KYC, envío y carga de documentos", "/kyc/*"],
            ["BeneficiaryController", "CRUD de beneficiarios propios", "/beneficiaries/*"],
            ["QuoteController", "Creación y consulta de cotizaciones", "/quotes/*"],
            ["RemittanceController", "Creación, consulta y seguimiento de remesas", "/remittances/*"],
            ["FundingController", "Métodos de fondeo y cuentas receptoras", "/funding-methods/*, /admin/funding-*"],
            ["PaymentController", "Confirmación simulada de depósitos", "/deposits/*"],
            ["ComplianceController", "Revisión AML/CTF de remesas", "/remittances/*/compliance-review"],
            ["PayoutController", "Órdenes y finalización simulada de pagos", "/remittances/*/payout, /payouts/*"],
            ["ReconciliationController", "Cierre y conciliación de remesas", "/remittances/*/reconcile"],
            ["ExchangeRateController", "Tasa de cambio vigente", "/exchange-rates/current"],
            ["AdminController", "Usuarios, tasas, KYC, ajustes y dashboard", "/admin/*"],
            ["HealthController", "Disponibilidad de API y base de datos", "/health"],
        ],
        widths=[3.6, 7.2, 5.6],
        font_size=8.2,
        first_col_bold=True,
    )
    H2(doc, "Dominio")
    P(
        doc,
        "Las reglas críticas se implementan en clases independientes del framework, lo "
        "que permite probarlas sin base de datos ni HTTP:",
        align="justify",
    )
    TBL(
        doc,
        ["Clase de dominio", "Función"],
        [
            ["PricingPolicy", "Valida comisión, importes mínimo/máximo y límites por corredor"],
            ["QuoteCalculator", "Calcula importe a recibir, comisión y vigencia de la cotización"],
            ["RemittanceStateMachine", "Define y valida las transiciones de estado permitidas"],
            ["DoubleEntryValidator", "Verifica que cada transacción contable esté balanceada por moneda"],
        ],
        widths=[4.6, 11.4],
        font_size=8.8,
        first_col_bold=True,
    )
    CODE(
        doc,
        "awaiting_payment → compliance_review → approved → funds_confirmed\n"
        "→ payout_pending → paid → reconciliation_pending → completed\n"
        "Alternativas: rejected · cancelled · payout_failed · reconciliation_exception",
    )

    # --------------------------------------------------- 6. Modelo de datos
    H1(doc, "6. Modelo de datos")
    H2(doc, "6.1 Dominios y tablas")
    P(
        doc,
        "El modelo físico tiene 37 tablas agrupadas en nueve dominios. La separación "
        "facilita la evolución del esquema y la asignación de responsabilidades.",
        align="justify",
    )
    IMAGE(
        doc,
        os.path.join(ASSETS, "modelo_datos.png"),
        "Figura 2. Agrupación del modelo de datos en nueve dominios.",
    )
    TBL(
        doc,
        ["Dominio", "Tablas", "Propósito"],
        [
            ["Identidad y acceso", "9", "Países, monedas, usuarios, perfiles, consentimientos, tokens, desafíos e idempotencia"],
            ["Beneficiarios y métodos", "5", "Beneficiarios, métodos de pago y cuentas receptoras de la plataforma"],
            ["Precios y cotizaciones", "2", "Tasas de cambio y cotizaciones versionadas"],
            ["Núcleo de remesas", "3", "Remesas, eventos de estado y revisiones de cumplimiento"],
            ["Fondeo y conciliación", "6", "Conexiones bancarias, depósitos, webhooks, transacciones, casos y cursores"],
            ["Payouts", "2", "Órdenes e intentos de pago al beneficiario"],
            ["Ledger contable", "5", "Cuentas, transacciones, asientos, wallets y su ledger"],
            ["Operación y auditoría", "3", "Ajustes de plataforma, outbox y registro de auditoría"],
            ["KYC", "2", "Expedientes y documentos de verificación de identidad"],
        ],
        widths=[3.8, 2.0, 10.2],
        font_size=8.6,
        first_col_bold=True,
        align_center_cols=(1,),
    )

    H2(doc, "6.2 Convenciones y controles")
    BUL(
        doc,
        [
            "Claves primarias técnicas y un identificador público UUID en las entidades "
            "expuestas, para evitar enumeración de recursos.",
            "Claves foráneas con integridad referencial y políticas CASCADE donde "
            "corresponde.",
            "Importes en DECIMAL con moneda explícita; nunca en punto flotante.",
            "Triggers de updated_at añadidos en la migración específica para PostgreSQL.",
            "Migraciones idempotentes y reversibles, ejecutables en CI y en despliegue.",
            "Tabla de claves de idempotencia y tabla de eventos de estado para "
            "trazabilidad.",
            "Índices únicos en correo, teléfono, identificadores públicos y hashes de "
            "tokens.",
        ],
    )
    NOTE(
        doc,
        "Las 16 migraciones cubren desde el esquema base hasta los módulos de seguridad "
        "de cuenta, fondeo y KYC. La migración inicial concentra el núcleo del modelo; "
        "las siguientes incorporan controles MVP, seeds y ampliaciones por dominio.",
    )

    # --------------------------------------------------- 7. API
    H1(doc, "7. API REST")
    P(
        doc,
        "La API es REST/JSON, versionada bajo /api/v1, con respuestas JSON y códigos "
        "HTTP estándar. La autenticación se resuelve con OIDC en producción y con una "
        "cabecera de identidad de desarrollo cuando SEDERG_AUTH_MODE=development.",
        align="justify",
    )
    TBL(
        doc,
        ["Grupo", "Rutas", "Descripción"],
        [
            ["Salud", "1", "GET /health: disponibilidad de API y base de datos"],
            ["Autenticación", "7", "Login, verificación, reset, 2FA y datos de sesión"],
            ["Seguridad de cuenta", "5", "Cambio de contraseña y gestión de 2FA"],
            ["Registro", "1", "Alta de cliente con consentimientos"],
            ["Perfil", "2", "Consulta y edición de datos personales"],
            ["KYC", "3", "Estado, envío y carga de documentos"],
            ["Beneficiarios", "4", "Alta, listado, consulta y edición"],
            ["Cotizaciones", "2", "Creación y consulta"],
            ["Remesas", "7", "Creación, consulta, depósito, cumplimiento, payout y conciliación"],
            ["Depósitos y payouts", "2", "Confirmación y finalización simuladas"],
            ["Fondeo", "6", "Métodos por país y administración de cuentas"],
            ["Administración", "12", "Usuarios, tasas, KYC, ajustes, dashboard y operaciones"],
            ["Tasas de cambio", "1", "Tasa vigente"],
        ],
        widths=[3.8, 2.0, 10.2],
        font_size=8.4,
        first_col_bold=True,
        align_center_cols=(1,),
    )
    P(doc, "Convenciones relevantes:", bold=True, space_before=4)
    BUL(
        doc,
        [
            "Recursos expuestos por identificador público UUID, no por clave interna.",
            "Operaciones sensibles protegidas por idempotencia y auditoría.",
            "Validación de entrada en el controlador y reglas de negocio en el dominio.",
            "Aislamiento por usuario: cada consulta verifica la propiedad del recurso.",
            "CORS restringido por lista de orígenes permitidos.",
        ],
    )
    CODE(
        doc,
        "POST /api/v1/quotes\n"
        "Authorization: Bearer <token OIDC>        (producción)\n"
        "X-User-Public-Id: <uuid>                  (solo development)\n"
        "Content-Type: application/json\n\n"
        "{ \"send_amount\": \"25000.00\", \"send_currency\": \"RUB\",\n"
        "  \"receive_currency\": \"CUP\", \"beneficiary_public_id\": \"…\" }",
    )

    # --------------------------------------------------- 8. Seguridad
    H1(doc, "8. Seguridad")
    H2(doc, "8.1 Controles implementados")
    TBL(
        doc,
        ["Control", "Implementación", "Estado"],
        [
            ["Autenticación productiva", "OIDC con JWT RS256, validación de issuer, audience y firma", "Implementado"],
            ["Identidad de desarrollo", "Cabecera X-User-Public-Id habilitada solo con SEDERG_AUTH_MODE=development", "Implementado"],
            ["Segundo factor", "TOTP RFC 6238, 30 s, 6 dígitos, ventana ±1", "Implementado"],
            ["Códigos de recuperación", "8 códigos de un solo uso almacenados como hash SHA-256", "Implementado"],
            ["Contraseñas", "Hash bcrypt y cambio autenticado", "Implementado"],
            ["Reset y verificación", "Tokens de 64 hex con hash SHA-256, expiración y consumo único", "Implementado"],
            ["Cifrado de PII", "encryptByKey (AES-256-CBC + HMAC) con SEDERG_PII_KEY", "Implementado"],
            ["Cifrado de cuentas", "encryptByKey con SEDERG_ACCOUNT_ENCRYPTION_KEY y enmascarado", "Implementado"],
            ["Documentos KYC", "Cifrado en reposo y análisis antimalware con ClamAV", "Implementado"],
            ["Auditoría", "Registro de acciones sensibles y eventos de estado", "Implementado"],
            ["Idempotencia", "Claves por operación y recurso", "Implementado"],
            ["Autorización por rol", "Roles de usuario y verificación en rutas administrativas", "Implementado"],
            ["CORS", "Lista de orígenes permitidos por variable de entorno", "Implementado"],
        ],
        widths=[3.6, 10.0, 2.4],
        font_size=8.2,
        first_col_bold=True,
        align_center_cols=(2,),
    )

    H2(doc, "8.2 Limitaciones conocidas")
    TBL(
        doc,
        ["Limitación", "Impacto", "Recomendación"],
        [
            ["Sin límite de intentos de login", "Riesgo de fuerza bruta sobre credenciales", "Añadir rate limiting y bloqueo progresivo antes del piloto"],
            ["Secreto TOTP sin cifrar", "Exposición si se compromete la base de datos", "Cifrar el secreto con la clave de PII y migrar datos existentes"],
            ["Tokens en almacenamiento local del navegador", "Mayor superficie ante XSS", "Evaluar cookies HttpOnly y CSP estricta"],
            ["Integraciones bancarias simuladas", "No hay validación con un proveedor real", "Implementar adaptadores y pruebas de conciliación"],
            ["TiDB Cloud Starter en Public Preview", "Cambios de comportamiento del proveedor", "Fijar versión, monitorear y prever plan de contingencia"],
            ["Backend público no desplegado", "La demo no ejecuta la API real", "Completar el despliegue en Northflank"],
            ["Almacenamiento efímero en planes gratuitos", "Pérdida de archivos o logs al reiniciar", "Usar almacenamiento persistente para KYC y auditoría"],
        ],
        widths=[4.2, 5.8, 6.0],
        font_size=8.2,
        first_col_bold=True,
    )
    CALL(
        doc,
        "Postura de seguridad",
        "Los controles de identidad, cifrado, auditoría e idempotencia están presentes en "
        "el código. Las limitaciones anteriores son conocidas y están priorizadas en la "
        "hoja de ruta; ninguna de ellas se oculta al cliente.",
        fill="FFF4E5",
        border="F0A400",
    )

    # --------------------------------------------------- 9. Frontend
    H1(doc, "9. Frontend")
    P(
        doc,
        "El frontend es una SPA en React 18 con TypeScript y Vite. Se organiza en un "
        "componente principal, un cliente API tipado, un módulo de internacionalización y "
        "una hoja de estilos extensa con variables de tema.",
        align="justify",
    )
    TBL(
        doc,
        ["Vista", "Propósito"],
        [
            ["summary", "Resumen del cliente, tasa vigente y accesos rápidos"],
            ["beneficiaries", "Alta, listado y edición de beneficiarios"],
            ["quote", "Cotizador con tasa, comisión, límites y vigencia"],
            ["remittances", "Historial y detalle del estado de cada remesa"],
            ["profile", "Datos personales y de contacto"],
            ["security", "Cambio de contraseña, 2FA y códigos de recuperación"],
            ["kyc", "Estado del expediente y carga de documentos"],
            ["admin", "Panel de usuarios, tasas, KYC, ajustes y operaciones"],
            ["funding", "Métodos de fondeo e instrucciones de pago"],
        ],
        widths=[3.2, 12.8],
        font_size=8.8,
        first_col_bold=True,
    )
    BUL(
        doc,
        [
            "Internacionalización ES/RU con formato local de fechas e importes.",
            "Tema claro y oscuro persistido en el navegador.",
            "Cliente API centralizado con inyección de identidad o token según el modo.",
            "Modo demostración (VITE_UI_PREVIEW) que permite explorar la interfaz sin "
            "backend real.",
            "Diseño responsive para escritorio y dispositivos móviles.",
        ],
    )
    NOTE(
        doc,
        "El frontend está publicado en https://sedergsoft.github.io/SedergPay_oficial/ en "
        "modo preview. Al no estar desplegado el backend público, las operaciones no se "
        "persisten contra la API real.",
    )

    # --------------------------------------------------- 10. Despliegue
    H1(doc, "10. Despliegue e infraestructura")
    P(
        doc,
        "El despliegue previsto busca validar el MVP sin coste inicial, manteniendo la "
        "posibilidad de migrar a infraestructura productiva cuando el proyecto lo "
        "requiera.",
        align="justify",
    )
    TBL(
        doc,
        ["Componente", "Servicio", "Plan", "Estado"],
        [
            ["Base de datos", "TiDB Cloud Starter (PostgreSQL-compatible)", "Free", "Migrada"],
            ["Frontend", "GitHub Pages", "Gratis", "Publicado en preview"],
            ["Backend API", "Northflank Sandbox (Docker)", "Free", "Pendiente"],
            ["Caché / colas", "Upstash Redis", "Free", "Previsto"],
            ["Correo", "Brevo SMTP", "Free", "Configurado"],
            ["Identidad", "Keycloak / OIDC", "—", "Previsto para producción"],
        ],
        widths=[3.2, 6.4, 2.6, 3.8],
        font_size=8.4,
        first_col_bold=True,
        align_center_cols=(2, 3),
    )
    H2(doc, "Entorno local")
    P(
        doc,
        "docker-compose levanta frontend, backend, PostgreSQL 17, Redis 7, ClamAV y el "
        "actualizador de tasas. El backend se construye con un Dockerfile basado en "
        "php:8.3-cli y se sirve con el servidor embebido de Yii sobre el directorio web.",
        align="justify",
    )
    CODE(
        doc,
        "docker compose up -d\n"
        "cd backend && composer install\n"
        "php yii migrate --interactive=0\n"
        "cd ../frontend && npm ci && npm run dev",
    )
    H2(doc, "Variables de entorno")
    P(
        doc,
        "La configuración se inyecta por variables de entorno. Los secretos nunca deben "
        "versionarse; .env.example solo contiene valores de ejemplo y marcadores.",
        align="justify",
    )
    CODE(
        doc,
        "SEDERG_DB_DSN=pgsql:host=…;port=5432;dbname=sederg_pay\n"
        "SEDERG_DB_USER=…\n"
        "SEDERG_DB_PASSWORD=…\n"
        "SEDERG_AUTH_MODE=development|oidc\n"
        "SEDERG_COOKIE_KEY=…\n"
        "SEDERG_PII_KEY=…\n"
        "SEDERG_ACCOUNT_ENCRYPTION_KEY=…\n"
        "SEDERG_MAIL_*=…\n"
        "SEDERG_OIDC_ISSUER=…\n"
        "SEDERG_OIDC_AUDIENCE=…\n"
        "SEDERG_OIDC_PUBLIC_KEY=…\n"
        "SEDERG_CORS_ORIGINS=…\n"
        "VITE_API_BASE_URL=…\n"
        "VITE_AUTH_MODE=development|oidc\n"
        "VITE_UI_PREVIEW=true|false",
    )
    CALL(
        doc,
        "Estado del despliegue",
        "La base de datos TiDB está migrada y el frontend está publicado en modo "
        "demostración. El backend público se encuentra en proceso de despliegue en "
        "Northflank; hasta que esté disponible, la demo no ejecuta operaciones reales "
        "contra la API.",
        fill="E8F1FA",
        border=CYAN_HEX,
    )

    # --------------------------------------------------- 11. CI/CD
    H1(doc, "11. CI/CD y calidad")
    P(
        doc,
        "La automatización se implementa con GitHub Actions. Cada flujo cubre una parte "
        "del ciclo: calidad del backend, integración con PostgreSQL, despliegue del "
        "frontend, despliegue del backend y migraciones de base de datos.",
        align="justify",
    )
    TBL(
        doc,
        ["Workflow", "Disparador", "Acciones"],
        [
            ["ci.yml", "Pull request a main o rama de migración, y push a la rama de migración", "Sintaxis PHP, PHPUnit y pruebas de integración PostgreSQL"],
            ["deploy-pages.yml", "Push a main con cambios en frontend", "Build de la SPA y publicación en GitHub Pages"],
            ["deploy.yml", "Push a main con cambios de backend y ejecución manual", "Despliegue opcional en VM gratuita por SSH (condicionado a VM_DEPLOY)"],
            ["migrate-db.yml", "Solo ejecución manual", "Migraciones controladas contra TiDB Cloud"],
        ],
        widths=[3.4, 5.4, 7.2],
        font_size=8.6,
        first_col_bold=True,
    )
    H2(doc, "Cobertura de pruebas")
    P(
        doc,
        "El repositorio contiene 27 pruebas unitarias distribuidas en nueve archivos, "
        "más un smoke test de integración del flujo de cotización. Las pruebas se "
        "concentran en las reglas de mayor riesgo: pricing, estados, ledger, límites del "
        "corredor RUB, seguridad documental y proveedores de pago.",
        align="justify",
    )
    TBL(
        doc,
        ["Archivo de prueba", "Ámbito"],
        [
            ["PricingPolicyTest", "Comisión, límites y validaciones de importe"],
            ["QuoteCalculatorTest", "Cálculo de importe a recibir y vigencia"],
            ["QuoteServiceTest", "Orquestación de cotizaciones"],
            ["RemittanceStateMachineTest", "Transiciones válidas e inválidas"],
            ["DoubleEntryValidatorTest", "Cuadre contable por moneda"],
            ["RubCorridorLimitsTest", "Límites específicos del corredor RUB"],
            ["KycDocumentSecurityTest", "Validación y seguridad documental"],
            ["FundingPaymentProviderFactoryTest", "Selección de proveedor de fondeo"],
            ["SbpSandboxQrProviderTest", "Generación de QR SBP en sandbox"],
            ["quote_flow_smoke.php", "Smoke test de integración"],
        ],
        widths=[6.0, 10.0],
        font_size=8.6,
        first_col_bold=True,
    )
    NOTE(
        doc,
        "No hay todavía pruebas end-to-end de interfaz ni umbral de cobertura "
        "obligatorio. Ambas son mejoras previstas antes de un piloto con usuarios reales.",
    )

    # --------------------------------------------------- 12. Riesgos
    H1(doc, "12. Riesgos, limitaciones y deuda técnica")
    P(
        doc,
        "La siguiente matriz resume los riesgos técnicos y operativos identificados, su "
        "impacto y la mitigación prevista.",
        align="justify",
    )
    TBL(
        doc,
        ["Riesgo", "Impacto", "Mitigación", "Estado"],
        [
            ["Ausencia de rate limiting en login", "Alto", "Límite por IP y usuario, bloqueo progresivo", "Pendiente"],
            ["Secreto TOTP sin cifrar", "Alto", "Cifrado con clave de PII y rotación", "Pendiente"],
            ["Integraciones bancarias simuladas", "Alto", "Adaptadores reales y conciliación verificada", "Simulado"],
            ["Backend público no desplegado", "Medio", "Completar Northflank y verificar E2E", "Pendiente"],
            ["TiDB Cloud Starter en Public Preview", "Medio", "Fijar versión y plan de contingencia", "Vigilancia"],
            ["Almacenamiento efímero en planes gratuitos", "Medio", "Almacenamiento persistente para KYC y logs", "Pendiente"],
            ["Sin pruebas E2E de interfaz", "Medio", "Añadir Playwright o equivalente", "Pendiente"],
            ["Dependencia de SMTP externo", "Bajo", "Reintentos, outbox y proveedor alternativo", "Mitigado parcialmente"],
            ["Operación en una sola región", "Medio", "Plan de continuidad y respaldos verificados", "Pendiente"],
            ["Tokens en localStorage", "Medio", "Cookies HttpOnly y CSP estricta", "Pendiente"],
        ],
        widths=[4.6, 2.0, 6.8, 3.0],
        font_size=8.0,
        first_col_bold=True,
        align_center_cols=(1, 3),
    )

    # --------------------------------------------------- 13. Hoja de ruta
    H1(doc, "13. Hoja de ruta técnica")
    TBL(
        doc,
        ["Fase", "Objetivos técnicos", "Criterio de salida"],
        [
            ["Consolidación sandbox", "Desplegar backend, estabilizar API y demo E2E", "API pública estable y flujo completo verificado"],
            ["Endurecimiento", "Rate limiting, cifrado TOTP, CSP, pruebas E2E", "Revisión de seguridad sin hallazgos críticos"],
            ["Piloto cerrado", "Métricas, respaldos, monitoreo y soporte", "Gates legales y operativos superados"],
            ["Integraciones", "Fondeo y payout con proveedores autorizados", "Conciliación y contratos aprobados"],
            ["Producción", "Alta disponibilidad, auditoría externa y operación 24/7", "Autorizaciones regulatorias vigentes"],
        ],
        widths=[3.6, 7.0, 5.4],
        font_size=8.4,
        first_col_bold=True,
    )

    # --------------------------------------------------- 14. Anexos
    H1(doc, "14. Anexos")
    H2(doc, "A. Catálogo de rutas")
    P(
        doc,
        "Las 53 rutas de la API, agrupadas por ámbito funcional. Todas las rutas de "
        "negocio se sirven bajo /api/v1 salvo el endpoint de salud.",
        align="justify",
    )
    TBL(
        doc,
        ["Método y ruta", "Acción"],
        [
            ["GET /health", "Salud de API y base de datos"],
            ["POST /api/v1/auth/login", "Inicio de sesión"],
            ["POST /api/v1/auth/verify-email", "Verificación de correo"],
            ["POST /api/v1/auth/resend-verification", "Reenvío de verificación"],
            ["POST /api/v1/auth/forgot-password", "Solicitud de recuperación"],
            ["POST /api/v1/auth/reset-password", "Restablecimiento de contraseña"],
            ["POST /api/v1/auth/2fa/login-verify", "Verificación del segundo factor"],
            ["GET /api/v1/auth/me", "Datos de la sesión"],
            ["GET /api/v1/account/security", "Estado de seguridad de la cuenta"],
            ["POST /api/v1/account/security/change-password", "Cambio de contraseña"],
            ["POST /api/v1/account/security/2fa/setup", "Alta de 2FA"],
            ["POST /api/v1/account/security/2fa/confirm", "Confirmación de 2FA"],
            ["POST /api/v1/account/security/2fa/disable", "Desactivación de 2FA"],
            ["POST /api/v1/registrations", "Alta de cliente"],
            ["GET /api/v1/profile", "Perfil del usuario"],
            ["PUT /api/v1/profile", "Edición del perfil"],
            ["GET /api/v1/kyc", "Estado del expediente KYC"],
            ["POST /api/v1/kyc/submit", "Envío del expediente"],
            ["POST /api/v1/kyc/documents", "Carga de documento"],
            ["POST /api/v1/beneficiaries", "Alta de beneficiario"],
            ["GET /api/v1/beneficiaries", "Listado de beneficiarios"],
            ["GET /api/v1/beneficiaries/{id}", "Detalle de beneficiario"],
            ["PUT /api/v1/beneficiaries/{id}", "Edición de beneficiario"],
            ["POST /api/v1/quotes", "Creación de cotización"],
            ["GET /api/v1/quotes/{id}", "Consulta de cotización"],
            ["GET /api/v1/exchange-rates/current", "Tasa de cambio vigente"],
            ["POST /api/v1/remittances", "Creación de remesa"],
            ["GET /api/v1/remittances", "Listado de remesas"],
            ["GET /api/v1/remittances/{id}", "Detalle de remesa"],
            ["POST /api/v1/remittances/{id}/deposit", "Instrucción de fondeo"],
            ["POST /api/v1/deposits/{id}/simulate-confirmation", "Confirmación simulada"],
            ["POST /api/v1/remittances/{id}/compliance-review", "Revisión de cumplimiento"],
            ["POST /api/v1/remittances/{id}/payout", "Orden de pago"],
            ["POST /api/v1/payouts/{id}/simulate-completion", "Finalización simulada"],
            ["POST /api/v1/remittances/{id}/reconcile", "Conciliación de remesa"],
            ["GET /api/v1/funding-methods/{country}", "Métodos de fondeo por país"],
            ["GET /api/v1/admin/funding-methods", "Catálogo administrativo"],
            ["PUT /api/v1/admin/funding-methods/{code}", "Edición de método"],
            ["GET /api/v1/admin/funding-accounts", "Cuentas receptoras"],
            ["POST /api/v1/admin/funding-accounts", "Alta de cuenta"],
            ["PUT /api/v1/admin/funding-accounts/{id}", "Edición de cuenta"],
            ["GET /api/v1/admin/operations", "Cola de operaciones"],
            ["GET /api/v1/admin/users", "Usuarios"],
            ["GET /api/v1/admin/rates/history", "Historial de tasas"],
            ["GET /api/v1/admin/dashboard", "Indicadores del panel"],
            ["GET /api/v1/admin/settings", "Ajustes de plataforma"],
            ["PUT /api/v1/admin/settings", "Actualización de ajustes"],
            ["POST /api/v1/admin/exchange-rate", "Fijar tasa"],
            ["PUT /api/v1/admin/exchange-rate/source", "Cambiar fuente de tasa"],
            ["GET /api/v1/admin/kyc", "Expedientes KYC"],
            ["GET /api/v1/admin/kyc/{id}/documents", "Documentos de un expediente"],
            ["GET /api/v1/admin/kyc/documents/{id}", "Descarga de documento"],
            ["POST /api/v1/admin/kyc/{id}/review", "Revisión de expediente"],
        ],
        widths=[9.2, 7.0],
        font_size=7.6,
        first_col_bold=False,
    )

    H2(doc, "B. Inventario de tablas")
    P(
        doc,
        "Inventario de las 37 tablas del modelo físico, agrupadas por dominio.",
        align="justify",
    )
    TBL(
        doc,
        ["Dominio", "Tablas"],
        [
            ["Identidad y acceso", "countries, currencies, users, user_profiles, user_consents, email_verification_tokens, password_reset_tokens, user_auth_challenges, idempotency_keys"],
            ["Beneficiarios y métodos", "beneficiaries, payment_methods, funding_payment_methods, platform_funding_accounts, funding_account_methods"],
            ["Precios y cotizaciones", "exchange_rates, remittance_quotes"],
            ["Núcleo de remesas", "remittances, remittance_state_events, compliance_checks"],
            ["Fondeo y conciliación", "bank_connections, deposit_requests, bank_webhook_events, bank_transactions, reconciliation_cases, bank_sync_cursors"],
            ["Payouts", "payout_orders, payout_attempts"],
            ["Ledger contable", "ledger_accounts, ledger_transactions, ledger_entries, wallets, wallet_ledger_entries"],
            ["Operación y auditoría", "platform_settings, outbox_messages, audit_logs"],
            ["KYC", "kyc_cases, kyc_documents"],
        ],
        widths=[3.8, 12.2],
        font_size=7.8,
        first_col_bold=True,
    )

    H2(doc, "C. Variables de entorno")
    TBL(
        doc,
        ["Variable", "Propósito", "Obligatoria"],
        [
            ["SEDERG_DB_DSN", "Cadena de conexión a la base de datos", "Sí"],
            ["SEDERG_DB_USER", "Usuario de base de datos", "Sí"],
            ["SEDERG_DB_PASSWORD", "Contraseña de base de datos", "Sí"],
            ["SEDERG_DB_PORT", "Puerto de base de datos", "No"],
            ["SEDERG_AUTH_MODE", "Modo de autenticación: development u oidc", "Sí"],
            ["SEDERG_COOKIE_KEY", "Clave de validación de cookies", "Sí"],
            ["SEDERG_PII_KEY", "Clave de cifrado de datos personales", "Sí"],
            ["SEDERG_ACCOUNT_ENCRYPTION_KEY", "Clave de cifrado de cuentas bancarias", "Sí para fondeo"],
            ["SEDERG_MAIL_*", "Configuración SMTP (host, puerto, usuario, remitente)", "Sí para correo"],
            ["SEDERG_APP_URL", "URL pública del frontend para enlaces", "Sí"],
            ["SEDERG_OIDC_ISSUER", "Emisor OIDC", "Sí en producción"],
            ["SEDERG_OIDC_AUDIENCE", "Audiencia esperada del token", "Sí en producción"],
            ["SEDERG_OIDC_PUBLIC_KEY", "Clave pública para validar JWT RS256", "Sí en producción"],
            ["SEDERG_CORS_ORIGINS", "Orígenes permitidos", "Sí"],
            ["VITE_API_BASE_URL", "URL base de la API para el frontend", "Sí fuera de preview"],
            ["VITE_AUTH_MODE", "Modo de autenticación del frontend", "Sí"],
            ["VITE_UI_PREVIEW", "Activa el modo demostración sin API", "No"],
        ],
        widths=[5.4, 8.6, 2.5],
        font_size=7.8,
        first_col_bold=True,
        align_center_cols=(2,),
    )
    NOTE(
        doc,
        "Los valores reales se gestionan fuera del repositorio. .env.example solo "
        "contiene marcadores y nunca debe incluir secretos productivos.",
    )

    H2(doc, "D. Glosario técnico")
    TBL(
        doc,
        ["Término", "Definición"],
        [
            ["API REST", "Interfaz HTTP que expone recursos y operaciones en formato JSON"],
            ["Idempotencia", "Propiedad que garantiza que repetir una operación no duplica su efecto"],
            ["Ledger", "Registro contable de doble entrada con asientos balanceados"],
            ["Máquina de estados", "Modelo que define estados y transiciones permitidas"],
            ["Migración", "Script versionado que modifica el esquema de base de datos"],
            ["OIDC", "Protocolo de identidad sobre OAuth 2.0 usado en producción"],
            ["Outbox", "Patrón de publicación fiable de eventos junto a la transacción"],
            ["Payout", "Pago al beneficiario en el país de destino"],
            ["Sandbox", "Entorno de prueba sin dinero ni bancos reales"],
            ["SPA", "Aplicación de una sola página ejecutada en el navegador"],
            ["TOTP", "Código temporal basado en tiempo para el segundo factor"],
            ["UUID", "Identificador único no secuencial usado en recursos públicos"],
        ],
        widths=[3.4, 12.6],
        font_size=8.6,
        first_col_bold=True,
    )

    SPACER(doc, 10)
    PR(
        doc,
        [
            ("SEDERG_PAY", {"size": 11, "color": NAVY, "bold": True}),
            ("  ·  Memoria técnica  ·  Versión 1.0", {"size": 9, "color": MID}),
        ],
        align="center",
    )
    PR(
        doc,
        [
            ("Repositorio: ", {"size": 8.5, "color": MID}),
            ("github.com/sedergsoft/SedergPay_oficial", {"size": 8.5, "color": BLUE, "bold": True}),
            ("   ·   Commit e201a38   ·   Noviembre de 2026", {"size": 8.5, "color": MID}),
        ],
        align="center",
    )

    doc.save(OUT)
    return os.path.abspath(OUT)


if __name__ == "__main__":
    path = build()
    print("Documento generado:", path)
