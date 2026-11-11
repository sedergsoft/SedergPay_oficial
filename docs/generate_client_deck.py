# -*- coding: utf-8 -*-
"""Genera el dossier comercial de SEDERG_PAY en formato DOCX."""
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
OUT = os.path.join(HERE, "..", "SEDERG_PAY_Presentacion_Comercial.docx")


def build() -> str:
    doc = new_document()
    setup_header_footer(
        doc.sections[0],
        header_left="SEDERG_PAY",
        header_right="Dossier comercial · v1.0",
        footer_left="Documento confidencial — uso comercial",
    )
    set_core_properties(
        doc,
        title="SEDERG_PAY — Presentación comercial",
        subject="Dossier para clientes potenciales",
        keywords="remesas, Rusia, Cuba, cumplimiento, fintech, sandbox",
        comments="Documento comercial generado a partir del repositorio sedergsoft/SedergPay_oficial",
    )

    add_cover(
        doc,
        doc_kicker="Dossier comercial",
        title="SEDERG_PAY",
        subtitle="Plataforma de remesas Rusia ↔ Cuba con control, cumplimiento y trazabilidad",
        doc_type="Presentación para clientes potenciales",
        meta=[
            ("Preparado por", "Equipo SEDERG_PAY"),
            ("Versión", "1.0"),
            ("Fecha", "Noviembre de 2026"),
            ("Clasificación", "Confidencial — uso comercial"),
            ("Base técnica", "Repositorio sedergsoft/SedergPay_oficial · commit e201a38"),
        ],
        footer_note=(
            "Documento informativo. La plataforma opera actualmente en entorno sandbox: "
            "no habilita pagos reales ni custodia de fondos hasta superar los gates "
            "legales, regulatorios y bancarios correspondientes."
        ),
    )

    # ------------------------------------------------------------------ Índice
    H1(doc, "Índice", page_break=False)
    TOC(
        doc,
        [
            (0, "1. Resumen ejecutivo"),
            (0, "2. El problema del corredor Rusia–Cuba"),
            (0, "3. La solución SEDERG_PAY"),
            (0, "4. Propuesta de valor"),
            (0, "5. Cómo funciona: el recorrido del cliente"),
            (0, "6. Actores y experiencia de uso"),
            (0, "7. Capacidades de la plataforma"),
            (0, "8. Corredores, fondeo y entrega"),
            (0, "9. Seguridad y cumplimiento"),
            (0, "10. Tecnología y arquitectura"),
            (0, "11. Modelo comercial"),
            (0, "12. Estado actual y hoja de ruta"),
            (0, "13. Próximos pasos"),
            (0, "14. Glosario"),
            (0, "15. Aviso legal"),
        ],
    )
    NOTE(
        doc,
        "Este dossier describe capacidades reales del repositorio y distingue de forma "
        "explícita entre lo implementado, lo simulado y lo pendiente. Las cifras y "
        "parámetros citados corresponden a la línea base del commit e201a38.",
    )

    # -------------------------------------------------------- 1. Resumen ejecutivo
    H1(doc, "1. Resumen ejecutivo")
    P(
        doc,
        "SEDERG_PAY es una plataforma digital para gestionar, controlar y conciliar "
        "remesas entre Rusia y Cuba. Cubre el recorrido completo del cliente: registro "
        "y verificación de identidad, alta de beneficiarios, cotización con tasa y "
        "comisión visibles, instrucciones de fondeo, revisión de cumplimiento, pago al "
        "beneficiario, conciliación contable y auditoría.",
        align="justify",
    )
    P(
        doc,
        "A diferencia de un simple formulario de envío, la plataforma está construida "
        "sobre reglas de dominio verificables: una máquina de estados que impide saltos "
        "de etapa, un libro contable de doble entrada, claves de idempotencia para "
        "evitar operaciones duplicadas y un registro de auditoría de las acciones "
        "sensibles. El portal está disponible en español y ruso, con tema claro y "
        "oscuro, y un panel administrativo para operar tasas, usuarios, KYC y cuentas "
        "de fondeo.",
        align="justify",
    )
    KPI(
        doc,
        [
            ("2", "CORREDORES\nRUB ↔ CUP"),
            ("8", "ETAPAS\nDEL FLUJO"),
            ("2", "IDIOMAS\nES / RU"),
            ("37", "TABLAS\nEN EL MODELO"),
        ],
        fills=[LIGHT_HEX, "F5F8FC", LIGHT_HEX, "F5F8FC"],
    )
    CALL(
        doc,
        "Lo que el cliente puede hacer hoy",
        "Recorrer el flujo completo en entorno sandbox con datos reales de negocio: "
        "registrarse, verificar el correo, completar KYC, crear beneficiarios, cotizar, "
        "generar instrucciones de fondeo, revisar cumplimiento, simular el pago y "
        "conciliar. El frontend está publicado en modo demostración y la base de datos "
        "en TiDB Cloud ya está migrada. El backend público se encuentra en proceso de "
        "despliegue, por lo que la demo no ejecuta todavía operaciones contra la API real.",
        fill="E8F1FA",
        border=CYAN_HEX,
    )
    CALL(
        doc,
        "Lo que la plataforma no hace todavía",
        "No mueve dinero real, no custodia fondos y no está conectada a bancos "
        "productivos. Los depósitos, payouts y proveedores de pago son simulados. "
        "Cualquier piloto con dinero real requiere contratos, autorizaciones y "
        "controles operativos adicionales que se detallan en la sección 12.",
        fill="FFF4E5",
        border="F0A400",
    )

    # --------------------------------------------------- 2. El problema
    H1(doc, "2. El problema del corredor Rusia–Cuba")
    P(
        doc,
        "El envío de dinero entre Rusia y Cuba combina una alta demanda con una "
        "operación fragmentada. El remitente suele comparar tasas en canales informales, "
        "confirmar por mensajería y confiar en que el beneficiario reciba el importe "
        "correcto. Esa dinámica genera fricción, opacidad y riesgos difíciles de auditar.",
        align="justify",
    )
    TBL(
        doc,
        ["Dolor del mercado", "Consecuencia para el cliente", "Respuesta de SEDERG_PAY"],
        [
            [
                "Tasas y comisiones poco transparentes",
                "El remitente no sabe cuánto recibirá realmente hasta después de enviar",
                "Cotización versionada con tasa, comisión, límites y vigencia antes de confirmar",
            ],
            [
                "Canales dispersos y sin historial",
                "Seguimiento por mensajería, sin estado único ni comprobantes",
                "Expediente digital de la remesa con estados, eventos y marcas de tiempo",
            ],
            [
                "Cumplimiento manual",
                "Revisiones inconsistentes y poca evidencia para auditoría",
                "KYC, revisión AML/CTF y registro de decisiones en la plataforma",
            ],
            [
                "Conciliación en hojas de cálculo",
                "Diferencias entre lo cobrado, lo pagado y lo contabilizado",
                "Ledger de doble entrada, conciliación y casos de excepción",
            ],
            [
                "Barrera de idioma",
                "Errores en datos de beneficiarios y cuentas",
                "Interfaz bilingüe ES/RU y validaciones de formato",
            ],
            [
                "Riesgo operativo y de fraude",
                "Operaciones duplicadas o ejecutadas sin control",
                "Idempotencia, autenticación reforzada y auditoría de acciones",
            ],
        ],
        widths=[3.4, 5.0, 6.2],
        font_size=8.6,
        first_col_bold=True,
    )

    # --------------------------------------------------- 3. La solución
    H1(doc, "3. La solución SEDERG_PAY")
    P(
        doc,
        "SEDERG_PAY concentra en una sola plataforma las piezas que hoy están separadas: "
        "cotización, cumplimiento, fondeo, pago, conciliación y atención al cliente. El "
        "objetivo es que cada remesa tenga un estado único, un responsable y evidencia "
        "verificable en cada paso.",
        align="justify",
    )
    IMAGE(
        doc,
        os.path.join(ASSETS, "propuesta_valor.png"),
        "Figura 1. De la necesidad del cliente al cierre conciliado.",
    )
    P(
        doc,
        "La plataforma se apoya en cuatro decisiones de diseño:",
        align="justify",
    )
    BUL(
        doc,
        [
            "Reglas de negocio en el dominio, no dispersas en la interfaz, para que la "
            "cotización, los estados y el ledger se comporten igual en todos los canales.",
            "Trazabilidad por defecto: cada transición de estado y cada acción sensible "
            "deja registro, con identificadores públicos no secuenciales.",
            "Separación entre fondeo y entrega: cómo paga el remitente y cómo recibe el "
            "beneficiario son decisiones independientes y auditables.",
            "Operación asistida: el panel administrativo permite gestionar tasas, KYC, "
            "usuarios y cuentas sin tocar la base de datos.",
        ],
    )

    # --------------------------------------------------- 4. Propuesta de valor
    H1(doc, "4. Propuesta de valor")
    P(
        doc,
        "La propuesta se organiza en seis pilares. Cada uno responde a una necesidad "
        "concreta del remitente, del beneficiario o del equipo que opera el servicio.",
        align="justify",
    )
    TBL(
        doc,
        ["Pilar", "Qué aporta", "Beneficio para el cliente"],
        [
            ["Transparencia", "Tasa, comisión, importe a recibir y vigencia antes de confirmar", "El cliente decide con información completa y sin sorpresas"],
            ["Cumplimiento", "KYC, revisión AML/CTF y evidencia de cada decisión", "Menos riesgo regulatorio y operaciones defendibles"],
            ["Control", "Máquina de estados, ledger de doble entrada y conciliación", "Cada remesa cuadra y puede auditarse"],
            ["Confianza", "2FA, cifrado de datos sensibles y aislamiento por usuario", "Protección de datos personales y de cuentas"],
            ["Experiencia", "Portal bilingüe ES/RU, responsive, tema claro y oscuro", "Menos errores y mejor adopción"],
            ["Operación", "Panel administrativo para tasas, usuarios, KYC y cuentas", "El equipo opera sin depender de soporte técnico"],
        ],
        widths=[2.6, 6.6, 5.4],
        font_size=8.8,
        first_col_bold=True,
    )
    CALL(
        doc,
        "Diferenciador clave",
        "SEDERG_PAY no se limita a iniciar una transferencia: cierra el ciclo. La remesa "
        "solo se considera completada cuando el pago fue registrado, conciliado y "
        "respaldado por asientos contables balanceados.",
        fill="E8F1FA",
        border=CYAN_HEX,
    )

    # --------------------------------------------------- 5. Flujo del cliente
    H1(doc, "5. Cómo funciona: el recorrido del cliente")
    IMAGE(
        doc,
        os.path.join(ASSETS, "flujo_remesa.png"),
        "Figura 2. Flujo funcional y máquina de estados de una remesa.",
    )
    P(
        doc,
        "El recorrido tiene ocho etapas. La plataforma impide avanzar si la etapa "
        "anterior no se cumplió y deja registro de cada transición.",
        align="justify",
    )
    STEPS(
        doc,
        [
            "Registro y verificación: el cliente crea su cuenta, acepta los "
            "consentimientos y confirma su correo electrónico.",
            "KYC: carga documentos de identidad; el equipo los revisa y aprueba o "
            "rechaza el expediente.",
            "Beneficiario: registra los datos del destinatario y el método de entrega "
            "disponible para el país de destino.",
            "Cotización: el sistema calcula tasa, comisión, importe a recibir y "
            "vigencia; la cotización queda versionada.",
            "Fondeo: el cliente recibe instrucciones específicas (QR o transferencia) "
            "para pagar a SEDERG_PAY en el país de origen.",
            "Cumplimiento: se revisa la operación contra listas y reglas AML/CTF; puede "
            "aprobarse o rechazarse con motivo.",
            "Pago al beneficiario: se genera la orden de payout y se registra el "
            "resultado del intento.",
            "Conciliación y cierre: se comparan fondos, pagos y asientos; la remesa se "
            "marca como completada o como excepción para revisión.",
        ],
    )
    NOTE(
        doc,
        "Estados soportados: awaiting_payment → compliance_review → approved → "
        "funds_confirmed → payout_pending → paid → reconciliation_pending → completed. "
        "Estados alternativos: rejected, cancelled, payout_failed y reconciliation_exception.",
    )

    # --------------------------------------------------- 6. Actores
    H1(doc, "6. Actores y experiencia de uso")
    P(
        doc,
        "La plataforma distingue dos roles implementados: cliente (customer) y "
        "administrador (admin). Las tareas de operación y cumplimiento se ejecutan hoy "
        "con la cuenta administrativa; la separación de un rol de operador y un portal "
        "para el beneficiario forman parte de la hoja de ruta.",
        align="justify",
    )
    TBL(
        doc,
        ["Actor", "Experiencia actual", "Estado"],
        [
            ["Remitente (customer)", "Panel con resumen, cotizador, beneficiarios, remesas, perfil, seguridad, KYC y fondeo", "Implementado"],
            ["Administrador (admin)", "Panel de usuarios, tasas, KYC, cuentas de fondeo, ajustes y cola de operaciones", "Implementado"],
            ["Operador de cumplimiento", "No existe un rol separado; las revisiones se realizan con la cuenta administrativa", "Pendiente"],
            ["Beneficiario", "No dispone de portal propio; sus datos los gestiona el remitente y el pago se registra en la remesa", "Pendiente"],
        ],
        widths=[3.2, 10.4, 2.9],
        font_size=8.6,
        first_col_bold=True,
        align_center_cols=(2,),
    )
    P(
        doc,
        "La aplicación incluye nueve vistas: resumen, beneficiarios, cotización, "
        "remesas, perfil, seguridad, KYC, administración y fondeo. La navegación muestra "
        "el módulo administrativo solo a las cuentas con ese rol, y el idioma puede "
        "cambiarse en cualquier momento sin perder la sesión.",
        align="justify",
    )

    # --------------------------------------------------- 7. Capacidades
    H1(doc, "7. Capacidades de la plataforma")
    P(
        doc,
        "El repositorio implementa dieciséis módulos funcionales. La tabla distingue el "
        "estado real de cada uno para evitar expectativas incorrectas.",
        align="justify",
    )
    TBL(
        doc,
        ["Módulo", "Capacidades principales", "Estado"],
        [
            ["Registro y acceso", "Alta de cliente, consentimientos, verificación de correo, login y sesión", "Implementado"],
            ["Seguridad de cuenta", "Cambio y recuperación de contraseña, 2FA TOTP, códigos de recuperación", "Implementado"],
            ["Perfil y KYC", "Datos personales, carga y revisión de documentos, cifrado y antivirus", "Implementado"],
            ["Beneficiarios", "Alta, consulta y edición de beneficiarios con límite configurable", "Implementado"],
            ["Cotizaciones", "Cálculo de tasa, comisión, límites y vigencia; historial por par", "Implementado"],
            ["Remesas", "Creación, consulta y máquina de estados con eventos auditables", "Implementado"],
            ["Fondeo", "Métodos por país, cuentas receptoras de la plataforma e instrucciones/QR", "Simulado"],
            ["Cumplimiento", "Revisión AML/CTF y decisión con motivo", "Implementado"],
            ["Payouts", "Órdenes e intentos de pago al beneficiario", "Simulado"],
            ["Conciliación", "Casos, comparación de movimientos y cierre contable", "Implementado"],
            ["Ledger", "Cuentas, transacciones y asientos de doble entrada", "Implementado"],
            ["Administración", "Usuarios, tasas, ajustes, dashboard y operaciones", "Implementado"],
            ["Notificaciones", "Correo transaccional por SMTP y outbox de eventos", "Implementado"],
            ["Auditoría", "Registro de acciones sensibles e idempotencia de operaciones", "Implementado"],
            ["Tipos de cambio", "Tasa actual, historial y fuente administrable", "Implementado"],
            ["Internacionalización", "Interfaz ES/RU con formato local de fechas e importes", "Implementado"],
        ],
        widths=[3.2, 10.0, 2.4],
        font_size=8.4,
        first_col_bold=True,
        align_center_cols=(2,),
    )
    NOTE(
        doc,
        "«Simulado» significa que el flujo, los estados y los registros existen, pero el "
        "movimiento de dinero y la conexión bancaria se ejecutan con adaptadores de "
        "prueba. No hay integración productiva con bancos ni proveedores de pago.",
    )

    # --------------------------------------------------- 8. Corredores
    H1(doc, "8. Corredores, fondeo y entrega")
    P(
        doc,
        "La plataforma modela dos corredores y separa explícitamente el método de fondeo "
        "(cómo paga el remitente) del método de entrega (cómo recibe el beneficiario). "
        "Esta separación evita mezclar catálogos y permite habilitar canales por país.",
        align="justify",
    )
    TBL(
        doc,
        ["Corredor", "Fondeo (inbound)", "Entrega (outbound)", "Estado"],
        [
            ["Rusia → Cuba (RUB → CUP)", "Transferencia bancaria; SBP C2B por QR (previsto)", "Pago al beneficiario en CUP según método habilitado", "Sandbox"],
            ["Cuba → Rusia (CUP → RUB)", "Transferencia bancaria y QR instantáneo (sandbox)", "Pago en RUB al beneficiario", "Sandbox"],
        ],
        widths=[3.8, 4.4, 5.4, 2.0],
        font_size=8.6,
        first_col_bold=True,
        align_center_cols=(3,),
    )
    BUL(
        doc,
        [
            "El catálogo de métodos se administra por país y moneda, con cuentas "
            "receptoras propias de la plataforma y reglas de visualización.",
            "La lectura o presentación de un QR no confirma el pago: la confirmación "
            "requiere una fuente bancaria autorizada y conciliación.",
            "Los adaptadores de pago son independientes del dominio, de modo que un "
            "proveedor real pueda incorporarse sin reescribir el flujo.",
        ],
    )

    # --------------------------------------------------- 9. Seguridad
    H1(doc, "9. Seguridad y cumplimiento")
    P(
        doc,
        "La seguridad se trata como parte del producto y no como un añadido. Los "
        "controles siguientes están implementados en el repositorio; las limitaciones "
        "conocidas se documentan con transparencia en la sección 12.",
        align="justify",
    )
    TBL(
        doc,
        ["Control", "Implementación", "Estado"],
        [
            ["Identidad", "OIDC con JWT RS256 en producción; identidad de desarrollo solo en modo development", "Implementado"],
            ["Doble factor", "TOTP RFC 6238 (30 s, 6 dígitos, ventana ±1) y 8 códigos de recuperación", "Implementado"],
            ["Contraseñas", "Hash bcrypt y política de cambio desde la cuenta", "Implementado"],
            ["Tokens", "Reset y verificación de 64 caracteres hexadecimales, almacenados como hash SHA-256", "Implementado"],
            ["Datos sensibles", "Cifrado con encryptByKey (AES-256-CBC + HMAC) para PII y cuentas", "Implementado"],
            ["Documentos KYC", "Cifrado en reposo y análisis antimalware con ClamAV", "Implementado"],
            ["Auditoría", "Registro de acciones sensibles y eventos de estado", "Implementado"],
            ["Idempotencia", "Claves por operación para evitar duplicados", "Implementado"],
            ["Aislamiento", "Verificación de propiedad de recursos por usuario", "Implementado"],
            ["Límite de intentos de login", "No implementado en el backend actual", "Pendiente"],
            ["Cifrado del secreto TOTP", "Almacenado sin cifrar en la base de datos", "Pendiente"],
        ],
        widths=[3.6, 10.0, 2.4],
        font_size=8.4,
        first_col_bold=True,
        align_center_cols=(2,),
    )
    CALL(
        doc,
        "Compromiso de transparencia",
        "Preferimos declarar lo que falta antes que prometer controles inexistentes. "
        "El endurecimiento del login y el cifrado del secreto TOTP están identificados "
        "como prioridades de la hoja de ruta técnica y se resolverán antes de un piloto "
        "con usuarios reales.",
        fill="FFF4E5",
        border="F0A400",
    )

    # --------------------------------------------------- 10. Tecnología
    H1(doc, "10. Tecnología y arquitectura")
    P(
        doc,
        "La plataforma usa un stack maduro y ampliamente soportado, pensado para "
        "mantenimiento a largo plazo y para separar las reglas de negocio de la "
        "infraestructura.",
        align="justify",
    )
    IMAGE(
        doc,
        os.path.join(ASSETS, "arquitectura.png"),
        "Figura 3. Arquitectura general: frontend, API, dominio, infraestructura y datos.",
    )
    TBL(
        doc,
        ["Capa", "Tecnología", "Por qué"],
        [
            ["Frontend", "React 18 + TypeScript + Vite", "Interfaz rápida, tipada y fácil de desplegar como SPA"],
            ["API", "Yii2 + PHP 8.3", "Framework estable, maduro y con soporte de largo plazo"],
            ["Dominio", "Clases PHP independientes del framework", "Reglas de pricing, estados y ledger testeables de forma aislada"],
            ["Datos", "PostgreSQL 17 / TiDB Cloud", "Transacciones, integridad referencial y compatibilidad gestionada"],
            ["Caché y colas", "Redis 7", "Rendimiento y procesamiento asíncrono"],
            ["Antivirus", "ClamAV", "Análisis de documentos KYC antes de su revisión"],
            ["Entrega", "Docker, GitHub Actions, Northflank, GitHub Pages", "Automatización y despliegue sin coste inicial"],
        ],
        widths=[2.6, 5.4, 7.2],
        font_size=8.6,
        first_col_bold=True,
    )
    P(
        doc,
        "El backend expone 53 rutas REST versionadas bajo /api/v1, organizadas por "
        "dominio. El modelo de datos tiene 37 tablas distribuidas en nueve dominios y se "
        "gestiona con 16 migraciones idempotentes. La calidad se protege con 27 pruebas "
        "unitarias sobre las reglas críticas.",
        align="justify",
    )

    # --------------------------------------------------- 11. Modelo comercial
    H1(doc, "11. Modelo comercial")
    P(
        doc,
        "El modelo se basa en una comisión transparente sobre la operación, con límites "
        "de envío y una cotización con vigencia limitada. Todos los parámetros son "
        "configurables desde el panel administrativo y quedan versionados.",
        align="justify",
    )
    TBL(
        doc,
        ["Parámetro", "Valor por defecto", "Descripción"],
        [
            ["Comisión", "3,00 %", "Porcentaje aplicado sobre el importe de la operación"],
            ["Importe mínimo", "15 000 RUB", "Monto mínimo de envío para el corredor RUB"],
            ["Importe máximo", "50 000 RUB", "Monto máximo de envío por operación"],
            ["Vigencia de cotización", "900 segundos", "Tiempo durante el cual la tasa cotizada es válida"],
            ["Beneficiarios por cliente", "20", "Límite configurable de beneficiarios registrados"],
            ["Moneda y precisión", "DECIMAL", "Importes con moneda explícita y precisión decimal"],
        ],
        widths=[3.8, 4.0, 7.4],
        font_size=8.8,
        first_col_bold=True,
    )
    NOTE(
        doc,
        "Los valores son parámetros por defecto del entorno sandbox. No constituyen una "
        "oferta comercial vinculante: las condiciones finales se acuerdan por contrato y "
        "pueden variar por corredor, volumen y método de pago.",
    )
    P(doc, "Fuentes de ingreso previstas:", bold=True, space_before=4)
    BUL(
        doc,
        [
            "Comisión por remesa, calculada y mostrada antes de la confirmación.",
            "Márgenes asociados a la tasa de cambio, siempre visibles en la cotización.",
            "Servicios de valor añadido para operadores: conciliación, reportería y "
            "soporte multi-corredor.",
        ],
    )

    # --------------------------------------------------- 12. Estado y hoja de ruta
    H1(doc, "12. Estado actual y hoja de ruta")
    P(
        doc,
        "La siguiente tabla resume el estado real del proyecto a la fecha de este "
        "documento. Es la sección más importante para calibrar expectativas.",
        align="justify",
    )
    TBL(
        doc,
        ["Componente", "Estado", "Detalle"],
        [
            ["MVP funcional en sandbox", "Implementado", "Flujo completo con datos reales de negocio y pagos simulados"],
            ["Frontend web", "Publicado", "SPA accesible en GitHub Pages en modo demostración (VITE_UI_PREVIEW)"],
            ["Base de datos", "Implementado", "TiDB Cloud Starter migrada con las 16 migraciones"],
            ["Backend público", "Pendiente", "Servicio en Northflank en proceso de despliegue"],
            ["Integraciones bancarias", "Simulado", "Adaptadores de prueba; sin conexión productiva"],
            ["Piloto controlado", "Pendiente", "Requiere gates legales, contractuales y operativos"],
            ["Producción con dinero real", "Pendiente", "Requiere autorizaciones regulatorias y operación 24/7"],
        ],
        widths=[4.0, 3.0, 8.2],
        font_size=8.8,
        first_col_bold=True,
        align_center_cols=(1,),
    )
    CALL(
        doc,
        "Qué significa «sandbox» en la práctica",
        "El sandbox permite validar producto, experiencia y operación con datos y reglas "
        "reales, pero sin mover dinero ni conectar bancos. Es el entorno adecuado para "
        "una demo, una prueba de concepto o un piloto cerrado con usuarios seleccionados.",
        fill="E8F1FA",
        border=CYAN_HEX,
    )
    P(doc, "Hoja de ruta prevista:", bold=True, space_before=4)
    TBL(
        doc,
        ["Fase", "Objetivo", "Criterio de salida"],
        [
            ["Fase 1 · Consolidación sandbox", "Cerrar el despliegue del backend y estabilizar la demo", "API pública disponible y flujo E2E verificado"],
            ["Fase 2 · Piloto cerrado", "Operar con usuarios reales y montos limitados", "Gates legales y operativos superados"],
            ["Fase 3 · Integraciones", "Conectar fondeo y payout con proveedores autorizados", "Contratos y pruebas de conciliación aprobadas"],
            ["Fase 4 · Producción", "Operación continua con dinero real", "Autorizaciones regulatorias y auditoría externa"],
        ],
        widths=[4.0, 6.2, 5.0],
        font_size=8.6,
        first_col_bold=True,
    )

    # --------------------------------------------------- 13. Próximos pasos
    H1(doc, "13. Próximos pasos")
    P(
        doc,
        "Proponemos un proceso gradual, con entregables claros en cada etapa y sin "
        "comprometer dinero real hasta que existan las condiciones adecuadas.",
        align="justify",
    )
    STEPS(
        doc,
        [
            "Demostración guiada del portal y del panel administrativo, con recorrido "
            "completo de una remesa en sandbox.",
            "Sesión de alcance para definir corredores, volúmenes, métodos de pago y "
            "requisitos operativos del cliente.",
            "Due diligence técnica: revisión de arquitectura, seguridad, modelo de datos "
            "y plan de integración.",
            "Definición de los gates legales, contractuales y regulatorios necesarios "
            "para el piloto.",
            "Piloto cerrado con usuarios seleccionados, montos limitados y métricas de "
            "operación acordadas.",
            "Integración progresiva de proveedores de fondeo y payout, con conciliación "
            "verificada antes de habilitar producción.",
        ],
    )
    CALL(
        doc,
        "Siguiente paso recomendado",
        "Agendar una demostración de 45 minutos. En la sesión se recorre el flujo "
        "completo, se revisan las capacidades reales y se acuerda el alcance de un "
        "posible piloto. No se requiere compromiso comercial para la demo.",
        fill="E8F1FA",
        border=CYAN_HEX,
    )

    # --------------------------------------------------- 14. Glosario
    H1(doc, "14. Glosario")
    TBL(
        doc,
        ["Término", "Definición"],
        [
            ["Remesa", "Envío de fondos de un remitente a un beneficiario entre dos países"],
            ["Corredor", "Par de países y monedas entre los que opera la plataforma (por ejemplo, RUB → CUP)"],
            ["Fondeo", "Etapa en la que el remitente paga a SEDERG_PAY en el país de origen"],
            ["Entrega o payout", "Etapa en la que el beneficiario recibe los fondos en destino"],
            ["KYC", "Conocimiento del cliente: verificación de identidad y documentación"],
            ["AML/CTF", "Prevención de lavado de activos y financiamiento del terrorismo"],
            ["Cotización", "Oferta de tasa, comisión e importe a recibir, con vigencia limitada"],
            ["Ledger", "Libro contable de doble entrada que registra cada movimiento"],
            ["Idempotencia", "Propiedad que evita ejecutar dos veces una misma operación"],
            ["Conciliación", "Comparación y cierre entre fondos recibidos, pagos y contabilidad"],
            ["Sandbox", "Entorno de prueba con reglas reales pero sin dinero ni bancos reales"],
            ["2FA / TOTP", "Segundo factor de autenticación basado en códigos temporales"],
            ["OIDC", "Protocolo estándar de identidad usado en producción"],
        ],
        widths=[3.4, 12.6],
        font_size=8.8,
        first_col_bold=True,
    )

    # --------------------------------------------------- 15. Aviso legal
    H1(doc, "15. Aviso legal")
    P(
        doc,
        "Este documento tiene carácter informativo y comercial. No constituye una oferta "
        "vinculante, asesoramiento financiero, legal ni fiscal, ni una garantía de "
        "resultados. Las capacidades descritas corresponden al estado del repositorio en "
        "el commit e201a38 y pueden cambiar sin previo aviso.",
        align="justify",
    )
    P(
        doc,
        "SEDERG_PAY no mueve dinero real, no custodia fondos y no está conectada a "
        "bancos productivos en la fecha de emisión. Cualquier operación con dinero real "
        "requiere contratos, autorizaciones regulatorias y controles operativos "
        "adicionales. La información sobre tratamiento de datos personales se rige por "
        "la política de privacidad y los consentimientos aceptados por cada usuario.",
        align="justify",
    )
    SPACER(doc, 10)
    PR(
        doc,
        [
            ("SEDERG_PAY", {"size": 11, "color": NAVY, "bold": True}),
            ("  ·  Plataforma de remesas Rusia ↔ Cuba", {"size": 9.5, "color": MID}),
        ],
        align="center",
    )
    PR(
        doc,
        [
            ("Repositorio: ", {"size": 8.5, "color": MID}),
            ("github.com/sedergsoft/SedergPay_oficial", {"size": 8.5, "color": BLUE, "bold": True}),
            ("   ·   Versión 1.0   ·   Noviembre de 2026", {"size": 8.5, "color": MID}),
        ],
        align="center",
    )

    doc.save(OUT)
    return os.path.abspath(OUT)


if __name__ == "__main__":
    path = build()
    print("Documento generado:", path)
