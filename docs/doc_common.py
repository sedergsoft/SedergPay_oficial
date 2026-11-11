# -*- coding: utf-8 -*-
"""Utilidades de formato para los documentos de SEDERG_PAY."""
from __future__ import annotations

import os
from typing import Iterable, Sequence

from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_ROW_HEIGHT_RULE, WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_TAB_ALIGNMENT, WD_TAB_LEADER
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

# ---------------------------------------------------------------------------
# Identidad visual
# ---------------------------------------------------------------------------
NAVY = RGBColor(0x10, 0x26, 0x4D)
BLUE = RGBColor(0x0B, 0x4F, 0x9C)
CYAN = RGBColor(0x00, 0xAE, 0xEF)
GREEN = RGBColor(0x00, 0xA5, 0x5A)
AMBER = RGBColor(0xB2, 0x6A, 0x00)
RED = RGBColor(0xE5, 0x39, 0x35)
MID = RGBColor(0x5B, 0x67, 0x79)
DARK = RGBColor(0x1F, 0x29, 0x37)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)

NAVY_HEX = "10264D"
BLUE_HEX = "0B4F9C"
CYAN_HEX = "00AEEF"
GREEN_HEX = "00A55A"
RED_HEX = "E53935"
LIGHT_HEX = "E8F1FA"
LIGHTER_HEX = "F5F8FC"
GRAY_HEX = "F4F6F9"
BORDER_HEX = "D5DEEA"

TEXT_WIDTH_CM = 16.5
BODY_FONT = "Calibri"
MONO_FONT = "Consolas"


# ---------------------------------------------------------------------------
# XML helpers
# ---------------------------------------------------------------------------
def _set_cell_shading(cell, fill: str) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = tc_pr.find(qn("w:shd"))
    if shd is None:
        shd = OxmlElement("w:shd")
        tc_pr.append(shd)
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)


def _set_cell_margins(cell, top=90, left=130, bottom=90, right=130) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    tc_mar = tc_pr.find(qn("w:tcMar"))
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tc_pr.append(tc_mar)
    for tag, value in (("w:top", top), ("w:left", left), ("w:bottom", bottom), ("w:right", right)):
        node = tc_mar.find(qn(tag))
        if node is None:
            node = OxmlElement(tag)
            tc_mar.append(node)
        node.set(qn("w:w"), str(value))
        node.set(qn("w:type"), "dxa")


def _set_table_borders(table, color=BORDER_HEX, size=6, inside=True) -> None:
    tbl = table._tbl
    tbl_pr = tbl.tblPr
    borders = tbl_pr.find(qn("w:tblBorders"))
    if borders is None:
        borders = OxmlElement("w:tblBorders")
        tbl_pr.append(borders)
    edges = ["top", "left", "bottom", "right"]
    if inside:
        edges += ["insideH", "insideV"]
    for edge in edges:
        el = borders.find(qn(f"w:{edge}"))
        if el is None:
            el = OxmlElement(f"w:{edge}")
            borders.append(el)
        el.set(qn("w:val"), "single")
        el.set(qn("w:sz"), str(size))
        el.set(qn("w:space"), "0")
        el.set(qn("w:color"), color)


def _set_table_no_borders(table) -> None:
    tbl = table._tbl
    tbl_pr = tbl.tblPr
    borders = OxmlElement("w:tblBorders")
    for edge in ["top", "left", "bottom", "right", "insideH", "insideV"]:
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "nil")
        borders.append(el)
    tbl_pr.append(borders)


