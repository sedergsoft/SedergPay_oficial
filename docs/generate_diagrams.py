# -*- coding: utf-8 -*-
"""Genera los diagramas usados en el dossier comercial y la memoria tecnica."""
from __future__ import annotations

import os
import textwrap

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch, FancyArrowPatch

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "assets")
os.makedirs(OUT, exist_ok=True)

NAVY = "#10264D"
BLUE = "#0B4F9C"
CYAN = "#00AEEF"
GREEN = "#00A55A"
RED = "#E53935"
GRAY = "#F4F6F9"
MID = "#5B6779"
LIGHT = "#E8EEF7"
WHITE = "#FFFFFF"


def _box(ax, x, y, w, h, title, body="", fill=WHITE, edge=BLUE, title_color=NAVY,
         body_color=MID, title_size=10, body_size=8, radius=0.02, lw=1.4, align="center"):
    patch = FancyBboxPatch(
        (x, y), w, h,
        boxstyle=f"round,pad=0.006,rounding_size={radius}",
        linewidth=lw, edgecolor=edge, facecolor=fill, zorder=2,
    )
    ax.add_patch(patch)
    if body:
        ax.text(x + w / 2, y + h * 0.66, title, ha="center", va="center",
                fontsize=title_size, fontweight="bold", color=title_color, zorder=3)
        ax.text(x + w / 2, y + h * 0.30, body, ha="center", va="center",
                fontsize=body_size, color=body_color, zorder=3, linespacing=1.35)
    else:
        ax.text(x + w / 2, y + h / 2, title, ha="center", va="center",
                fontsize=title_size, fontweight="bold", color=title_color, zorder=3)
    return patch


def _arrow(ax, x1, y1, x2, y2, color=CYAN, style="-|>", lw=1.8, dashed=False):
    arrow = FancyArrowPatch(
        (x1, y1), (x2, y2),
        arrowstyle=style, mutation_scale=14, linewidth=lw,
        color=color, zorder=1,
        linestyle="--" if dashed else "-",
        shrinkA=0, shrinkB=0,
    )
    ax.add_patch(arrow)


def _canvas(w=12, h=7.2, title=""):
    fig, ax = plt.subplots(figsize=(w, h), dpi=200)
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    fig.patch.set_facecolor(WHITE)
    if title:
        ax.text(0.02, 0.965, title, ha="left", va="top", fontsize=15,
                fontweight="bold", color=NAVY)
        ax.plot([0.02, 0.98], [0.925, 0.925], color=CYAN, linewidth=2.2)
    return fig, ax


def _save(fig, name):
    path = os.path.join(OUT, name)
    fig.savefig(path, bbox_inches="tight", facecolor=WHITE, pad_inches=0.18)
    plt.close(fig)
    print("saved", path)
    return path


# ---------------------------------------------------------------------------
# 1. Arquitectura general
# ---------------------------------------------------------------------------
def architecture():
    fig, ax = _canvas(12, 8.4, "Arquitectura general de SEDERG_PAY")

    # Usuarios
    users = [
        ("Remitente", "Portal cliente"),
        ("Beneficiario", "Seguimiento"),
        ("Operador", "Cola de operaciones"),
        ("Administrador", "Configuracion y control"),
    ]
    x0, w, gap = 0.035, 0.215, 0.018
    for i, (title, body) in enumerate(users):
        _box(ax, x0 + i * (w + gap), 0.795, w, 0.095, title, body,
             fill=GRAY, edge=CYAN, title_size=9, body_size=7.5)
        _arrow(ax, x0 + i * (w + gap) + w / 2, 0.795, x0 + i * (w + gap) + w / 2, 0.745,
               color=MID, lw=1.2)

    # Frontend
    _box(ax, 0.035, 0.645, 0.93, 0.095,
         "Frontend SPA  ·  React 18 + TypeScript + Vite",
         "Portal del cliente y panel administrativo  ·  ES / RU  ·  tema claro y oscuro  ·  responsive",
         fill=LIGHT, edge=BLUE, title_size=11, body_size=8)
    _arrow(ax, 0.5, 0.645, 0.5, 0.595, color=BLUE, lw=2.2)

    # API
    _box(ax, 0.035, 0.485, 0.93, 0.105,
         "API REST versionada  ·  /api/v1  ·  Yii2 + PHP 8.3",
         "Auth · Security · Registro · KYC · Beneficiarios · Cotizaciones · Remesas\n"
         "Fondeo · Cumplimiento · Payouts · Conciliacion · Administracion",
         fill=WHITE, edge=BLUE, title_size=11, body_size=8)
    _arrow(ax, 0.5, 0.485, 0.5, 0.435, color=BLUE, lw=2.2)

    # Tres columnas
    col_w, col_gap = 0.285, 0.035
    xs = [0.035, 0.035 + col_w + col_gap, 0.035 + 2 * (col_w + col_gap)]
    _box(ax, xs[0], 0.255, col_w, 0.175, "Capa de dominio",
         "PricingPolicy\nQuoteCalculator\nRemittanceStateMachine\nDoubleEntryValidator",
         fill=WHITE, edge=GREEN, title_size=10, body_size=7.8)
    _box(ax, xs[1], 0.255, col_w, 0.175, "Infraestructura",
         "Repositorios y servicios\nTOTP · Mailer · QR · OIDC\nCifrado y enmascarado\nSettings y auditoria",
         fill=WHITE, edge=CYAN, title_size=10, body_size=7.8)
    _box(ax, xs[2], 0.255, col_w, 0.175, "Datos y almacenamiento",
         "PostgreSQL 17 / TiDB Cloud\nRedis (cache / colas)\nStorage privado cifrado\nLedger e idempotencia",
         fill=WHITE, edge=BLUE, title_size=10, body_size=7.8)

    # External
    _box(ax, 0.035, 0.055, 0.93, 0.145, "Servicios externos e integraciones",
         "Banco Central de Cuba (tasas)  ·  Banco de Rusia (respaldo)  ·  SMTP Brevo\n"
         "Proveedores bancarios y QR en modo sandbox  ·  OIDC / Keycloak (produccion)",
         fill=GRAY, edge=MID, title_size=10, body_size=8, lw=1.1)
    _arrow(ax, xs[1] + col_w / 2, 0.255, 0.5, 0.205, color=MID, lw=1.4, dashed=True)

    ax.text(0.035, 0.018, "Implementado en el repositorio  ·  Integraciones bancarias reales pendientes de contrato",
            ha="left", va="bottom", fontsize=7.5, color=MID, style="italic")
    return _save(fig, "arquitectura.png")


