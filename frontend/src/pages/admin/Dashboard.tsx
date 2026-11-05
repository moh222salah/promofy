import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowDownRight, ArrowUpRight } from 'lucide-react'
import { Button, Card, ErrorState, Spinner } from '@/components/ui'
import { ChartCard } from '@/components/charts/ChartCard'
import { exportUrl, fetchCharts, fetchOverview, fetchWidgets, RANGES, type Filters, type Kpi } from '@/lib/analytics'
import { fmtDate, fmtMoney, fmtNumber } from '@/i18n'
import { useAuth } from '@/auth/AuthContext'

const MONEY = new Set(['total_revenue', 'average_order_value'])
const KPI_ORDER = ['total_revenue', 'confirmed_payments', 'total_orders', 'pending_orders', 'new_leads', 'qualified_leads', 'conversion_rate', 'pending_quotations', 'active_projects', 'completed_projects', 'average_order_value', 'customer_growth']
const CHART_ORDER = ['revenue_over_time', 'orders_over_time', 'leads_trend', 'lead_funnel', 'revenue_by_country', 'revenue_by_product_category', 'revenue_by_service_category', 'top_products', 'most_requested_services', 'traffic_sources', 'campaign_performance', 'customer_acquisition', 'payment_status_distribution', 'order_status_distribution']

function KpiCard({ id, kpi, currency }: { id: string; kpi: Kpi; currency: string }) {
  const { t } = useTranslation()
  const val = MONEY.has(id) ? fmtMoney(kpi.value, currency) : id === 'conversion_rate' ? `${fmtNumber(kpi.value)}%` : fmtNumber(kpi.value)
  const ch = kpi.change_percent
  return (
    <Card>
      <div className="text-xs text-muted">{t(`kpi.${id}`)}</div>
      <div className="mt-1 text-2xl font-bold tabular-nums">{val}</div>
      <div className="mt-1 flex items-center gap-1 text-xs text-muted" title={t('kpi.vsPrev')}>
        {ch === null ? '—' : <>{ch >= 0 ? <ArrowUpRight size={14} className="text-success" aria-hidden /> : <ArrowDownRight size={14} className="text-danger" aria-hidden />}<span dir="ltr">{ch > 0 ? '+' : ''}{fmtNumber(ch)}%</span></>}
      </div>
    </Card>
  )
}

function WidgetList({ title, rows, render }: { title: string; rows?: any[]; render: (r: any) => React.ReactNode }) {
  const { t } = useTranslation()
  if (!rows) return null
  return <Card title={title}>{rows.length === 0 ? <p className="text-sm text-muted">{t('dash.none')}</p> : <ul className="divide-y divide-line text-sm">{rows.map((r, i) => <li key={r.id ?? i} className="flex items-center justify-between gap-2 py-2">{render(r)}</li>)}</ul>}</Card>
}

