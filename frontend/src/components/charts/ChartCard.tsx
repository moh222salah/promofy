import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Funnel, FunnelChart, LabelList, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useId } from 'react'
import { Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, EmptyState } from '@/components/ui'
import { fmtNumber } from '@/i18n'
import type { Chart, Point } from '@/lib/analytics'

const COLORS = ['#4F8CFF', '#22D3EE', '#A78BFA', '#F59E0B', '#34D399', '#F472B6', '#60A5FA', '#FB923C']
const tip = { contentStyle: { background: 'rgb(var(--c-surface) / .92)', border: '1px solid rgb(var(--c-lit) / .5)', borderRadius: 12, backdropFilter: 'blur(10px)', boxShadow: '0 12px 30px -10px rgb(0 0 0 / .6)', color: 'rgb(var(--c-ink))', fontSize: 12 }, cursor: { stroke: 'rgb(var(--c-lit) / .6)', fill: 'rgb(var(--c-lit) / .08)' } }

export function ChartCard({ chart, title, exportHref, height = 260 }: { chart: Chart | undefined; title: string; exportHref?: string; height?: number }) {
  const { t, i18n } = useTranslation()
  const gid = useId().replace(/:/g, '')
  const ar = i18n.language === 'ar'
  const series = (chart?.series ?? []).map((p) => ({ ...p, name: (ar && p.label_ar ? p.label_ar : p.label) as string })) as Array<Point & { name: string; leads?: number; orders?: number; won?: number; revenue?: number }>
  const hasData = chart?.type === 'table' ? series.length > 0 : series.some((p) => Number(p.value) > 0)
  const fmt = (v: unknown) => fmtNumber(Number(v))

  const body = () => {
    if (!chart || !hasData) return <EmptyState />
    switch (chart.type) {
      case 'line':
        return (
          <ResponsiveContainer width="100%" height={height}><AreaChart data={series} margin={{ left: 0, right: 8, top: 8 }}>
            <defs><linearGradient id={`g-${gid}`} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={COLORS[0]} stopOpacity={0.55} /><stop offset="1" stopColor={COLORS[0]} stopOpacity={0} /></linearGradient></defs>
            <CartesianGrid stroke="currentColor" strokeOpacity={0.1} vertical={false} strokeDasharray="3 5" />
            <XAxis dataKey="name" tick={{ fontSize: 11 }} minTickGap={24} axisLine={false} tickLine={false} /><YAxis tick={{ fontSize: 11 }} tickFormatter={fmt} width={48} allowDecimals={false} axisLine={false} tickLine={false} />
            <Tooltip {...tip} formatter={(v) => fmt(v)} /><Area type="monotone" dataKey="value" stroke={COLORS[0]} strokeWidth={2.5} fill={`url(#g-${gid})`} dot={false} activeDot={{ r: 5, stroke: '#fff', strokeWidth: 2 }} style={{ filter: 'drop-shadow(0 0 6px rgb(79 140 255 / .7))' }} />
          </AreaChart></ResponsiveContainer>)
      case 'bar':
        return (
          <ResponsiveContainer width="100%" height={height}><BarChart data={series} layout="vertical" margin={{ left: 8, right: 16 }}>
            <CartesianGrid stroke="currentColor" strokeOpacity={0.1} horizontal={false} />
            <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={fmt} /><YAxis type="category" dataKey="name" width={110} tick={{ fontSize: 11 }} />
            <Tooltip {...tip} formatter={(v) => fmt(v)} /><Bar dataKey="value" radius={[0, 8, 8, 0]}>{series.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}</Bar>
          </BarChart></ResponsiveContainer>)
      case 'pie':
        return (
          <ResponsiveContainer width="100%" height={height}><PieChart>
            <Pie data={series} dataKey="value" nameKey="name" innerRadius={55} outerRadius={88} paddingAngle={3} stroke="none" cornerRadius={6}>{series.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}</Pie>
            <Tooltip {...tip} formatter={(v) => fmt(v)} /><Legend />
          </PieChart></ResponsiveContainer>)
      case 'funnel':
        return (
          <ResponsiveContainer width="100%" height={height}><FunnelChart>
            <Tooltip {...tip} formatter={(v) => fmt(v)} />
            <Funnel dataKey="value" data={series.map((p, i) => ({ ...p, fill: COLORS[i % COLORS.length] }))} isAnimationActive={false}><LabelList position="right" dataKey="name" fontSize={11} /></Funnel>
          </FunnelChart></ResponsiveContainer>)
      case 'table':
        return (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="text-muted"><th className="p-1 text-start">UTM</th><th className="p-1">{t('chart.leads')}</th><th className="p-1">{t('chart.orders')}</th><th className="p-1">{t('chart.won')}</th><th className="p-1">{t('chart.revenue')}</th></tr></thead>
            <tbody>{series.map((r) => <tr key={r.label} className="border-t border-line"><td className="p-1">{r.label}</td><td className="p-1 text-center">{fmt(r.leads)}</td><td className="p-1 text-center">{fmt(r.orders)}</td><td className="p-1 text-center">{fmt(r.won)}</td><td className="p-1 text-center">{fmt(r.revenue)}</td></tr>)}</tbody></table></div>)
    }
  }

  return (
    <Card className="transition-transform hover:-translate-y-0.5" title={title} actions={exportHref && <a href={exportHref} className="inline-flex items-center gap-1 text-xs text-muted hover:text-ink" aria-label={`${t('chart.export')}: ${title}`}><Download size={14} aria-hidden />CSV</a>}>
      <div dir="ltr" className="text-muted">{body()}</div>
    </Card>
  )
}
