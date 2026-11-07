import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Activity, ArrowDownRight, ArrowUpRight, BadgeCheck, CheckCircle2, Clock, Coins, FileText, Percent, Rocket, ShoppingBag, Target, TrendingUp, UserPlus, Wallet, type LucideIcon } from 'lucide-react'
import { CountUp } from '@/components/fx/CountUp'
import { Sparkline } from '@/components/fx/Sparkline'
import { Button, Card, ErrorState, Spinner } from '@/components/ui'
import { ChartCard } from '@/components/charts/ChartCard'
import { exportUrl, fetchCharts, fetchOverview, fetchWidgets, RANGES, type Filters, type Kpi } from '@/lib/analytics'
import { fmtDate, fmtMoney, fmtNumber } from '@/i18n'
import { useAuth } from '@/auth/AuthContext'

const MONEY = new Set(['total_revenue', 'average_order_value'])
const KPI_ORDER = ['total_revenue', 'confirmed_payments', 'total_orders', 'pending_orders', 'new_leads', 'qualified_leads', 'conversion_rate', 'pending_quotations', 'active_projects', 'completed_projects', 'average_order_value', 'customer_growth']
const CHART_ORDER = ['revenue_over_time', 'orders_over_time', 'leads_trend', 'lead_funnel', 'revenue_by_country', 'revenue_by_product_category', 'revenue_by_service_category', 'top_products', 'most_requested_services', 'traffic_sources', 'campaign_performance', 'customer_acquisition', 'payment_status_distribution', 'order_status_distribution']

const KPI_STYLE: Record<string, { rgb?: string; icon: LucideIcon; tone: string; spark?: [string, string] }> = {
  total_revenue: { rgb: '245 181 68', icon: Wallet, tone: 'amber', spark: ['revenue_over_time', '245 181 68'] },
  confirmed_payments: { rgb: '34 197 94', icon: BadgeCheck, tone: 'green' },
  total_orders: { rgb: '31 196 230', icon: ShoppingBag, tone: 'teal', spark: ['orders_over_time', '61 214 245'] },
  pending_orders: { rgb: '251 146 60', icon: Clock, tone: 'amber' },
  new_leads: { rgb: '139 92 246', icon: UserPlus, tone: 'violet', spark: ['leads_trend', '167 139 250'] },
  qualified_leads: { rgb: '132 204 22', icon: Target, tone: 'green' },
  conversion_rate: { rgb: '56 189 248', icon: Percent, tone: 'teal' },
  pending_quotations: { rgb: '240 80 110', icon: FileText, tone: 'rose' },
  active_projects: { rgb: '99 102 241', icon: Rocket, tone: 'violet' },
  completed_projects: { rgb: '45 212 191', icon: CheckCircle2, tone: 'green' },
  average_order_value: { rgb: '234 179 8', icon: Coins, tone: 'amber' },
  customer_growth: { rgb: '217 70 239', icon: TrendingUp, tone: 'teal', spark: ['customer_acquisition', '61 214 245'] },
}

function KpiCard({ id, kpi, currency, charts, i }: { id: string; kpi: Kpi; currency: string; charts?: Record<string, any>; i: number }) {
  const { t } = useTranslation()
  const st = KPI_STYLE[id] ?? { icon: Activity, tone: '' }
  const Icon = st.icon
  const isMoney = MONEY.has(id)
  const ch = kpi.change_percent
  const fmt = (n: number) => isMoney ? fmtMoney(n, currency) : id === 'conversion_rate' ? `${fmtNumber(n)}%` : fmtNumber(n)
  const series: number[] = st.spark ? (charts?.[st.spark[0]]?.series ?? []).map((p: any) => Number(p.value)) : []
  return (
    <section className="kpi glass lit tilt3d page-in rounded-2xl p-4" style={{ animationDelay: `${i * 45}ms`, ['--kc' as any]: st.rgb ?? '79 140 255' }}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0"><div className="truncate text-xs font-medium text-muted">{t(`kpi.${id}`)}</div>
          <div className="display pop3d mt-1.5 truncate text-2xl font-bold tabular-nums"><CountUp value={Number(kpi.value)} format={fmt} /></div></div>
        <div className={`badge3d ${st.tone} pop3d shrink-0`}><Icon size={19} aria-hidden /></div>
      </div>
      {st.spark && <div className="mt-2"><Sparkline data={series} color={st.rgb ?? st.spark[1]} /></div>}
      <div className="mt-2 flex items-center gap-1 text-xs text-muted" title={t('kpi.vsPrev')}>
        {ch === null ? '—' : <>{ch >= 0 ? <ArrowUpRight size={14} className="text-success" aria-hidden /> : <ArrowDownRight size={14} className="text-danger" aria-hidden />}<span dir="ltr" className={ch >= 0 ? 'text-success' : 'text-danger'}>{ch > 0 ? '+' : ''}{fmtNumber(ch)}%</span></>}
      </div>
    </section>
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
        <h1 className="display title-gradient me-auto flex items-center gap-3 text-2xl font-extrabold sm:text-3xl">{t('nav.dashboard')}<span className="live-dot" aria-hidden /></h1>
        {canView && (<>
          <label className="text-xs text-muted">{t('dash.currency')}
            <select className="mt-0.5 block field p-2 text-sm text-ink" value={currency} onChange={(e) => setF({ ...f, currency: e.target.value })}>
              {Array.from(new Set([currency, ...(overview.data?.currencies ?? [])])).map((c) => <option key={c}>{c}</option>)}
            </select>
          </label>
          <label className="text-xs text-muted">&nbsp;
            <select aria-label="range" className="mt-0.5 block field p-2 text-sm text-ink" value={f.range} onChange={(e) => setF({ ...f, range: e.target.value as Filters['range'] })}>
              {RANGES.map((r) => <option key={r} value={r}>{t(`range.${r}`)}</option>)}
            </select>
          </label>
          {f.range === 'custom' && (
            <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (draft.from && draft.to) setF({ ...f, from: draft.from, to: draft.to }) }}>
              <label className="text-xs text-muted">{t('range.from')}<input type="date" required className="mt-0.5 block field p-2 text-sm text-ink" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} /></label>
              <label className="text-xs text-muted">{t('range.to')}<input type="date" required className="mt-0.5 block field p-2 text-sm text-ink" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} /></label>
              <Button type="submit" variant="outline">{t('range.apply')}</Button>
            </form>
          )}
        </>)}
      </div>

      {canView && (overview.isLoading ? <Spinner /> : overview.isError ? <ErrorState error={overview.error} onRetry={() => overview.refetch()} /> : overview.data && (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
          {KPI_ORDER.filter((k) => overview.data.kpis[k]).map((k, i) => <KpiCard key={k} id={k} kpi={overview.data.kpis[k]} currency={currency} charts={charts.data} i={i} />)}
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
