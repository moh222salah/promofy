import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowRight, FileText, Link2, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { Button, Card, ErrorState, Spinner } from '@/components/ui'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useToast } from '@/components/Toast'
import ResourcePage from '@/components/crud/ResourcePage'
import type { ResourceConfig, Option, Bi } from '@/components/crud/types'
import { fmtDate, fmtMoney } from '@/i18n'

const L = (ar: string, en: string): Bi => ({ ar, en })
const O = (value: string, ar: string, en: string): Option => ({ value, label_ar: ar, label_en: en })
export const QUOTE_STATUSES: Option[] = [
  O('draft', 'مسودة', 'Draft'), O('sent', 'مُرسل', 'Sent'), O('viewed', 'تمت المشاهدة', 'Viewed'), O('approved', 'موافَق عليه', 'Approved'),
  O('rejected', 'مرفوض', 'Rejected'), O('expired', 'منتهي', 'Expired'), O('cancelled', 'ملغي', 'Cancelled'),
]
const lbl = (v: string, l: 'ar' | 'en') => QUOTE_STATUSES.find((o) => o.value === v)?.[`label_${l}`] ?? v
const input = 'field p-2 text-sm'
const msg = (e: unknown, fb: string) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fb)

export const quotations: ResourceConfig = {
  path: 'quotations', module: 'quotations', title: L('عروض الأسعار', 'Quotations'), readOnly: true, exportCsv: true, detailPath: '/admin/quotations', defaultSort: '-id',
  columns: [
    { key: 'number', label: L('الرقم', 'Number'), sortable: true },
    { key: 'title', label: L('العنوان', 'Title') },
    { key: 'customer_name', label: L('العميل', 'Customer') },
    { key: 'total', label: L('الإجمالي', 'Total'), sortable: true, render: (r) => `${r.total} ${r.currency_code}` },
    { key: 'status', label: L('الحالة', 'Status'), sortable: true, render: (r, l) => lbl(r.status, l) },
    { key: 'valid_until', label: L('صالح حتى', 'Valid until'), sortable: true, render: (r, l) => (r.valid_until ? fmtDate(r.valid_until, l) : '—') },
  ],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: QUOTE_STATUSES }, { name: 'assigned_to', label: L('المسؤول', 'Assignee'), optionsFrom: '/admin/users/options' }],
  fields: [],
}

export function QuotationsList() {
  const { can } = useAuth(); const { i18n } = useTranslation(); const ar = i18n.language === 'ar'
  return (
    <div className="space-y-3">
      {can('quotations.create') && <div className="flex justify-end"><Link to="/admin/quotations/new" className="btn btn-primary"><Plus size={16} aria-hidden />{ar ? 'عرض سعر جديد' : 'New quotation'}</Link></div>}
      <ResourcePage cfg={quotations} />
    </div>
  )
}

interface Item { name_ar: string; name_en: string; description: string; quantity: string; unit_price: string; discount_percent: string; service_id: number | null }
const blank = (): Item => ({ name_ar: '', name_en: '', description: '', quantity: '1', unit_price: '0', discount_percent: '0', service_id: null })

