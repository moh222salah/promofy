import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FileSpreadsheet, FileText, Printer } from 'lucide-react'
import { download, fetchRows, printRows, toXlsx, type Rows } from '@/lib/export'
import { useToast } from '@/components/Toast'

/** CSV · Excel · Print — one data fetch (the permission-checked CSV endpoint), three outputs. */
export function ExportButtons({ url, name, title, compact = false }: { url: string; name: string; title: string; compact?: boolean }) {
  const { t, i18n } = useTranslation(); const toast = useToast()
  const rtl = i18n.language === 'ar'; const [busy, setBusy] = useState<string | null>(null)
  const stamp = new Date().toISOString().slice(0, 10)
  const run = async (kind: 'csv' | 'xlsx' | 'print') => {
    if (busy) return
    setBusy(kind)
    try {
      const rows: Rows = await fetchRows(url)
      if (rows.length === 0) { toast(t('export.empty'), 'error'); return }
      if (kind === 'csv') download(new Blob(['﻿' + rows.map((r) => r.map((c) => (/[",\n\r]/.test(c) ? `"${c.replace(/"/g, '""')}"` : c)).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }), `${name}-${stamp}.csv`)
      else if (kind === 'xlsx') download(toXlsx(rows, title, rtl), `${name}-${stamp}.xlsx`)
      else printRows(title, rows, rtl)
    } catch (e) { toast((e as Error).message || t('app.error'), 'error') } finally { setBusy(null) }
  }
  const items = [
    { k: 'csv' as const, Icon: FileText, label: 'CSV', tone: 'text-sky-400' },
    { k: 'xlsx' as const, Icon: FileSpreadsheet, label: 'Excel', tone: 'text-emerald-400' },
    { k: 'print' as const, Icon: Printer, label: t('export.print'), tone: 'text-violet-400' },
  ]
  return (
    <div role="group" aria-label={`${t('crud.export')}: ${title}`} className="glass inline-flex items-center gap-0.5 rounded-xl p-0.5">
      {items.map(({ k, Icon, label, tone }) => (
        <button key={k} type="button" data-export={k} title={`${label} — ${title}`} aria-label={`${label}: ${title}`} disabled={!!busy} onClick={() => run(k)}
          className={`icon-btn group inline-flex items-center gap-1.5 ${compact ? '!px-1.5 text-[11px]' : '!px-2.5 text-xs'} font-semibold ${busy === k ? 'animate-pulse' : ''}`}>
          <Icon size={compact ? 14 : 16} className={`${tone} transition-transform group-hover:-translate-y-0.5 group-hover:scale-110`} aria-hidden />{!compact && <span>{label}</span>}
        </button>))}
    </div>
  )
}