export default function Dashboard() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [f, setF] = useState<Filters>({ range: 'last_30_days' })
  const [draft, setDraft] = useState({ from: '', to: '' })
  const canView = can('analytics.view')

  const overview = useQuery({ queryKey: ['overview', f], queryFn: () => fetchOverview(f), enabled: canView })
  const charts = useQuery({ queryKey: ['charts', f], queryFn: () => fetchCharts(f), enabled: canView })
  const widgets = useQuery({ queryKey: ['widgets'], queryFn: fetchWidgets })
  const currency = overview.data?.currency ?? f.currency ?? 'EGP'
  const w = widgets.data

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-2">
        <h1 className="me-auto text-xl font-bold">{t('nav.dashboard')}</h1>
        {canView && (<>
          <label className="text-xs text-muted">{t('dash.currency')}
            <select className="mt-0.5 block rounded-lg border border-line bg-surface p-2 text-sm text-ink" value={currency} onChange={(e) => setF({ ...f, currency: e.target.value })}>
              {Array.from(new Set([currency, ...(overview.data?.currencies ?? [])])).map((c) => <option key={c}>{c}</option>)}
            </select>
          </label>
          <label className="text-xs text-muted">&nbsp;
            <select aria-label="range" className="mt-0.5 block rounded-lg border border-line bg-surface p-2 text-sm text-ink" value={f.range} onChange={(e) => setF({ ...f, range: e.target.value as Filters['range'] })}>
              {RANGES.map((r) => <option key={r} value={r}>{t(`range.${r}`)}</option>)}
            </select>
          </label>
          {f.range === 'custom' && (
            <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (draft.from && draft.to) setF({ ...f, from: draft.from, to: draft.to }) }}>
              <label className="text-xs text-muted">{t('range.from')}<input type="date" required className="mt-0.5 block rounded-lg border border-line bg-surface p-2 text-sm text-ink" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} /></label>
              <label className="text-xs text-muted">{t('range.to')}<input type="date" required className="mt-0.5 block rounded-lg border border-line bg-surface p-2 text-sm text-ink" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} /></label>
              <Button type="submit" variant="outline">{t('range.apply')}</Button>
            </form>
          )}
        </>)}
      </div>

      {canView && (overview.isLoading ? <Spinner /> : overview.isError ? <ErrorState error={overview.error} onRetry={() => overview.refetch()} /> : overview.data && (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
          {KPI_ORDER.filter((k) => overview.data.kpis[k]).map((k) => <KpiCard key={k} id={k} kpi={overview.data.kpis[k]} currency={currency} />)}
        </div>
      ))}

      {canView && (charts.isLoading ? <Spinner /> : charts.isError ? <ErrorState error={charts.error} onRetry={() => charts.refetch()} /> : (
        <div className="grid gap-3 lg:grid-cols-2">
          {CHART_ORDER.map((c) => <ChartCard key={c} chart={charts.data?.[c]} title={t(`chart.${c}`)} exportHref={can('analytics.export') ? exportUrl(c, { ...f, currency }) : undefined} />)}
        </div>
      ))}

      {widgets.isError && <ErrorState error={widgets.error} onRetry={() => widgets.refetch()} />}
      {w && (
        <div className="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
          <WidgetList title={t('dash.recent_orders')} rows={w.recent_orders} render={(o) => <><span>{o.number} · {o.customer_name}</span><span className="text-muted tabular-nums">{fmtMoney(Number(o.total), o.currency_code)}</span></>} />
          <WidgetList title={t('dash.recent_leads')} rows={w.recent_leads} render={(l) => <><span>{l.name}{l.company ? ` · ${l.company}` : ''}</span><span className="text-muted">{l.status}</span></>} />
          <WidgetList title={t('dash.pending_quotations')} rows={w.pending_quotations} render={(q) => <><span>{q.number} · {q.customer_name}</span><span className="text-muted tabular-nums">{fmtMoney(Number(q.total), q.currency_code)}</span></>} />
          <WidgetList title={t('dash.latest_messages')} rows={w.latest_messages} render={(m) => <><span>{m.name}{m.subject ? ` — ${m.subject}` : ''}</span><span className="text-muted">{fmtDate(m.created_at)}</span></>} />
          <WidgetList title={t('dash.upcoming_tasks')} rows={w.upcoming_tasks} render={(x) => <><span>{x.title}</span><span className="text-muted">{fmtDate(x.due_at)}</span></>} />
          <WidgetList title={t('dash.low_stock')} rows={w.low_stock} render={(p) => <><span>{p.sku}</span><span className="text-warn tabular-nums">{fmtNumber(p.stock_quantity)}</span></>} />
          {w.payment_alerts && (
            <Card title={t('dash.payment_alerts')}><dl className="space-y-1 text-sm"><div className="flex justify-between"><dt>{t('dash.failed7')}</dt><dd className="tabular-nums">{fmtNumber(w.payment_alerts.failed_last_7_days)}</dd></div><div className="flex justify-between"><dt>{t('dash.awaiting')}</dt><dd className="tabular-nums">{fmtNumber(w.payment_alerts.awaiting_payment_orders)}</dd></div></dl></Card>
          )}
        </div>
      )}
    </div>
  )
}
