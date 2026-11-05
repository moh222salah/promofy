import { api, qs } from '@/lib/api'

export const RANGES = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month', 'this_year', 'custom'] as const
export type RangeKey = (typeof RANGES)[number]
export interface Filters { range: RangeKey; from?: string; to?: string; currency?: string }

export interface Kpi { value: number; previous: number; change_percent: number | null }
export interface Overview { currency: string; currencies: string[]; kpis: Record<string, Kpi>; range: { from: string; to: string; preset: string } }
export interface Point { label: string; label_ar?: string; value: number; [k: string]: unknown }
export interface Chart { chart: string; type: 'line' | 'bar' | 'pie' | 'funnel' | 'table'; series: Point[]; meta: Record<string, unknown> }

const params = (f: Filters) => ({ range: f.range, from: f.range === 'custom' ? f.from : undefined, to: f.range === 'custom' ? f.to : undefined, currency: f.currency })

export const fetchOverview = (f: Filters) => api.get<{ data: Overview }>('/admin/analytics/overview', params(f)).then((r) => r.data)
export const fetchCharts = (f: Filters) => api.get<{ data: Record<string, Chart> }>('/admin/analytics/charts', params(f)).then((r) => r.data)
export const fetchWidgets = () => api.get<{ data: Record<string, any> }>('/admin/dashboard/widgets', { limit: 5 }).then((r) => r.data)
export const exportUrl = (name: string, f: Filters) => `/api/v1/admin/analytics/charts/${name}/export${qs(params(f))}`
