import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import clsx from 'clsx'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import ResourcePage from '@/components/crud/ResourcePage'
import { ErrorState, Spinner, Button } from '@/components/ui'
import { useToast } from '@/components/Toast'
import { fmtDate } from '@/i18n'
import { LEAD_STATUSES, PRIORITIES, SOURCES, label, leads } from './modules-crm'

interface Card { id: number; name: string; company: string | null; priority: string; source: string; budget: string | null; next_followup_at: string | null; assignee?: { name: string } | null; service?: { name_ar: string; name_en: string } | null }
interface Column { status: string; total: number; items: Card[] }

function Board() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const { can } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const canMove = can('leads.edit') || can('leads.edit_assigned')
  const [dragging, setDragging] = useState<number | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const [lost, setLost] = useState<{ id: number; reason: string } | null>(null)

  const q = useQuery({ queryKey: ['leads-board'], queryFn: () => api.get<{ data: Column[] }>('/admin/leads/board').then((r) => r.data) })
  const move = useMutation({
    mutationFn: ({ id, status, lost_reason }: { id: number; status: string; lost_reason?: string }) => api.post(`/admin/leads/${id}/status`, { status, lost_reason }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['leads-board'] }); qc.invalidateQueries({ queryKey: ['resource', 'leads'] }); setLost(null) },
    onError: (e) => { toast(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('app.error'), 'error'); setLost(null) },
  })
  const request = (id: number, status: string, from: string) => {
    if (status === from) return
    if (status === 'lost') setLost({ id, reason: '' })
    else move.mutate({ id, status })
  }

  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />

  return (
    <>
      <div className="flex gap-3 overflow-x-auto pb-2" role="list">
        {q.data!.map((col) => (
          <div key={col.status} role="listitem" aria-label={label(LEAD_STATUSES, col.status, lang)}
            onDragOver={(e) => { if (canMove) { e.preventDefault(); setOver(col.status) } }} onDragLeave={() => setOver(null)}
            onDrop={(e) => { e.preventDefault(); setOver(null); const from = q.data!.find((c) => c.items.some((i) => i.id === dragging))?.status; if (dragging && from) request(dragging, col.status, from); setDragging(null) }}
            className={clsx('w-72 shrink-0 rounded-xl border bg-surface-2/50 p-2', over === col.status ? 'border-brand-2' : 'border-line')}>
            <h3 className="mb-2 flex items-center justify-between px-1 text-sm font-semibold">{label(LEAD_STATUSES, col.status, lang)}<span className="rounded-full bg-surface px-2 text-xs text-muted">{col.total}</span></h3>
            <div className="space-y-2">
              {col.items.map((c) => (
                <article key={c.id} draggable={canMove} onDragStart={() => setDragging(c.id)} onDragEnd={() => setDragging(null)} className={clsx('rounded-lg border border-line bg-surface p-3 text-sm shadow-sm', canMove && 'cursor-grab', dragging === c.id && 'opacity-50')}>
                  <Link to={`/admin/leads/${c.id}`} className="font-semibold text-brand-2 hover:underline">{c.name}</Link>
                  {c.company && <div className="text-xs text-muted">{c.company}</div>}
                  <div className="mt-1 flex flex-wrap gap-1 text-xs">
                    <span className={clsx('rounded-full px-2 py-0.5', c.priority === 'urgent' || c.priority === 'high' ? 'bg-danger/15 text-danger' : 'bg-surface-2 text-muted')}>{label(PRIORITIES, c.priority, lang)}</span>
                    <span className="rounded-full bg-surface-2 px-2 py-0.5 text-muted">{label(SOURCES, c.source, lang)}</span>
                  </div>
                  {c.service && <div className="mt-1 text-xs text-muted">{lang === 'ar' ? c.service.name_ar : c.service.name_en}</div>}
                  {c.next_followup_at && <div className="mt-1 text-xs text-warn">{t('crm.followUp')}: {fmtDate(c.next_followup_at, lang)}</div>}
                  <div className="mt-1 flex items-center justify-between text-xs text-muted"><span>{c.assignee?.name ?? '—'}</span>
                    {canMove && <select aria-label={t('crm.moveTo')} className="rounded border border-line bg-bg p-1" value={col.status} onChange={(e) => request(c.id, e.target.value, col.status)}>
                      {LEAD_STATUSES.map((s) => <option key={s.value} value={s.value}>{lang === 'ar' ? s.label_ar : s.label_en}</option>)}</select>}
                  </div>
                </article>))}
              {col.items.length === 0 && <p className="p-2 text-center text-xs text-muted">{t('dash.none')}</p>}
              {col.total > col.items.length && <p className="text-center text-xs text-muted">+{col.total - col.items.length}</p>}
            </div>
          </div>))}
      </div>
      {lost && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onMouseDown={() => setLost(null)}>
          <form role="dialog" aria-modal="true" aria-label={t('crm.lostTitle')} className="w-full max-w-sm space-y-3 rounded-xl border border-line bg-surface p-5 shadow-xl" onMouseDown={(e) => e.stopPropagation()} onSubmit={(e) => { e.preventDefault(); move.mutate({ id: lost.id, status: 'lost', lost_reason: lost.reason }) }}>
            <h2 className="font-bold">{t('crm.lostTitle')}</h2>
            <input autoFocus aria-label={t('crm.lostReason')} placeholder={t('crm.lostReason')} className="w-full rounded-lg border border-line bg-bg p-2 text-sm" value={lost.reason} onChange={(e) => setLost({ ...lost, reason: e.target.value })} />
            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setLost(null)}>{t('crud.cancel')}</Button><Button type="submit" disabled={move.isPending}>{t('crud.confirm')}</Button></div>
          </form>
        </div>)}
    </>
  )
}

export default function LeadsPage() {
  const { t, i18n } = useTranslation()
  const [view, setView] = useState<'board' | 'table'>('board')
  return (
    <div className="space-y-3">
      <div role="tablist" aria-label="view" className="inline-flex rounded-lg border border-line bg-surface p-1 text-sm">
        {(['board', 'table'] as const).map((v) => <button key={v} role="tab" aria-selected={view === v} onClick={() => setView(v)} className={clsx('rounded-md px-3 py-1.5', view === v ? 'bg-brand text-white dark:text-bg' : 'hover:bg-surface-2')}>{t(`crm.${v}`)}</button>)}
      </div>
      {view === 'table' ? <ResourcePage cfg={leads} /> : (<><h1 className="text-xl font-bold">{leads.title[i18n.language === 'ar' ? 'ar' : 'en']}</h1><Board /></>)}
    </div>
  )
}