def _repeat_header(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    header = OxmlElement("w:tblHeader")
    header.set(qn("w:val"), "true")
    tr_pr.append(header)


def _cant_split(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    cant = OxmlElement("w:cantSplit")
    cant.set(qn("w:val"), "true")
    tr_pr.append(cant)


def _add_bottom_border(paragraph, color=CYAN_HEX, size=8, space=4) -> None:
    p_pr = paragraph._p.get_or_add_pPr()
    p_bdr = OxmlElement("w:pBdr")
    bottom = OxmlElement("w:bottom")
    bottom.set(qn("w:val"), "single")
    bottom.set(qn("w:sz"), str(size))
    bottom.set(qn("w:space"), str(space))
    bottom.set(qn("w:color"), color)
    p_bdr.append(bottom)
    p_pr.append(p_bdr)


def _add_page_field(paragraph) -> None:
    run = paragraph.add_run()
    begin = OxmlElement("w:fldChar")
    begin.set(qn("w:fldCharType"), "begin")
    instr = OxmlElement("w:instrText")
    instr.set(qn("xml:space"), "preserve")
    instr.text = " PAGE "
    separate = OxmlElement("w:fldChar")
    separate.set(qn("w:fldCharType"), "separate")
    text = OxmlElement("w:t")
    text.text = "1"
    end = OxmlElement("w:fldChar")
    end.set(qn("w:fldCharType"), "end")
    run._r.append(begin)
    run._r.append(instr)
    run._r.append(separate)
    run._r.append(text)
    run._r.append(end)


def _set_char_spacing(run, twips: int) -> None:
    r_pr = run._r.get_or_add_rPr()
    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:val"), str(twips))
    r_pr.append(spacing)


# ---------------------------------------------------------------------------
# Estilos y documento
# ---------------------------------------------------------------------------
def new_document() -> Document:
    doc = Document()
    section = doc.sections[0]
    section.page_width = Cm(21.0)
    section.page_height = Cm(29.7)
    section.top_margin = Cm(2.2)
    section.bottom_margin = Cm(2.0)
    section.left_margin = Cm(2.3)
    section.right_margin = Cm(2.2)
    section.header_distance = Cm(0.9)
    section.footer_distance = Cm(0.8)

    normal = doc.styles["Normal"]
    normal.font.name = BODY_FONT
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = DARK
    normal.paragraph_format.space_after = Pt(5)
    normal.paragraph_format.line_spacing = 1.15
    _set_style_east_asia(normal, BODY_FONT)

    for name, size, color, before, after in (
        ("Heading 1", 19, BLUE, 18, 8),
        ("Heading 2", 13.5, NAVY, 14, 5),
        ("Heading 3", 11.5, BLUE, 10, 4),
    ):
        style = doc.styles[name]
        style.font.name = BODY_FONT
        style.font.size = Pt(size)
        style.font.bold = True
        style.font.color.rgb = color
        style.paragraph_format.space_before = Pt(before)
        style.paragraph_format.space_after = Pt(after)
        style.paragraph_format.keep_with_next = True
        _set_style_east_asia(style, BODY_FONT)

    for name in ("List Bullet", "List Number"):
        style = doc.styles[name]
        style.font.name = BODY_FONT
        style.font.size = Pt(10.5)
        style.font.color.rgb = DARK
        style.paragraph_format.space_after = Pt(2)
        style.paragraph_format.line_spacing = 1.12

    return doc


def _set_style_east_asia(style, font_name: str) -> None:
    r_pr = style.element.get_or_add_rPr()
    r_fonts = r_pr.find(qn("w:rFonts"))
    if r_fonts is None:
        r_fonts = OxmlElement("w:rFonts")
        r_pr.append(r_fonts)
    r_fonts.set(qn("w:ascii"), font_name)
    r_fonts.set(qn("w:hAnsi"), font_name)
    r_fonts.set(qn("w:eastAsia"), font_name)
    r_fonts.set(qn("w:cs"), font_name)


def setup_header_footer(section, header_left: str, header_right: str, footer_left: str) -> None:
    section.different_first_page_header_footer = True

    header = section.header
    p = header.paragraphs[0]
    p.text = ""
    p.paragraph_format.tab_stops.add_tab_stop(Cm(TEXT_WIDTH_CM), WD_TAB_ALIGNMENT.RIGHT)
    p.paragraph_format.space_after = Pt(2)
    run = p.add_run(header_left)
    _run_style(run, size=8, color=MID)
    run = p.add_run("\t" + header_right)
    _run_style(run, size=8, color=MID)
    _add_bottom_border(p, color=BORDER_HEX, size=6, space=2)

    footer = section.footer
    p = footer.paragraphs[0]
    p.text = ""
    p.paragraph_format.tab_stops.add_tab_stop(Cm(TEXT_WIDTH_CM), WD_TAB_ALIGNMENT.RIGHT)
    run = p.add_run(footer_left)
    _run_style(run, size=8, color=MID)
    run = p.add_run("\t")
    run = p.add_run("Página ")
    _run_style(run, size=8, color=MID)
    _add_page_field(p)
    for run in p.runs:
        _run_style(run, size=8, color=MID)


def _run_style(run, size=None, color=None, bold=None, italic=None, font=None) -> None:
    if font:
        run.font.name = font
    if size is not None:
        run.font.size = Pt(size)
    if color is not None:
        run.font.color.rgb = color
    if bold is not None:
        run.bold = bold
    if italic is not None:
        run.italic = italic


# ---------------------------------------------------------------------------
# Portada
# ---------------------------------------------------------------------------
def add_cover(doc, doc_kicker: str, title: str, subtitle: str, doc_type: str,
              meta: Sequence[tuple[str, str]], footer_note: str) -> None:
    # espacio superior
    for _ in range(2):
        p = doc.add_paragraph()
        p.paragraph_format.space_after = Pt(0)

    table = doc.add_table(rows=1, cols=1)
    table.autofit = False
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    _set_table_no_borders(table)
    cell = table.cell(0, 0)
    cell.width = Cm(TEXT_WIDTH_CM)
    _set_cell_shading(cell, NAVY_HEX)
    _set_cell_margins(cell, top=520, left=520, bottom=520, right=520)

    p = cell.paragraphs[0]
    p.paragraph_format.space_after = Pt(18)
    run = p.add_run(doc_kicker.upper())
    _run_style(run, size=10, color=CYAN, bold=True)
    _set_char_spacing(run, 60)

    p = cell.add_paragraph()
    p.paragraph_format.space_after = Pt(6)
    run = p.add_run(title)
    _run_style(run, size=40, color=WHITE, bold=True)
    _set_char_spacing(run, 20)

    p = cell.add_paragraph()
    p.paragraph_format.space_after = Pt(18)
    run = p.add_run(subtitle)
    _run_style(run, size=15, color=WHITE)

    p = cell.add_paragraph()
    p.paragraph_format.space_after = Pt(14)
    _add_bottom_border(p, color=CYAN_HEX, size=18, space=0)

    p = cell.add_paragraph()
    p.paragraph_format.space_after = Pt(0)
    run = p.add_run(doc_type)
    _run_style(run, size=11.5, color=RGBColor(0xD9, 0xE6, 0xF5), bold=True)

    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(10)

    meta_table = doc.add_table(rows=0, cols=2)
    meta_table.autofit = False
    meta_table.alignment = WD_TABLE_ALIGNMENT.CENTER
    _set_table_no_borders(meta_table)
    for label, value in meta:
        row = meta_table.add_row()
        row.height = Cm(0.8)
        c0, c1 = row.cells
        c0.width = Cm(4.6)
        c1.width = Cm(11.9)
        _set_cell_margins(c0, top=50, bottom=50, left=0, right=80)
        _set_cell_margins(c1, top=50, bottom=50, left=80, right=0)
        p0 = c0.paragraphs[0]
        p0.paragraph_format.space_after = Pt(0)
        r0 = p0.add_run(label.upper())
        _run_style(r0, size=8, color=MID, bold=True)
        _set_char_spacing(r0, 30)
        p1 = c1.paragraphs[0]
        p1.paragraph_format.space_after = Pt(0)
        r1 = p1.add_run(value)
        _run_style(r1, size=9.5, color=DARK, bold=True)

    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(26)
    p.paragraph_format.space_after = Pt(0)
    _add_bottom_border(p, color=CYAN_HEX, size=10, space=6)
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(6)
    run = p.add_run(footer_note)
    _run_style(run, size=8, color=MID, italic=True)

    doc.add_page_break()


# ---------------------------------------------------------------------------
# Bloques de contenido
# ---------------------------------------------------------------------------
def H1(doc, text: str, page_break: bool = True):
    if page_break:
        doc.add_page_break()
    p = doc.add_paragraph(text, style="Heading 1")
    _add_bottom_border(p, color=CYAN_HEX, size=8, space=4)
    return p


def H2(doc, text: str):
    return doc.add_paragraph(text, style="Heading 2")


def H3(doc, text: str):
    return doc.add_paragraph(text, style="Heading 3")


def P(doc, text: str = "", bold=False, italic=False, size=None, color=None,
      align=None, space_after=5, space_before=0, keep_with_next=False):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.space_before = Pt(space_before)
    p.paragraph_format.keep_with_next = keep_with_next
    if align == "center":
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    elif align == "right":
        p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    elif align == "justify":
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    run = p.add_run(text)
    _run_style(run, size=size or 10.5, color=color or DARK, bold=bold, italic=italic)
    return p


def PR(doc, runs: Iterable[tuple[str, dict]], space_after=5, space_before=0, align=None):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.space_before = Pt(space_before)
    if align == "center":
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for text, opts in runs:
        run = p.add_run(text)
        _run_style(run, size=opts.get("size", 10.5), color=opts.get("color", DARK),
                   bold=opts.get("bold"), italic=opts.get("italic"))
    return p


def BUL(doc, items: Sequence[str], level=0):
    for item in items:
        p = doc.add_paragraph(style="List Bullet")
        p.paragraph_format.left_indent = Cm(0.65 + 0.55 * level)
        p.paragraph_format.first_line_indent = Cm(-0.35)
        p.paragraph_format.space_after = Pt(2)
        run = p.add_run(item)
        _run_style(run, size=10.5, color=DARK)


def STEPS(doc, items: Sequence[str]):
    for index, item in enumerate(items, 1):
        p = doc.add_paragraph()
        p.paragraph_format.left_indent = Cm(0.9)
        p.paragraph_format.first_line_indent = Cm(-0.9)
        p.paragraph_format.space_after = Pt(3)
        run = p.add_run(f"{index:02d}")
        _run_style(run, size=10, color=CYAN, bold=True)
        run = p.add_run("   " + item)
        _run_style(run, size=10.5, color=DARK)


def _status_color(value: str):
    clean = value.strip().lower()
    if clean in {"implementado", "completado", "activo", "sí", "si", "ok", "publicado", "aprobado", "verificado"}:
        return GREEN, True
    if clean in {"simulado", "parcial", "en preparación", "en preparacion", "en curso", "sandbox", "preview"}:
        return AMBER, True
    if clean in {"pendiente", "no", "bloqueado", "rechazado", "fuera de alcance"}:
        return RED, True
    return None, False


def TBL(doc, headers: Sequence[str], rows: Sequence[Sequence[str]],
        widths: Sequence[float] | None = None, font_size=8.8, header_fill=BLUE_HEX,
        first_col_bold=False, align_center_cols: Sequence[int] = ()):
    n_cols = len(headers)
    table = doc.add_table(rows=1, cols=n_cols)
    table.autofit = False
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    _set_table_borders(table, color=BORDER_HEX, size=5)

    if widths is None:
        widths = [TEXT_WIDTH_CM / n_cols] * n_cols
    total = sum(widths)
    widths = [w * TEXT_WIDTH_CM / total for w in widths]

    header_row = table.rows[0]
    _repeat_header(header_row)
    _cant_split(header_row)
    for i, header in enumerate(headers):
        cell = header_row.cells[i]
        cell.width = Cm(widths[i])
        _set_cell_shading(cell, header_fill)
        _set_cell_margins(cell, top=80, bottom=80, left=110, right=110)
        p = cell.paragraphs[0]
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.line_spacing = 1.0
        run = p.add_run(header)
        _run_style(run, size=font_size - 0.2, color=WHITE, bold=True)

    for r_index, row_data in enumerate(rows):
        row = table.add_row()
        _cant_split(row)
        for c_index, value in enumerate(row_data):
            cell = row.cells[c_index]
            cell.width = Cm(widths[c_index])
            _set_cell_margins(cell, top=70, bottom=70, left=110, right=110)
            if r_index % 2 == 1:
                _set_cell_shading(cell, LIGHTER_HEX)
            p = cell.paragraphs[0]
            p.paragraph_format.space_after = Pt(0)
            p.paragraph_format.line_spacing = 1.05
            if c_index in align_center_cols:
                p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            text = "" if value is None else str(value)
            run = p.add_run(text)
            color, bold = _status_color(text)
            _run_style(run, size=font_size, color=color or DARK,
                       bold=bold or (first_col_bold and c_index == 0))
    return table


def CALL(doc, title: str, body: str, fill=LIGHT_HEX, border=CYAN_HEX):
    table = doc.add_table(rows=1, cols=1)
    table.autofit = False
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    _set_table_borders(table, color=border, size=18)
    cell = table.cell(0, 0)
    cell.width = Cm(TEXT_WIDTH_CM)
    _set_cell_shading(cell, fill)
    _set_cell_margins(cell, top=150, bottom=150, left=190, right=190)
    p = cell.paragraphs[0]
    p.paragraph_format.space_after = Pt(2)
    run = p.add_run(title)
    _run_style(run, size=10, color=NAVY, bold=True)
    p2 = cell.add_paragraph()
    p2.paragraph_format.space_after = Pt(0)
    run = p2.add_run(body)
    _run_style(run, size=9.6, color=DARK)
    doc.add_paragraph().paragraph_format.space_after = Pt(0)


def NOTE(doc, text: str):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(2)
    p.paragraph_format.space_after = Pt(6)
    run = p.add_run(text)
    _run_style(run, size=8.5, color=MID, italic=True)
    return p


def CODE(doc, text: str):
    table = doc.add_table(rows=1, cols=1)
    table.autofit = False
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    _set_table_borders(table, color="E1E7EF", size=4)
    cell = table.cell(0, 0)
    cell.width = Cm(TEXT_WIDTH_CM)
    _set_cell_shading(cell, GRAY_HEX)
    _set_cell_margins(cell, top=110, bottom=110, left=150, right=150)
    p = cell.paragraphs[0]
    p.paragraph_format.space_after = Pt(0)
    p.paragraph_format.line_spacing = 1.05
    run = p.add_run(text)
    _run_style(run, size=8.2, color=DARK, font=MONO_FONT)
    doc.add_paragraph().paragraph_format.space_after = Pt(0)


def KPI(doc, items: Sequence[tuple[str, str]], fills: Sequence[str] | None = None):
    table = doc.add_table(rows=1, cols=len(items))
    table.autofit = False
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    _set_table_no_borders(table)
    width = TEXT_WIDTH_CM / len(items)
    for i, (value, label) in enumerate(items):
        cell = table.cell(0, i)
        cell.width = Cm(width)
        fill = (fills[i] if fills else (LIGHT_HEX if i % 2 == 0 else LIGHTER_HEX))
        _set_cell_shading(cell, fill)
        _set_cell_margins(cell, top=150, bottom=150, left=100, right=100)
        p = cell.paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p.paragraph_format.space_after = Pt(2)
        run = p.add_run(value)
        _run_style(run, size=20, color=BLUE, bold=True)
        p2 = cell.add_paragraph()
        p2.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p2.paragraph_format.space_after = Pt(0)
        run = p2.add_run(label)
        _run_style(run, size=8, color=MID, bold=True)
    doc.add_paragraph().paragraph_format.space_after = Pt(0)


def IMAGE(doc, path: str, caption: str | None = None, width_cm=TEXT_WIDTH_CM):
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(4)
    p.paragraph_format.space_after = Pt(2)
    p.add_run().add_picture(path, width=Cm(width_cm))
    if caption:
        p2 = doc.add_paragraph()
        p2.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p2.paragraph_format.space_after = Pt(8)
        run = p2.add_run(caption)
        _run_style(run, size=8, color=MID, italic=True)


def TOC(doc, entries: Sequence[tuple[int, str]]):
    for level, text in entries:
        p = doc.add_paragraph()
        p.paragraph_format.space_after = Pt(2)
        if level == 0:
            p.paragraph_format.space_before = Pt(7)
            run = p.add_run(text)
            _run_style(run, size=10.5, color=NAVY, bold=True)
        elif level == 1:
            p.paragraph_format.left_indent = Cm(0.7)
            run = p.add_run("—  " + text)
            _run_style(run, size=9.8, color=DARK)
        else:
            p.paragraph_format.left_indent = Cm(1.4)
            run = p.add_run("·  " + text)
            _run_style(run, size=9.2, color=MID)


def SPACER(doc, points=6):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(points)
    return p


def set_core_properties(doc, title: str, subject: str, keywords: str, comments: str):
    cp = doc.core_properties
    cp.title = title
    cp.subject = subject
    cp.author = "SEDERG_PAY"
    cp.last_modified_by = "SEDERG_PAY"
    cp.keywords = keywords
    cp.comments = comments
    cp.category = "Documento corporativo"
