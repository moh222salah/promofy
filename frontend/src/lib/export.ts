import { zipSync, strToU8 } from 'fflate'

export type Rows = string[][]

/** RFC-4180 CSV parser (quotes, escaped quotes, CRLF, BOM). */
export function parseCsv(text: string): Rows {
  const rows: Rows = []; let row: string[] = []; let cell = ''; let q = false
  const src = text.replace(/^﻿/, '')
  for (let i = 0; i < src.length; i++) {
    const c = src[i]
    if (q) { if (c === '"') { if (src[i + 1] === '"') { cell += '"'; i++ } else q = false } else cell += c }
    else if (c === '"') q = true
    else if (c === ',') { row.push(cell); cell = '' }
    else if (c === '\n' || c === '\r') { if (c === '\r' && src[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = '' }
    else cell += c
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row) }
  return rows.filter((r) => r.some((x) => x !== ''))
}

/** The server already neutralises spreadsheet formulas (leading ' on = + - @); strip that marker only for display. */
const clean = (v: string) => v.replace(/^'(?=[=+\-@\t\r])/, '')

export async function fetchRows(url: string): Promise<Rows> {
  const res = await fetch(url, { credentials: 'include', headers: { Accept: 'text/csv' } })
  if (!res.ok) throw new Error(`Export failed (${res.status})`)
  return parseCsv(await res.text()).map((r) => r.map(clean))
}

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string)).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
const colName = (i: number) => { let n = i + 1; let s = ''; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26) } return s }
const isNum = (v: string) => /^-?(0|[1-9]\d{0,14})(\.\d+)?$/.test(v)

/** Minimal valid .xlsx (inline strings — never formulas), bold frozen header, auto widths, RTL sheet for Arabic. */
export function toXlsx(rows: Rows, sheet: string, rtl: boolean): Blob {
  const widths = rows[0]?.map((_, c) => Math.min(60, Math.max(8, ...rows.slice(0, 200).map((r) => (r[c] ?? '').length + 2)))) ?? []
  const data = rows.map((r, ri) => `<row r="${ri + 1}">${r.map((v, ci) => {
    const ref = `${colName(ci)}${ri + 1}`
    if (ri > 0 && isNum(v)) return `<c r="${ref}"><v>${v}</v></c>`
    return `<c r="${ref}" t="inlineStr"${ri === 0 ? ' s="1"' : ''}><is><t xml:space="preserve">${esc(v)}</t></is></c>`
  }).join('')}</row>`).join('')
  const xml = (b: string) => `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n${b}`
  const files = {
    '[Content_Types].xml': xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>'),
    '_rels/.rels': xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'),
    'xl/workbook.xml': xml(`<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="${esc(sheet.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || 'Sheet1')}" sheetId="1" r:id="rId1"/></sheets></workbook>`),
    'xl/_rels/workbook.xml.rels': xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'),
    'xl/styles.xml': xml('<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF164E9B"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>'),
    'xl/worksheets/sheet1.xml': xml(`<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"${rtl ? ' rightToLeft="1"' : ''}><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>${widths.map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('')}</cols><sheetData>${data}</sheetData></worksheet>`),
  }
  const zipped = zipSync(Object.fromEntries(Object.entries(files).map(([k, v]) => [k, strToU8(v)])))
  return new Blob([zipped], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
}

export function download(blob: Blob, name: string) {
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000)
}

/** Print through a hidden iframe (no pop-up blockers); all cell text is HTML-escaped. */
export function printRows(title: string, rows: Rows, rtl: boolean) {
  const [head, ...body] = rows
  const html = `<!doctype html><html dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8"><title>${esc(title)}</title><style>body{font:12px system-ui,sans-serif;margin:24px;color:#0b1b36}h1{font-size:18px;margin:0 0 4px;color:#102b5c}p{margin:0 0 14px;color:#667085}table{border-collapse:collapse;width:100%}th{background:#102b5c;color:#fff;text-align:start}th,td{border:1px solid #d0d5dd;padding:5px 7px}tr:nth-child(even) td{background:#f4f7fc}@media print{@page{margin:12mm}}</style></head><body><h1>PROMOFY · ${esc(title)}</h1><p>${esc(new Date().toLocaleString(rtl ? 'ar-EG' : 'en-GB'))}</p><table><thead><tr>${(head ?? []).map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></body></html>`
  const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0'; f.srcdoc = html
  f.onload = () => { const w = f.contentWindow; if (!w) return; w.focus(); w.print(); setTimeout(() => f.remove(), 60000) }
  document.body.appendChild(f)
}
