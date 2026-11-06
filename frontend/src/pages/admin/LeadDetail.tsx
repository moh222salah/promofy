import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowRight } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { Button, Card, ErrorState, Spinner } from '@/components/ui'
import { useToast } from '@/components/Toast'
import { fmtDate } from '@/i18n'
import { LEAD_STATUSES, PRIORITIES, SOURCES, label } from './modules-crm'

const input = 'field p-2 text-sm'
const TYPES = ['note', 'call', 'email', 'whatsapp', 'meeting'] as const
const msg = (e: unknown, fb: string) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fb)

export default function LeadDetail() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const { can } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const nav = useNavigate()
  const canEdit = can('leads.edit') || can('leads.edit_assigned')
  const lead = useQuery({ queryKey: ['lead', id], queryFn: () => api.get<{ data: any }>(`/admin/leads/${id}`).then((r) => r.data) })
  const acts = useQuery({ queryKey: ['lead-acts', id], queryFn: () => api.get<{ data: any[] }>(`/admin/leads/${id}/activities`).then((r) => r.data) })
  const staff = useQuery({ queryKey: ['options', '/admin/users/options'], queryFn: () => api.get<{ data: any[] }>('/admin/users/options').then((r) => r.data), enabled: can('leads.assign') })
  const [act, setAct] = useState<{ type: string; body: string }>({ type: 'note', body: '' })
  const [fu, setFu] = useState({ at: '', note: '' })
  const [lostReason, setLostReason] = useState('')

  const refresh = () => { qc.invalidateQueries({ queryKey: ['lead', id] }); qc.invalidateQueries({ queryKey: ['lead-acts', id] }); qc.invalidateQueries({ queryKey: ['leads-board'] }); qc.invalidateQueries({ queryKey: ['resource', 'leads'] }) }
  const ok = (text = t('crud.done')) => { toast(text); refresh() }
  const fail = (e: unknown) => toast(msg(e, t('app.error')), 'error')
  const status = useMutation({ mutationFn: (s: string) => api.post(`/admin/leads/${id}/status`, { status: s, lost_reason: s === 'lost' ? lostReason : undefined }), onSuccess: () => ok(), onError: fail })
  const assign = useMutation({ mutationFn: (uid: number | null) => api.put(`/admin/leads/${id}`, { assigned_to: uid }), onSuccess: () => ok(), onError: fail })
  const log = useMutation({ mutationFn: () => api.post(`/admin/leads/${id}/activities`, act), onSuccess: () => { setAct({ ...act, body: '' }); ok() }, onError: fail })
  const followUp = useMutation({ mutationFn: () => api.post(`/admin/leads/${id}/follow-up`, { next_followup_at: new Date(fu.at).toISOString(), note: fu.note || undefined }), onSuccess: () => { setFu({ at: '', note: '' }); ok() }, onError: fail })
  const convert = useMutation({ mutationFn: () => api.post<{ data: { id: number } }>(`/admin/leads/${id}/convert`), onSuccess: () => ok(t('crm.converted')), onError: fail })
  const remove = useMutation({ mutationFn: () => api.delete(`/admin/leads/${id}`), onSuccess: () => { toast(t('crud.deleted')); nav('/admin/leads') }, onError: fail })

  if (lead.isLoading) return <Spinner />
  if (lead.isError) return <ErrorState error={lead.error} onRetry={() => lead.refetch()} />
  const l = lead.data
  const attribution = l.attribution ?? {}

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/leads" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight size={14} className="ltr:rotate-180" aria-hidden />{t('nav.leads')}</Link>
        <h1 className="text-xl font-bold">{l.name}</h1>
        <span className="rounded-full bg-surface-2 px-3 py-1 text-xs font-semibold">{label(LEAD_STATUSES, l.status, lang)}</span>
        {l.customer_id && <span className="rounded-full bg-success/15 px-3 py-1 text-xs font-semibold text-success">{t('crm.isCustomer')}</span>}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title={t('crm.details')}>
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              {([['company', l.company], ['email', l.email], ['phone', l.phone], ['country', l.country_code], ['industry', l.industry], ['source', label(SOURCES, l.source, lang)], ['priority', label(PRIORITIES, l.priority, lang)],
                ['budget', l.budget], ['timeline', l.timeline], ['nextFollowUp', l.next_followup_at ? fmtDate(l.next_followup_at, lang) : null]] as const).map(([k, v]) => (
                <div key={k}><dt className="text-xs text-muted">{t(`crm.f.${k}`)}</dt><dd dir={k === 'email' || k === 'phone' ? 'ltr' : undefined} className={k === 'email' || k === 'phone' ? 'text-start' : ''}>{v || '—'}</dd></div>))}
              {l.description && <div className="sm:col-span-2"><dt className="text-xs text-muted">{t('crm.f.description')}</dt><dd className="whitespace-pre-wrap">{l.description}</dd></div>}
              {l.lost_reason && <div className="sm:col-span-2"><dt className="text-xs text-muted">{t('crm.lostReason')}</dt><dd>{l.lost_reason}</dd></div>}
              {Object.keys(attribution).length > 0 && <div className="sm:col-span-2"><dt className="text-xs text-muted">{t('crm.attribution')}</dt><dd dir="ltr" className="text-start text-xs">{Object.entries(attribution).map(([k, v]) => `${k}=${v}`).join(' · ')}</dd></div>}
            </dl>
          </Card>

          <Card title={t('crm.timeline')}>
            {canEdit && (
              <form className="mb-4 flex flex-wrap items-start gap-2" onSubmit={(e) => { e.preventDefault(); if (act.body.trim()) log.mutate() }}>
                <select aria-label={t('crm.type')} className={input} value={act.type} onChange={(e) => setAct({ ...act, type: e.target.value })}>{TYPES.map((x) => <option key={x} value={x}>{t(`crm.types.${x}`)}</option>)}</select>
                <input aria-label={t('crm.activityBody')} placeholder={t('crm.activityBody')} className={`${input} min-w-48 flex-1`} maxLength={3000} value={act.body} onChange={(e) => setAct({ ...act, body: e.target.value })} />
                <Button type="submit" disabled={log.isPending || !act.body.trim()}>{t('order.addNote')}</Button>
              </form>)}
            {acts.isLoading ? <Spinner /> : (acts.data ?? []).length === 0 ? <p className="text-sm text-muted">{t('dash.none')}</p> : (
              <ol className="space-y-3 text-sm">{acts.data!.map((a) => (
                <li key={a.id} className="border-s-2 border-line ps-3"><div className="flex flex-wrap gap-2"><span className="font-medium">{t(`crm.types.${a.type}`, { defaultValue: a.type })}</span><span className="ms-auto text-xs text-muted">{a.user?.name ?? t('order.system')} · {fmtDate(a.created_at, lang)}</span></div>{a.body && <p className="whitespace-pre-wrap text-muted">{a.body}</p>}</li>))}</ol>)}
          </Card>
        </div>

        <div className="space-y-4">
          {canEdit && (
            <Card title={t('crm.pipeline')}>
              <div className="flex flex-wrap gap-1">{LEAD_STATUSES.filter((s) => s.value !== l.status && s.value !== 'lost').map((s) => <Button key={s.value} variant="outline" disabled={status.isPending} onClick={() => status.mutate(s.value as string)}>{lang === 'ar' ? s.label_ar : s.label_en}</Button>)}</div>
              {l.status !== 'lost' && (
                <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); status.mutate('lost') }}>
                  <input aria-label={t('crm.lostReason')} placeholder={t('crm.lostReason')} className={`${input} flex-1`} value={lostReason} onChange={(e) => setLostReason(e.target.value)} />
                  <Button type="submit" variant="outline" className="!text-danger" disabled={status.isPending}>{label(LEAD_STATUSES, 'lost', lang)}</Button>
                </form>)}
              {can('leads.assign') && (
                <label className="mt-3 block text-xs text-muted">{t('order.assignee')}
                  <select className={`${input} mt-0.5 block w-full`} value={l.assigned_to ?? ''} onChange={(e) => assign.mutate(e.target.value ? Number(e.target.value) : null)}>
                    <option value="">—</option>{staff.data?.map((u) => <option key={u.value} value={u.value}>{u.label_en}</option>)}</select></label>)}
            </Card>)}
          {canEdit && (
            <Card title={t('crm.followUp')}>
              <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); if (fu.at) followUp.mutate() }}>
                <input required type="datetime-local" dir="ltr" aria-label={t('crm.followUp')} className={`${input} w-full`} value={fu.at} onChange={(e) => setFu({ ...fu, at: e.target.value })} />
                <input aria-label={t('crm.followNote')} placeholder={t('crm.followNote')} className={`${input} w-full`} value={fu.note} onChange={(e) => setFu({ ...fu, note: e.target.value })} />
                <Button type="submit" className="w-full" disabled={followUp.isPending || !fu.at}>{t('crm.schedule')}</Button>
                <p className="text-xs text-muted">{t('crm.followHint')}</p>
              </form>
            </Card>)}
          <Card title={t('crm.actions')}>
            <div className="flex flex-col gap-2">
              {can('customers.create') && !l.customer_id && <Button onClick={() => convert.mutate()} disabled={convert.isPending}>{t('crm.convert')}</Button>}
              {can('quotations.create') && <Link to={`/admin/quotations?lead=${l.id}`} className="rounded-lg border border-line px-4 py-2 text-center text-sm hover:bg-surface-2">{t('crm.createQuote')}</Link>}
              {can('leads.delete') && <Button variant="outline" className="!text-danger" onClick={() => { if (window.confirm(t('crud.deleteTitle'))) remove.mutate() }}>{t('crud.delete')}</Button>}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