# ---------------------------------------------------------------------------
# 2. Flujo de una remesa
# ---------------------------------------------------------------------------
def remittance_flow():
    fig, ax = _canvas(12.4, 5.4, "Flujo funcional de una remesa")

    steps = [
        ("1. Registro", "Email verificado\nconsentimientos", GRAY, CYAN),
        ("2. KYC", "Documentos\nrevision", GRAY, CYAN),
        ("3. Beneficiario", "Datos y metodo\nde entrega", LIGHT, BLUE),
        ("4. Cotizacion", "Tasa, comision\nlimites y vigencia", LIGHT, BLUE),
        ("5. Fondeo", "Instrucciones\nQR / transferencia", WHITE, BLUE),
        ("6. Cumplimiento", "AML / CTF\naprobado o rechazado", WHITE, GREEN),
        ("7. Payout", "Pago simulado\nal beneficiario", WHITE, GREEN),
        ("8. Conciliacion", "Ledger, cierre\ny auditoria", LIGHT, GREEN),
    ]
    w, h, gap = 0.108, 0.20, 0.0125
    y = 0.60
    for i, (title, body, fill, edge) in enumerate(steps):
        x = 0.018 + i * (w + gap)
        _box(ax, x, y, w, h, title, body, fill=fill, edge=edge,
             title_size=8.6, body_size=6.9)
        if i < len(steps) - 1:
            _arrow(ax, x + w, y + h / 2, x + w + gap, y + h / 2, color=CYAN, lw=1.5)

    # Estados
    _box(ax, 0.018, 0.30, 0.964, 0.17, "Maquina de estados de la remesa",
         "awaiting_payment  →  compliance_review  →  approved  →  funds_confirmed  →  payout_pending\n"
         "→  paid  →  reconciliation_pending  →  completed        (alternativas: rejected, cancelled, payout_failed, reconciliation_exception)",
         fill=WHITE, edge=NAVY, title_size=9.5, body_size=7.6)

    _box(ax, 0.018, 0.08, 0.964, 0.15, "Garantias transversales",
         "Idempotencia en operaciones  ·  ledger de doble entrada  ·  auditoria de acciones  ·  aislamiento por usuario\n"
         "Cifrado de datos sensibles  ·  referencias unicas  ·  outbox para eventos  ·  verificacion de propiedad de recursos",
         fill=GRAY, edge=MID, title_size=9.5, body_size=7.6, lw=1.1)

    return _save(fig, "flujo_remesa.png")