export function QuotationEditor() {
  const { id } = useParams(); const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient()
  const { i18n } = useTranslation(); const ar = i18n.language === 'ar'; const tt = (a: string, e: string) => (ar ? a : e)
  const existing = useQuery({ queryKey: ['quotation', id], queryFn: () => api.get<{ data: any }>(`/admin/quotations/${id}`).then((r) => r.data), enabled: !!id })
  const customers = useQuery({ queryKey: ['options', 'customers'], queryFn: () => api.get<{ data: Option[] }>('/admin/customers/options').then((r) => r.data) })
  const currencies = useQuery({ queryKey: ['options', 'currencies'], queryFn: () => api.get<{ data: Option[] }>('/admin/currencies/options').then((r) => r.data) })
  const services = useQuery({ queryKey: ['options', 'services-full'], queryFn: () => api.get<{ data: any[] }>('/admin/services?per_page=100').then((r) => r.data) })
  const [f, setF] = useState<any>(null)
  const [items, setItems] = useState<Item[]>([blank()])
  const [err, setErr] = useState<Record<string, string[]>>({})
  const form = f ?? (existing.data ? {
    customer_id: existing.data.customer_id ?? '', customer_name: existing.data.customer_name ?? '', customer_company: existing.data.customer_company ?? '', customer_email: existing.data.customer_email ?? '',
    title: existing.data.title, currency_code: existing.data.currency_code, valid_until: existing.data.valid_until?.slice(0, 10) ?? '', payment_terms: existing.data.payment_terms ?? '', scope: existing.data.scope ?? '', notes: existing.data.notes ?? '', tax_rate: String(existing.data.tax_rate ?? 0),
  } : { customer_id: '', customer_name: '', customer_company: '', customer_email: '', title: '', currency_code: 'EGP', valid_until: '', payment_terms: '', scope: '', notes: '', tax_rate: '' })
  const [loadedItems, setLoadedItems] = useState(false)
  if (existing.data && !loadedItems) { setLoadedItems(true); setItems(existing.data.items.map((i: any) => ({ name_ar: i.name_ar, name_en: i.name_en, description: i.description ?? '', quantity: String(i.quantity), unit_price: String(i.unit_price), discount_percent: String(i.discount_percent ?? 0), service_id: i.service_id }))) }
  const set = (k: string, v: string) => setF({ ...form, [k]: v })
  const setItem = (i: number, k: keyof Item, v: any) => setItems(items.map((x, j) => (j === i ? { ...x, [k]: v } : x)))
  const sum = items.reduce((a, i) => a + Number(i.quantity || 0) * Number(i.unit_price || 0) * (1 - Number(i.discount_percent || 0) / 100), 0)
  const tax = sum * Number(form.tax_rate || 0) / 100

  const save = useMutation({
    mutationFn: () => {
      const body: any = { ...form, customer_id: form.customer_id ? Number(form.customer_id) : null, items: items.map((i) => ({ ...i, quantity: Number(i.quantity), unit_price: Number(i.unit_price), discount_percent: Number(i.discount_percent || 0) })) }
      for (const k of ['valid_until', 'customer_email', 'customer_company', 'payment_terms', 'scope', 'notes']) if (body[k] === '') body[k] = null
      if (body.tax_rate === '') delete body.tax_rate; else body.tax_rate = Number(body.tax_rate)
      if (body.customer_id) { delete body.customer_name }
      return id ? api.put<{ data: any }>(`/admin/quotations/${id}`, body) : api.post<{ data: any }>('/admin/quotations', body)
    },
    onSuccess: (r) => { toast(tt('تم الحفظ', 'Saved')); qc.invalidateQueries({ queryKey: ['quotation'] }); qc.invalidateQueries({ queryKey: ['resource', 'quotations'] }); nav(`/admin/quotations/${r.data.id}`) },
    onError: (e) => { setErr(e instanceof ApiError ? e.errors : {}); toast(msg(e, tt('حدث خطأ', 'Error')), 'error') },
  })
  if (id && existing.isLoading) return <Spinner />
  if (id && existing.isError) return <ErrorState error={existing.error} />
  if (existing.data && existing.data.status !== 'draft') return <ErrorState error={new Error(tt('يمكن تعديل المسودات فقط', 'Only drafts can be edited'))} />
  const fe = (k: string) => err[k]?.[0] && <p className="mt-0.5 text-xs text-danger">{err[k][0]}</p>
  const lab = 'block text-xs text-muted'
  return (
    <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); setErr({}); save.mutate() }}>
      <div className="flex items-center gap-3"><Link to="/admin/quotations" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight size={14} className="ltr:rotate-180" aria-hidden />{tt('عروض الأسعار', 'Quotations')}</Link>
        <h1 className="display text-xl font-bold">{id ? tt('تعديل عرض سعر', 'Edit quotation') : tt('عرض سعر جديد', 'New quotation')}</h1></div>
      <Card title={tt('العميل والبيانات', 'Customer & details')}>
        <div className="grid gap-3 md:grid-cols-2">
          <label className={lab}>{tt('عميل مسجّل', 'Existing customer')}<select id="q-customer" className={`${input} mt-1 w-full`} value={form.customer_id} onChange={(e) => set('customer_id', e.target.value)}><option value="">—</option>{customers.data?.map((c) => <option key={c.value} value={c.value}>{c.label_en}</option>)}</select></label>
          {!form.customer_id && <label className={lab}>{tt('اسم العميل', 'Customer name')}<input id="q-name" className={`${input} mt-1 w-full`} value={form.customer_name} onChange={(e) => set('customer_name', e.target.value)} />{fe('customer_name')}</label>}
          {!form.customer_id && <label className={lab}>{tt('الشركة', 'Company')}<input className={`${input} mt-1 w-full`} value={form.customer_company} onChange={(e) => set('customer_company', e.target.value)} /></label>}
          {!form.customer_id && <label className={lab}>{tt('البريد الإلكتروني', 'Email')}<input dir="ltr" className={`${input} mt-1 w-full`} value={form.customer_email} onChange={(e) => set('customer_email', e.target.value)} />{fe('customer_email')}</label>}
          <label className={lab}>{tt('عنوان العرض', 'Title')}<input id="q-title" required className={`${input} mt-1 w-full`} value={form.title} onChange={(e) => set('title', e.target.value)} />{fe('title')}</label>
          <label className={lab}>{tt('العملة', 'Currency')}<select id="q-currency" className={`${input} mt-1 w-full`} value={form.currency_code} onChange={(e) => set('currency_code', e.target.value)}>{currencies.data?.map((c) => <option key={c.value} value={c.value}>{c.value}</option>)}</select>{fe('currency_code')}</label>
          <label className={lab}>{tt('صالح حتى', 'Valid until')}<input type="date" className={`${input} mt-1 w-full`} value={form.valid_until} onChange={(e) => set('valid_until', e.target.value)} />{fe('valid_until')}</label>
          <label className={lab}>{tt('نسبة الضريبة %', 'Tax rate %')}<input type="number" step="any" min="0" max="100" dir="ltr" placeholder={tt('الافتراضي من الإعدادات', 'Default from settings')} className={`${input} mt-1 w-full`} value={form.tax_rate} onChange={(e) => set('tax_rate', e.target.value)} />{fe('tax_rate')}</label>
        </div>
      </Card>
      <Card title={tt('البنود', 'Line items')}>
        <div className="space-y-3">
          {items.map((it, i) => (
            <div key={i} className="grid gap-2 rounded-xl border border-line p-3 md:grid-cols-12">
              <select aria-label="service" className={`${input} md:col-span-12`} value={it.service_id ?? ''} onChange={(e) => { const s = services.data?.find((x) => String(x.id) === e.target.value); setItems(items.map((x, j) => (j === i ? { ...x, service_id: s ? s.id : null, name_ar: s ? s.name_ar : x.name_ar, name_en: s ? s.name_en : x.name_en } : x))) }}>
                <option value="">{tt('— بند مخصّص / اختر خدمة —', '— Custom item / pick a service —')}</option>{services.data?.map((s) => <option key={s.id} value={s.id}>{ar ? s.name_ar : s.name_en}</option>)}</select>
              <input required placeholder={tt('الاسم بالعربية', 'Name (AR)')} className={`${input} md:col-span-5`} value={it.name_ar} onChange={(e) => setItem(i, 'name_ar', e.target.value)} />
              <input required placeholder={tt('الاسم بالإنجليزية', 'Name (EN)')} dir="ltr" className={`${input} md:col-span-5`} value={it.name_en} onChange={(e) => setItem(i, 'name_en', e.target.value)} />
              <button type="button" className="icon-btn danger md:col-span-2 md:justify-self-end" aria-label={tt('حذف البند', 'Remove item')} disabled={items.length === 1} onClick={() => setItems(items.filter((_, j) => j !== i))}><Trash2 size={16} /></button>
              <label className={`${lab} md:col-span-3`}>{tt('الكمية', 'Qty')}<input required type="number" step="any" min="0" dir="ltr" className={`${input} mt-1 w-full`} value={it.quantity} onChange={(e) => setItem(i, 'quantity', e.target.value)} /></label>
              <label className={`${lab} md:col-span-3`}>{tt('سعر الوحدة', 'Unit price')}<input required type="number" step="any" min="0" dir="ltr" className={`${input} mt-1 w-full`} value={it.unit_price} onChange={(e) => setItem(i, 'unit_price', e.target.value)} /></label>
              <label className={`${lab} md:col-span-3`}>{tt('خصم %', 'Discount %')}<input type="number" step="any" min="0" max="100" dir="ltr" className={`${input} mt-1 w-full`} value={it.discount_percent} onChange={(e) => setItem(i, 'discount_percent', e.target.value)} /></label>
              <input placeholder={tt('وصف (اختياري)', 'Description (optional)')} className={`${input} md:col-span-12`} value={it.description} onChange={(e) => setItem(i, 'description', e.target.value)} />
            </div>))}
          {err.items && <p className="text-xs text-danger">{err.items[0]}</p>}
          {Object.keys(err).filter((k) => k.startsWith('items.')).slice(0, 1).map((k) => <p key={k} className="text-xs text-danger">{err[k][0]}</p>)}
          <Button type="button" variant="outline" onClick={() => setItems([...items, blank()])}><Plus size={15} aria-hidden />{tt('إضافة بند', 'Add item')}</Button>
        </div>
        <p className="mt-3 text-end text-xs text-muted">{tt('المجموع الفرعي التقريبي', 'Estimated subtotal')}: <span className="tabular-nums">{fmtMoney(sum, form.currency_code)}</span>{tax > 0 && <> · {tt('الضريبة', 'Tax')}: <span className="tabular-nums">{fmtMoney(tax, form.currency_code)}</span></>} — {tt('الإجمالي النهائي يحسبه الخادم', 'final totals are computed by the server')}</p>
      </Card>
      <Card title={tt('الشروط والنطاق', 'Terms & scope')}>
        <div className="grid gap-3 md:grid-cols-2">
          <label className={lab}>{tt('شروط الدفع', 'Payment terms')}<textarea rows={3} className={`${input} mt-1 w-full`} value={form.payment_terms} onChange={(e) => set('payment_terms', e.target.value)} /></label>
          <label className={lab}>{tt('ملاحظات', 'Notes')}<textarea rows={3} className={`${input} mt-1 w-full`} value={form.notes} onChange={(e) => set('notes', e.target.value)} /></label>
          <label className={`${lab} md:col-span-2`}>{tt('نطاق العمل', 'Scope of work')}<textarea rows={5} className={`${input} mt-1 w-full`} value={form.scope} onChange={(e) => set('scope', e.target.value)} /></label>
        </div>
      </Card>
      <div className="flex justify-end gap-2"><Link to="/admin/quotations" className="btn btn-outline">{tt('إلغاء', 'Cancel')}</Link><Button type="submit" disabled={save.isPending}>{tt('حفظ', 'Save')}</Button></div>
    </form>
  )
}

