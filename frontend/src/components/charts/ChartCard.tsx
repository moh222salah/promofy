import { Bar, BarChart, CartesianGrid, Cell, Funnel, FunnelChart, LabelList, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, EmptyState } from '@/components/ui'
import { fmtNumber } from '@/i18n'
import type { Chart, Point } from '@/lib/analytics'

const COLORS = ['#164E9B', '#E8A317', '#1B998B', '#C8553D', '#7B5EA7', '#4C9F70', '#D1495B', '#5A6E8C']

export function ChartCard({ chart, title, exportHref, height = 260 }: { chart: Chart | undefined; title: string; exportHref?: string; height?: number }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const series = (chart?.series ?? []).map((p) => ({ ...p, name: (ar && p.label_ar ? p.label_ar : p.label) as string })) as Array<Point & { name: string; leads?: number; orders?: number; won?: number; revenue?: number }>
  const hasData = chart?.type === 'table' ? series.length > 0 : series.some((p) => Number(p.value) > 0)
  const fmt = (v: unknown) => fmtNumber(Number(v))

  const body = () => {
    if (!chart || !hasData) return <EmptyState />
    switch (chart.type) {
      case 'line':
        return (
          <ResponsiveContainer width="100%" height={height}><LineChart data={series} margin={{ left: 0, right: 8, top: 8 }}>
            <CartesianGrid stroke="currentColor" strokeOpacity={0.1} vertical={false} />
            <XAxis dataKey="name" tick={{ fontSize: 11 }} minTickGap={24} /><YAxis tick={{ fontSize: 11 }} tickFormatter={fmt} width={48} allowDecimals={false} />
            <Tooltip formatter={(v) => fmt(v)} /><Line type="monotone" dataKey="value" stroke={COLORS[0]} strokeWidth={2} dot={false} />
          </LineChart></ResponsiveContainer>)
      case 'bar':
        return (
          <ResponsiveContainer width="100%" height={height}><BarChart data={series} layout="vertical" margin={{ left: 8, right: 16 }}>
            <CartesianGrid stroke="currentColor" strokeOpacity={0.1} horizontal={false} />
            <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={fmt} /><YAxis type="category" dataKey="name" width={110} tick={{ fontSize: 11 }} />
            <Tooltip formatter={(v) => fmt(v)} /><Bar dataKey="value" fill={COLORS[0]} radius={[0, 4, 4, 0]} />
          </BarChart></ResponsiveContainer>)
      case 'pie':
        return (
          <ResponsiveContainer width="100%" height={height}><PieChart>
            <Pie data={series} dataKey="value" nameKey="name" innerRadius={50} outerRadius={85} paddingAngle={2}>{series.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}</Pie>
            <Tooltip formatter={(v) => fmt(v)} /><Legend />
          </PieChart></ResponsiveContainer>)
      case 'funnel':
        return (
          <ResponsiveContainer width="100%" height={height}><FunnelChart>
            <Tooltip formatter={(v) => fmt(v)} />
            <Funnel dataKey="value" data={series.map((p, i) => ({ ...p, fill: COLORS[i % COLORS.length] }))} isAnimationActive={false}><LabelList position="right" dataKey="name" fontSize={11} /></Funnel>
          </FunnelChart></ResponsiveContainer>)
      case 'table':
        return (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="text-muted"><th className="p-1 text-start">UTM</th><th className="p-1">{t('chart.leads')}</th><th className="p-1">{t('chart.orders')}</th><th className="p-1">{t('chart.won')}</th><th className="p-1">{t('chart.revenue')}</th></tr></thead>
            <tbody>{series.map((r) => <tr key={r.label} className="border-t border-line"><td className="p-1">{r.label}</td><td className="p-1 text-center">{fmt(r.leads)}</td><td className="p-1 text-center">{fmt(r.orders)}</td><td className="p-1 text-center">{fmt(r.won)}</td><td className="p-1 text-center">{fmt(r.revenue)}</td></tr>)}</tbody></table></div>)
    }
  }

  return (
    <Card title={title} actions={exportHref && <a href={exportHref} className="inline-flex items-center gap-1 text-xs text-muted hover:text-ink" aria-label={`${t('chart.export')}: ${title}`}><Download size={14} aria-hidden />CSV</a>}>
      <div dir="ltr" className="text-muted">{body()}</div>
    </Card>
  )
}