# ---------------------------------------------------------------------------
# 3. Grupos del modelo de datos
# ---------------------------------------------------------------------------
def data_groups():
    fig, ax = _canvas(12.2, 7.6, "Modelo de datos: 37 tablas en 9 dominios")

    groups = [
        ("Identidad y acceso", "9 tablas", "users · user_profiles · user_consents\ncountries · currencies\ntokens y desafios · idempotencia", BLUE),
        ("Beneficiarios y metodos", "5 tablas", "beneficiaries · payment_methods\nfunding_payment_methods\nplatform_funding_accounts\nfunding_account_methods", CYAN),
        ("Precios y cotizaciones", "2 tablas", "exchange_rates\nremittance_quotes", GREEN),
        ("Nucleo de remesas", "3 tablas", "remittances\nremittance_state_events\ncompliance_checks", BLUE),
        ("Fondeo y conciliacion", "6 tablas", "deposit_requests · bank_webhook_events\nbank_transactions · bank_connections\nbank_sync_cursors\nreconciliation_cases", CYAN),
        ("Payouts", "2 tablas", "payout_orders\npayout_attempts", GREEN),
        ("Ledger contable", "5 tablas", "ledger_accounts\nledger_transactions\nledger_entries\nwallets · wallet_ledger_entries", BLUE),
        ("Operacion y auditoria", "3 tablas", "platform_settings\noutbox_messages\naudit_logs", CYAN),
        ("KYC", "2 tablas", "kyc_cases\nkyc_documents", GREEN),
    ]

    cols = 3
    w, h = 0.295, 0.225
    gapx, gapy = 0.028, 0.055
    for i, (title, count, body, color) in enumerate(groups):
        row, col = divmod(i, cols)
        x = 0.025 + col * (w + gapx)
        y = 0.655 - row * (h + gapy)
        _box(ax, x, y, w, h, f"{title}  ({count})", body,
             fill=WHITE, edge=color, title_size=9.2, body_size=6.9, lw=1.4)
        ax.add_patch(FancyBboxPatch((x, y + h - 0.012), w, 0.012,
                                    boxstyle="square,pad=0", linewidth=0,
                                    facecolor=color, zorder=4))

    ax.text(0.025, 0.025, "Migraciones Yii2 idempotentes  ·  claves primarias UUID publicas  ·  claves foraneas con CASCADE  ·  triggers updated_at",
            ha="left", va="bottom", fontsize=7.6, color=MID, style="italic")
    return _save(fig, "modelo_datos.png")


# ---------------------------------------------------------------------------
# 4. Propuesta de valor (comercial)
# ---------------------------------------------------------------------------
def value_chain():
    fig, ax = _canvas(12.2, 5.6, "De la necesidad del cliente al cierre conciliado")

    left = [
        ("Remitente", "Envia RUB desde Rusia"),
        ("SEDERG_PAY", "Cotiza, controla y da seguimiento"),
        ("Beneficiario", "Recibe CUP en Cuba"),
    ]
    w, h = 0.24, 0.16
    xs = [0.04, 0.38, 0.72]
    for i, (title, body) in enumerate(left):
        _box(ax, xs[i], 0.66, w, h, title, body, fill=LIGHT if i == 1 else GRAY,
             edge=BLUE if i == 1 else CYAN, title_size=10.5, body_size=8)
    _arrow(ax, 0.28, 0.74, 0.38, 0.74, color=CYAN, lw=2)
    _arrow(ax, 0.62, 0.74, 0.72, 0.74, color=CYAN, lw=2)

    pillars = [
        ("Transparencia", "Tasa oficial o administrada, comision y limites visibles antes de confirmar."),
        ("Cumplimiento", "KYC, revision AML/CTF y trazabilidad de cada decision."),
        ("Control", "Ledger de doble entrada, conciliacion y auditoria de acciones."),
        ("Confianza", "2FA, cifrado de datos sensibles y aislamiento por usuario."),
        ("Experiencia", "Portal bilingue ES/RU, responsive y con tema claro/oscuro."),
        ("Operacion", "Panel administrativo para usuarios, tasas, KYC y cuentas."),
    ]
    cols = 3
    w2, h2 = 0.295, 0.20
    gapx, gapy = 0.028, 0.05
    for i, (title, body) in enumerate(pillars):
        row, col = divmod(i, cols)
        x = 0.025 + col * (w2 + gapx)
        y = 0.33 - row * (h2 + gapy)
        _box(ax, x, y, w2, h2, title, textwrap.fill(body, 46),
             fill=WHITE, edge=CYAN if i % 2 == 0 else BLUE,
             title_size=9.8, body_size=7.4)

    ax.text(0.025, 0.02, "El mismo flujo sirve para demostracion, piloto controlado y produccion una vez superados los gates legales y bancarios.",
            ha="left", va="bottom", fontsize=7.8, color=MID, style="italic")
    return _save(fig, "propuesta_valor.png")


if __name__ == "__main__":
    architecture()
    remittance_flow()
    data_groups()
    value_chain()