export function QuotationDetail() {
  const { id } = useParams(); const { can } = useAuth(); const toast = useToast(); const qc = useQueryClient(); const nav = useNavigate()
  const { i18n } = useTranslation(); const lang = i18n.language === 'ar' ? 'ar' : 'en'; const tt = (a: string, e: string) => (lang === 'ar' ? a : e)
  const key = ['quotation', id]
  const q = useQuery({ queryKey: key, queryFn: () => api.get<{ data: any }>(`/admin/quotations/${id}`).then((r) => r.data) })
  const [dlg, setDlg] = useState<null | 'reject' | 'cancel' | 'delete'>(null); const [note, setNote] = useState(''); const [link, setLink] = useState('')
  const done = (m?: string) => { toast(m ?? tt('تم', 'Done')); qc.invalidateQueries({ queryKey: key }); qc.invalidateQueries({ queryKey: ['resource', 'quotations'] }); setDlg(null); setNote('') }
  const fail = (e: unknown) => { setDlg(null); toast(msg(e, tt('حدث خطأ', 'Error')), 'error') }
  const act = useMutation({ mutationFn: ({ a, body }: { a: string; body?: any }) => api.post(`/admin/quotations/${id}/${a}`, body), onSuccess: () => done(), onError: fail })
  const toOrder = useMutation({ mutationFn: () => api.post<{ data: any }>(`/admin/quotations/${id}/convert-to-order`), onSuccess: (r) => { toast(tt('تم إنشاء الطلب', 'Order created')); nav(`/admin/orders/${r.data.order_id}`) }, onError: fail })
  const toProject = useMutation({ mutationFn: () => api.post<{ data: any }>(`/admin/projects/from-quotation/${id}`), onSuccess: () => done(tt('تم إنشاء المشروع', 'Project created')), onError: fail })
  const getLink = useMutation({ mutationFn: () => api.get<{ data: { url: string } }>(`/admin/quotations/${id}/link`), onSuccess: (r) => setLink(r.data.url), onError: fail })
  const del = useMutation({ mutationFn: () => api.delete(`/admin/quotations/${id}`), onSuccess: () => { qc.invalidateQueries({ queryKey: ['resource', 'quotations'] }); nav('/admin/quotations') }, onError: fail })
  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  const o = q.data; const money = (v: string | number) => fmtMoney(Number(v), o.currency_code)
  const draft = o.status === 'draft'; const live = ['sent', 'viewed'].includes(o.status)
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/quotations" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight size={14} className="ltr:rotate-180" aria-hidden />{tt('عروض الأسعار', 'Quotations')}</Link>
        <h1 className="display text-xl font-bold" dir="ltr">{o.number}</h1>
        <span id="q-status" className="rounded-full bg-lit/15 px-3 py-1 text-xs font-semibold">{lbl(o.status, lang)}</span>
        <a className="btn btn-outline ms-auto" href={`/api/v1/admin/quotations/${o.id}/document`} target="_blank" rel="noreferrer"><FileText size={16} aria-hidden />{tt('طباعة / PDF', 'Print / PDF')}</a>
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title={o.title}>
            <div className="overflow-x-auto"><table className="w-full text-sm">
              <thead><tr className="text-muted"><th className="p-2 text-start">{tt('البند', 'Item')}</th><th className="p-2">{tt('الكمية', 'Qty')}</th><th className="p-2 text-end">{tt('السعر', 'Unit')}</th><th className="p-2 text-end">{tt('الخصم', 'Disc.')}</th><th className="p-2 text-end">{tt('الإجمالي', 'Line')}</th></tr></thead>
              <tbody>{o.items.map((i: any) => <tr key={i.id} className="border-t border-line"><td className="p-2">{lang === 'ar' ? i.name_ar : i.name_en}{i.description && <div className="text-xs text-muted">{i.description}</div>}</td><td className="p-2 text-center">{i.quantity}</td><td className="p-2 text-end tabular-nums">{money(i.unit_price)}</td><td className="p-2 text-end">{Number(i.discount_percent) > 0 ? `${i.discount_percent}%` : '—'}</td><td className="p-2 text-end tabular-nums">{money(i.line_total)}</td></tr>)}</tbody>
            </table></div>
            <dl className="mt-3 ms-auto max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-muted">{tt('المجموع الفرعي', 'Subtotal')}</dt><dd className="tabular-nums">{money(o.subtotal)}</dd></div>
              {Number(o.discount_total) > 0 && <div className="flex justify-between"><dt className="text-muted">{tt('الخصم', 'Discount')}</dt><dd className="tabular-nums">−{money(o.discount_total)}</dd></div>}
              <div className="flex justify-between"><dt className="text-muted">{tt('الضريبة', 'Tax')} ({o.tax_rate}%)</dt><dd className="tabular-nums">{money(o.tax_total)}</dd></div>
              <div className="flex justify-between border-t border-line pt-1 font-bold"><dt>{tt('الإجمالي', 'Total')}</dt><dd className="tabular-nums">{money(o.total)}</dd></div>
            </dl>
          </Card>
          {(o.scope || o.payment_terms || o.notes) && <Card title={tt('الشروط والنطاق', 'Terms & scope')}><div className="space-y-3 whitespace-pre-wrap text-sm">{o.scope && <p>{o.scope}</p>}{o.payment_terms && <p><b>{tt('شروط الدفع', 'Payment terms')}:</b> {o.payment_terms}</p>}{o.notes && <p className="text-muted">{o.notes}</p>}</div></Card>}
          {o.decision_note && <Card title={tt('ملاحظة القرار', 'Decision note')}><p className="text-sm">{o.decision_note}</p></Card>}
        </div>
        <div className="space-y-4">
          <Card title={tt('العميل', 'Customer')}><dl className="space-y-1 text-sm"><dd className="font-medium">{o.customer_name}</dd>{o.customer_company && <dd className="text-muted">{o.customer_company}</dd>}<dd dir="ltr" className="text-start text-muted">{o.customer_email}</dd><dd className="text-xs text-muted">{tt('صالح حتى', 'Valid until')}: {o.valid_until ? fmtDate(o.valid_until, lang) : '—'}</dd></dl></Card>
          <Card title={tt('الإجراءات', 'Actions')}>
            <div className="flex flex-wrap gap-2">
              {draft && can('quotations.edit') && <Link id="q-edit" className="btn btn-outline" to={`/admin/quotations/${o.id}/edit`}>{tt('تعديل', 'Edit')}</Link>}
              {draft && can('quotations.send') && <Button id="q-send" disabled={act.isPending} onClick={() => act.mutate({ a: 'send' })}>{tt('إرسال للعميل', 'Send to customer')}</Button>}
              {live && can('quotations.approve') && <Button id="q-approve" disabled={act.isPending} onClick={() => act.mutate({ a: 'approve' })}>{tt('اعتماد', 'Approve')}</Button>}
              {live && can('quotations.approve') && <Button variant="outline" className="!text-danger" onClick={() => setDlg('reject')}>{tt('رفض', 'Reject')}</Button>}
              {(draft || live) && can('quotations.edit') && <Button variant="outline" onClick={() => setDlg('cancel')}>{tt('إلغاء العرض', 'Cancel quotation')}</Button>}
              {o.status === 'approved' && can('quotations.approve') && can('orders.edit') && <Button id="q-order" disabled={toOrder.isPending} onClick={() => toOrder.mutate()}>{tt('تحويل إلى طلب', 'Convert to order')}</Button>}
              {o.status === 'approved' && can('projects.create') && <Button variant="outline" disabled={toProject.isPending} onClick={() => toProject.mutate()}>{tt('إنشاء مشروع', 'Create project')}</Button>}
              {!draft && can('quotations.send') && <Button variant="outline" onClick={() => getLink.mutate()}><Link2 size={15} aria-hidden />{tt('رابط العميل', 'Customer link')}</Button>}
              {draft && can('quotations.delete') && <Button variant="outline" className="!text-danger" onClick={() => setDlg('delete')}>{tt('حذف', 'Delete')}</Button>}
              {can('quotations.create') && <Button variant="outline" onClick={() => api.post<{ data: any }>(`/admin/quotations/${id}/duplicate`).then((r) => { toast(tt('تم النسخ', 'Duplicated')); nav(`/admin/quotations/${r.data.id}`) }).catch(fail)}>{tt('نسخ', 'Duplicate')}</Button>}
            </div>
            {link && <div className="mt-3"><input readOnly dir="ltr" className={`${input} w-full`} value={link} onFocus={(e) => e.currentTarget.select()} aria-label="link" /><p className="mt-1 text-xs text-muted">{tt('شارك الرابط مع العميل للمراجعة والموافقة', 'Share this link so the customer can review and decide')}</p></div>}
          </Card>
        </div>
      </div>
      <ConfirmDialog open={dlg === 'cancel'} danger busy={act.isPending} title={tt('إلغاء عرض السعر؟', 'Cancel this quotation?')} message={tt('لا يمكن التراجع عن الإلغاء.', 'This cannot be undone.')} confirmLabel={tt('إلغاء العرض', 'Cancel quotation')} onCancel={() => setDlg(null)} onConfirm={() => act.mutate({ a: 'cancel' })} />
      <ConfirmDialog open={dlg === 'delete'} danger busy={del.isPending} title={tt('حذف المسودة؟', 'Delete this draft?')} message={tt('سيتم حذف المسودة نهائيًا.', 'The draft will be removed.')} confirmLabel={tt('حذف', 'Delete')} onCancel={() => setDlg(null)} onConfirm={() => del.mutate()} />
      {dlg === 'reject' && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4 backdrop-blur-sm" onMouseDown={() => setDlg(null)}>
          <form role="dialog" aria-modal="true" className="glass w-full max-w-sm space-y-3 rounded-2xl p-5" onMouseDown={(e) => e.stopPropagation()} onSubmit={(e) => { e.preventDefault(); act.mutate({ a: 'reject', body: { note } }) }}>
            <h2 className="font-bold">{tt('سبب الرفض', 'Reason for rejection')}</h2>
            <input required autoFocus className={`${input} w-full`} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setDlg(null)}>{tt('إلغاء', 'Cancel')}</Button><Button type="submit" disabled={act.isPending}>{tt('رفض', 'Reject')}</Button></div>
          </form>
        </div>)}
    </div>
  )
}
