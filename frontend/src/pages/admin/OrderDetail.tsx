import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowRight, FileText } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { Button, Card, ErrorState, Spinner } from '@/components/ui'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useToast } from '@/components/Toast'
import { fmtDate, fmtMoney } from '@/i18n'
import { label, ORDER_STATUSES, PAYMENT_STATUSES } from './modules-commerce'

/** Mirrors OrderService::WORKFLOW for button visibility only — the API enforces the real transitions. paid/refunded are never manual. */
const NEXT: Record<string, string[]> = {
  pending: ['awaiting_payment', 'processing', 'cancelled'], awaiting_payment: ['pending', 'cancelled'], paid: ['processing', 'in_progress', 'completed'],
  processing: ['in_progress', 'completed', 'cancelled'], in_progress: ['completed', 'cancelled'], completed: [], cancelled: [], refunded: [],
}

const msg = (e: unknown, fb: string) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fb)
const input = 'rounded-lg border border-line bg-bg p-2 text-sm'

export default function OrderDetail() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const { can } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const key = ['order', id]
  const q = useQuery({ queryKey: key, queryFn: () => api.get<{ data: any }>(`/admin/orders/${id}`).then((r) => r.data) })
  const staff = useQuery({ queryKey: ['options', '/admin/users/options'], queryFn: () => api.get<{ data: any[] }>('/admin/users/options').then((r) => r.data), enabled: can('orders.assign') })
  const [note, setNote] = useState('')
  const [pay, setPay] = useState({ amount: '', note: '' })
  const [refund, setRefund] = useState<{ id: number; amount: string; reason: string } | null>(null)
  const [cancel, setCancel] = useState(false)

  const done = (text: string) => { toast(text); qc.invalidateQueries({ queryKey: key }); qc.invalidateQueries({ queryKey: ['resource', 'orders'] }) }
  const fail = (e: unknown) => toast(msg(e, t('app.error')), 'error')
  const status = useMutation({ mutationFn: (s: string) => api.post(`/admin/orders/${id}/status`, { status: s }), onSuccess: () => { setCancel(false); done(t('crud.done')) }, onError: (e) => { setCancel(false); fail(e) } })
  const assign = useMutation({ mutationFn: (user_id: number | null) => api.post(`/admin/orders/${id}/assign`, { user_id }), onSuccess: () => done(t('crud.done')), onError: fail })
  const addNote = useMutation({ mutationFn: () => api.post(`/admin/orders/${id}/notes`, { body: note }), onSuccess: () => { setNote(''); done(t('crud.done')) }, onError: fail })
  const record = useMutation({ mutationFn: () => api.post(`/admin/orders/${id}/payments`, pay), onSuccess: () => { setPay({ amount: '', note: '' }); done(t('crud.done')) }, onError: fail })
  const doRefund = useMutation({ mutationFn: () => api.post(`/admin/payments/${refund!.id}/refund`, { amount: refund!.amount, reason: refund!.reason }), onSuccess: () => { setRefund(null); done(t('crud.done')) }, onError: fail })

  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  const o = q.data
  const money = (v: string | number) => fmtMoney(Number(v), o.currency_code)
  const next = (NEXT[o.status] ?? []).filter((s) => (s === 'cancelled' ? can('orders.cancel') : can('orders.edit')))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/orders" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight size={14} className="ltr:rotate-180" aria-hidden />{t('nav.orders')}</Link>
        <h1 className="text-xl font-bold" dir="ltr">{o.number}</h1>
        <span className="rounded-full bg-surface-2 px-3 py-1 text-xs font-semibold">{label(ORDER_STATUSES, o.status, lang)}</span>
        <a className="ms-auto inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm hover:bg-surface-2" href={`/api/v1/admin/orders/${o.id}/invoice`} target="_blank" rel="noreferrer"><FileText size={16} aria-hidden />{t('order.invoice')}</a>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title={t('order.items')}>
            <div className="overflow-x-auto"><table className="w-full text-sm">
              <thead><tr className="text-muted"><th className="p-2 text-start">{t('order.item')}</th><th className="p-2">{t('order.qty')}</th><th className="p-2 text-end">{t('order.unit')}</th><th className="p-2 text-end">{t('order.line')}</th></tr></thead>
              <tbody>{o.items.map((i: any) => <tr key={i.id} className="border-t border-line"><td className="p-2">{lang === 'ar' ? i.name_ar : i.name_en}<div className="text-xs text-muted" dir="ltr">{i.sku}</div></td><td className="p-2 text-center">{i.quantity}</td><td className="p-2 text-end tabular-nums">{money(i.unit_price)}</td><td className="p-2 text-end tabular-nums">{money(i.line_total)}</td></tr>)}</tbody>
            </table></div>
            <dl className="mt-3 ms-auto max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-muted">{t('order.subtotal')}</dt><dd className="tabular-nums">{money(o.subtotal)}</dd></div>
              {Number(o.discount_total) > 0 && <div className="flex justify-between"><dt className="text-muted">{t('order.discount')}</dt><dd className="tabular-nums">−{money(o.discount_total)}</dd></div>}
              <div className="flex justify-between"><dt className="text-muted">{t('order.tax')}</dt><dd className="tabular-nums">{money(o.tax_total)}</dd></div>
              <div className="flex justify-between border-t border-line pt-1 font-bold"><dt>{t('order.total')}</dt><dd className="tabular-nums">{money(o.total)}</dd></div>
            </dl>
          </Card>

          {o.payments && (
            <Card title={t('order.payments')}>
              {o.payments.length === 0 ? <p className="text-sm text-muted">{t('dash.none')}</p> : o.payments.map((p: any) => (
                <div key={p.id} className="flex flex-wrap items-center gap-2 border-b border-line py-2 text-sm last:border-0">
                  <span className="font-medium">{money(p.amount)}</span><span className="text-muted">{p.provider}</span>
                  <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs">{label(PAYMENT_STATUSES, p.status, lang)}</span>
                  {Number(p.refunded_total) > 0 && <span className="text-xs text-warn">{t('order.refunded')}: {money(p.refunded_total)}</span>}
                  <span className="ms-auto text-xs text-muted">{fmtDate(p.created_at, lang)}</span>
                  {can('payments.refund') && ['succeeded', 'partially_refunded'].includes(p.status) && <Button variant="outline" onClick={() => setRefund({ id: p.id, amount: '', reason: '' })}>{t('order.refund')}</Button>}
                </div>))}
              {can('payments.manage') && !['cancelled', 'refunded'].includes(o.status) && (
                <form className="mt-3 flex flex-wrap items-end gap-2 border-t border-line pt-3" onSubmit={(e) => { e.preventDefault(); record.mutate() }}>
                  <label className="text-xs text-muted">{t('order.manualAmount')}<input required type="number" step="any" min="0" dir="ltr" className={`${input} mt-0.5 block w-32`} value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} /></label>
                  <label className="min-w-48 flex-1 text-xs text-muted">{t('order.manualNote')}<input required className={`${input} mt-0.5 block w-full`} value={pay.note} onChange={(e) => setPay({ ...pay, note: e.target.value })} /></label>
                  <Button type="submit" disabled={record.isPending}>{t('order.recordPayment')}</Button>
                </form>)}
            </Card>)}

          <Card title={t('order.history')}>
            <ol className="space-y-2 text-sm">{o.history.map((h: any) => <li key={h.id} className="flex flex-wrap gap-2"><span className="font-medium">{label(ORDER_STATUSES, h.to_status, lang)}</span>{h.note && <span className="text-muted">— {h.note}</span>}<span className="ms-auto text-xs text-muted">{h.user?.name ?? t('order.system')} · {fmtDate(h.created_at, lang)}</span></li>)}</ol>
          </Card>
        </div>

        <div className="space-y-4">
          <Card title={t('order.customer')}>
            <dl className="space-y-1 text-sm"><dd className="font-medium">{o.customer_name}</dd><dd dir="ltr" className="text-start text-muted">{o.customer_email}</dd><dd dir="ltr" className="text-start text-muted">{o.customer_phone}</dd><dd className="text-muted">{o.country_code}</dd></dl>
          </Card>
          <Card title={t('order.manage')}>
            <div className="flex flex-wrap gap-2">
              {next.length === 0 && <p className="text-sm text-muted">{t('order.finalState')}</p>}
              {next.map((s) => <Button key={s} variant={s === 'cancelled' ? 'outline' : 'primary'} className={s === 'cancelled' ? '!text-danger' : ''} disabled={status.isPending} onClick={() => (s === 'cancelled' ? setCancel(true) : status.mutate(s))}>{label(ORDER_STATUSES, s, lang)}</Button>)}
            </div>
            {can('orders.assign') && (
              <label className="mt-3 block text-xs text-muted">{t('order.assignee')}
                <select className={`${input} mt-0.5 block w-full`} value={o.assigned_to ?? ''} onChange={(e) => assign.mutate(e.target.value ? Number(e.target.value) : null)}>
                  <option value="">—</option>{staff.data?.map((u) => <option key={u.value} value={u.value}>{u.label_en}</option>)}
                </select>
              </label>)}
          </Card>
          <Card title={t('order.notes')}>
            <ul className="mb-3 space-y-2 text-sm">{o.notes.map((n: any) => <li key={n.id} className="rounded-lg bg-surface-2 p-2"><p>{n.body}</p><p className="mt-1 text-xs text-muted">{n.author?.name} · {fmtDate(n.created_at, lang)}</p></li>)}</ul>
            {can('orders.edit') && <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); if (note.trim()) addNote.mutate() }}>
              <input aria-label={t('order.notes')} className={`${input} flex-1`} value={note} onChange={(e) => setNote(e.target.value)} maxLength={3000} />
              <Button type="submit" disabled={addNote.isPending || !note.trim()}>{t('order.addNote')}</Button></form>}
          </Card>
        </div>
      </div>

      <ConfirmDialog open={cancel} danger busy={status.isPending} title={t('order.cancelTitle')} message={t('order.cancelMsg')} confirmLabel={t('order.cancelConfirm')} onCancel={() => setCancel(false)} onConfirm={() => status.mutate('cancelled')} />
      {refund && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onMouseDown={() => setRefund(null)}>
          <form role="dialog" aria-modal="true" aria-label={t('order.refund')} className="w-full max-w-sm space-y-3 rounded-xl border border-line bg-surface p-5 shadow-xl" onMouseDown={(e) => e.stopPropagation()} onSubmit={(e) => { e.preventDefault(); doRefund.mutate() }}>
            <h2 className="font-bold">{t('order.refund')}</h2>
            <label className="block text-sm">{t('order.refundAmount')}<input required type="number" step="any" min="0" dir="ltr" className={`${input} mt-1 w-full`} value={refund.amount} onChange={(e) => setRefund({ ...refund, amount: e.target.value })} /></label>
            <label className="block text-sm">{t('order.refundReason')}<input required className={`${input} mt-1 w-full`} value={refund.reason} onChange={(e) => setRefund({ ...refund, reason: e.target.value })} /></label>
            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setRefund(null)}>{t('crud.cancel')}</Button><Button type="submit" disabled={doRefund.isPending}>{t('order.refund')}</Button></div>
          </form>
        </div>)}
    </div>
  )
}
