import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import clsx from 'clsx'
import { ArrowRight, ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import ResourcePage from '@/components/crud/ResourcePage'
import { Button, Card, ErrorState, Spinner } from '@/components/ui'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useToast } from '@/components/Toast'
import type { ResourceConfig, Option, Bi } from '@/components/crud/types'
import { fmtDate } from '@/i18n'

const L = (ar: string, en: string): Bi => ({ ar, en })
const O = (value: string, ar: string, en: string): Option => ({ value, label_ar: ar, label_en: en })
export const PROJECT_STATUSES: Option[] = [O('planning', 'تخطيط', 'Planning'), O('in_progress', 'قيد التنفيذ', 'In progress'), O('under_review', 'قيد المراجعة', 'Under review'), O('on_hold', 'معلّق', 'On hold'), O('completed', 'مكتمل', 'Completed'), O('cancelled', 'ملغي', 'Cancelled')]
const PRIORITIES: Option[] = [O('low', 'منخفضة', 'Low'), O('normal', 'عادية', 'Normal'), O('high', 'عالية', 'High'), O('urgent', 'عاجلة', 'Urgent')]
const lb = (o: Option[], v: string, l: 'ar' | 'en') => o.find((x) => x.value === v)?.[`label_${l}`] ?? v
const input = 'field p-2 text-sm'
const msg = (e: unknown, fb: string) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fb)
const useLang = () => { const { i18n } = useTranslation(); const lang: 'ar' | 'en' = i18n.language === 'ar' ? 'ar' : 'en'; return { lang, tt: (a: string, e: string) => (lang === 'ar' ? a : e) } }

export const projects: ResourceConfig = {
  path: 'projects', module: 'projects', title: L('المشاريع', 'Projects'), readOnly: true, exportCsv: true, detailPath: '/admin/projects', defaultSort: '-id',
  columns: [
    { key: 'name', label: L('المشروع', 'Project'), sortable: true },
    { key: 'customer', label: L('العميل', 'Customer'), render: (r) => r.customer?.name ?? '—' },
    { key: 'status', label: L('الحالة', 'Status'), sortable: true, render: (r, l) => lb(PROJECT_STATUSES, r.status, l) },
    { key: 'priority', label: L('الأولوية', 'Priority'), sortable: true, render: (r, l) => lb(PRIORITIES, r.priority, l) },
    { key: 'progress', label: L('التقدّم', 'Progress'), sortable: true, render: (r) => `${r.progress}%` },
    { key: 'due_date', label: L('الموعد', 'Due'), sortable: true, render: (r, l) => (r.due_date ? fmtDate(r.due_date, l) : '—') },
  ],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: PROJECT_STATUSES }, { name: 'priority', label: L('الأولوية', 'Priority'), options: PRIORITIES }, { name: 'manager_id', label: L('المدير', 'Manager'), optionsFrom: '/admin/users/options' }],
  fields: [],
}

function Board() {
  const { lang, tt } = useLang(); const { can } = useAuth(); const toast = useToast(); const qc = useQueryClient()
  const canMove = can('projects.edit') || can('projects.edit_assigned')
  const [dragging, setDragging] = useState<number | null>(null); const [over, setOver] = useState<string | null>(null)
  const q = useQuery({ queryKey: ['projects-board'], queryFn: () => api.get<{ data: any[] }>('/admin/projects/board').then((r) => r.data) })
  const move = useMutation({ mutationFn: ({ id, status }: { id: number; status: string }) => api.post(`/admin/projects/${id}/status`, { status }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects-board'] }); qc.invalidateQueries({ queryKey: ['resource', 'projects'] }) }, onError: (e) => toast(msg(e, tt('حدث خطأ', 'Error')), 'error') })
  const request = (id: number, status: string, from: string) => { if (status !== from) move.mutate({ id, status }) }
  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  return (
    <div className="flex gap-3 overflow-x-auto pb-2" role="list">
      {q.data!.map((col) => (
        <div key={col.status} role="listitem" aria-label={lb(PROJECT_STATUSES, col.status, lang)}
          onDragOver={(e) => { if (canMove) { e.preventDefault(); setOver(col.status) } }} onDragLeave={() => setOver(null)}
          onDrop={(e) => { e.preventDefault(); setOver(null); const from = q.data!.find((c) => c.items.some((i: any) => i.id === dragging))?.status; if (dragging && from) request(dragging, col.status, from); setDragging(null) }}
          className={clsx('glass w-72 shrink-0 rounded-2xl p-2', over === col.status && 'ring-2 ring-lit')}>
          <h3 className="mb-2 flex items-center justify-between px-1 text-sm font-semibold">{lb(PROJECT_STATUSES, col.status, lang)}<span className="rounded-full bg-surface-2 px-2 text-xs text-muted">{col.total}</span></h3>
          <div className="space-y-2">
            {col.items.map((p: any) => (
              <article key={p.id} draggable={canMove} onDragStart={() => setDragging(p.id)} onDragEnd={() => setDragging(null)} className={clsx('lit rounded-xl border border-line bg-surface/70 p-3 text-sm', canMove && 'cursor-grab', dragging === p.id && 'opacity-50')}>
                <Link to={`/admin/projects/${p.id}`} className="font-semibold text-brand-2 hover:underline">{p.name}</Link>
                {p.customer && <div className="text-xs text-muted">{p.customer.name}</div>}
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-lit" style={{ width: `${p.progress}%` }} /></div>
                <div className="mt-2 flex items-center justify-between gap-2 text-xs text-muted"><span>{p.manager?.name ?? '—'}</span><span className={clsx(['high', 'urgent'].includes(p.priority) && 'text-danger')}>{p.due_date ? fmtDate(p.due_date, lang) : ''}</span></div>
                {canMove && <select aria-label={tt('نقل إلى', 'Move to')} className="mt-2 w-full rounded border border-line bg-bg p-1 text-xs" value={col.status} onChange={(e) => request(p.id, e.target.value, col.status)}>{PROJECT_STATUSES.map((s) => <option key={s.value} value={s.value}>{lang === 'ar' ? s.label_ar : s.label_en}</option>)}</select>}
              </article>))}
            {col.items.length === 0 && <p className="p-2 text-center text-xs text-muted">—</p>}
          </div>
        </div>))}
    </div>
  )
}

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
function Calendar() {
  const { lang, tt } = useLang(); const [month, setMonth] = useState(() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1) })
  const from = new Date(month.getFullYear(), month.getMonth(), 1); const to = new Date(month.getFullYear(), month.getMonth() + 1, 0)
  const q = useQuery({ queryKey: ['projects-cal', iso(from)], queryFn: () => api.get<{ data: any }>(`/admin/projects/calendar?from=${iso(from)}&to=${iso(to)}`).then((r) => r.data) })
  const events: Record<string, { kind: string; text: string; id: number }[]> = {}
  const add = (d: string | null, kind: string, text: string, id: number) => { if (!d) return; const k = d.slice(0, 10); (events[k] ??= []).push({ kind, text, id }) }
  q.data?.projects.forEach((p: any) => { add(p.start_date, 'start', p.name, p.id); add(p.due_date, 'due', p.name, p.id) })
  q.data?.milestones.forEach((m: any) => add(m.due_date, 'ms', m.title, m.project_id))
  q.data?.tasks.forEach((t: any) => add(t.due_at, 'task', t.title, t.project_id))
  const lead = (from.getDay() + 1) % 7 // week starts Saturday
  const cells = [...Array(lead).fill(null), ...Array.from({ length: to.getDate() }, (_, i) => new Date(from.getFullYear(), from.getMonth(), i + 1))]
  const color: Record<string, string> = { start: 'bg-lit/20', due: 'bg-danger/20', ms: 'bg-warn/20', task: 'bg-surface-2' }
  const kindLabel: Record<string, string> = { start: tt('بداية', 'Start'), due: tt('تسليم', 'Due'), ms: tt('مرحلة', 'Milestone'), task: tt('مهمة', 'Task') }
  return (
    <div className="glass rounded-2xl p-3">
      <div className="mb-3 flex items-center gap-2">
        <button className="icon-btn" aria-label="prev" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))}><ChevronRight size={16} className="ltr:rotate-180" /></button>
        <h2 id="cal-title" className="min-w-40 text-center font-semibold">{month.toLocaleDateString(lang === 'ar' ? 'ar-EG' : 'en-US', { month: 'long', year: 'numeric' })}</h2>
        <button className="icon-btn" aria-label="next" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))}><ChevronLeft size={16} className="ltr:rotate-180" /></button>
      </div>
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState error={q.error} onRetry={() => q.refetch()} /> : (
        <div className="overflow-x-auto"><div className="grid min-w-[640px] grid-cols-7 gap-1">
          {cells.map((d, i) => d ? (
            <div key={i} className={clsx('min-h-24 rounded-lg border border-line p-1 text-xs', iso(d) === iso(new Date()) && 'ring-1 ring-lit')}>
              <div className="mb-1 text-muted">{d.getDate()}</div>
              {(events[iso(d)] ?? []).slice(0, 4).map((e, j) => <Link key={j} to={`/admin/projects/${e.id}`} title={`${kindLabel[e.kind]}: ${e.text}`} className={clsx('mb-0.5 block truncate rounded px-1 py-0.5', color[e.kind])}>{e.text}</Link>)}
              {(events[iso(d)]?.length ?? 0) > 4 && <span className="text-muted">+{events[iso(d)].length - 4}</span>}
            </div>) : <div key={i} />)}
        </div></div>)}
      <p className="mt-2 flex flex-wrap gap-3 text-xs text-muted">{Object.keys(color).map((k) => <span key={k} className="inline-flex items-center gap-1"><i className={clsx('inline-block h-2.5 w-2.5 rounded', color[k])} />{kindLabel[k]}</span>)}</p>
    </div>
  )
}

export function ProjectsPage() {
  const { can } = useAuth(); const { tt } = useLang()
  const [view, setView] = useState<'board' | 'table' | 'calendar'>('board')
  const names = { board: tt('لوحة', 'Board'), table: tt('جدول', 'Table'), calendar: tt('تقويم', 'Calendar') }
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <div role="tablist" aria-label="view" className="glass inline-flex rounded-xl p-1 text-sm">
          {(['board', 'table', 'calendar'] as const).map((v) => <button key={v} role="tab" aria-selected={view === v} onClick={() => setView(v)} className={clsx('rounded-lg px-3 py-1.5', view === v ? 'bg-brand text-white dark:text-bg' : 'hover:bg-surface-2')}>{names[v]}</button>)}
        </div>
        {can('projects.create') && <Link to="/admin/projects/new" className="btn btn-primary ms-auto"><Plus size={16} aria-hidden />{tt('مشروع جديد', 'New project')}</Link>}
      </div>
      {view === 'table' ? <ResourcePage cfg={projects} /> : view === 'board' ? <Board /> : <Calendar />}
    </div>
  )
}

export function ProjectCreate() {
  const nav = useNavigate(); const toast = useToast(); const { tt } = useLang()
  const opt = (u: string) => useQuery({ queryKey: ['options', u], queryFn: () => api.get<{ data: Option[] }>(u).then((r) => r.data) })
  const customers = opt('/admin/customers/options'); const users = opt('/admin/users/options')
  const [f, setF] = useState({ name: '', customer_id: '', type: '', priority: 'normal', start_date: '', due_date: '', manager_id: '', description: '' }); const [err, setErr] = useState<Record<string, string[]>>({})
  const save = useMutation({
    mutationFn: () => { const b: any = {}; Object.entries(f).forEach(([k, v]) => { if (v !== '') b[k] = k.endsWith('_id') ? Number(v) : v }); return api.post<{ data: any }>('/admin/projects', b) },
    onSuccess: (r) => { toast(tt('تم الحفظ', 'Saved')); nav(`/admin/projects/${r.data.id}`) }, onError: (e) => { setErr(e instanceof ApiError ? e.errors : {}); toast(msg(e, tt('حدث خطأ', 'Error')), 'error') },
  })
  const s = (k: string) => (e: any) => setF({ ...f, [k]: e.target.value }); const lab = 'block text-xs text-muted'
  const fe = (k: string) => err[k]?.[0] && <p className="mt-0.5 text-xs text-danger">{err[k][0]}</p>
  return (
    <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); setErr({}); save.mutate() }}>
      <div className="flex items-center gap-3"><Link to="/admin/projects" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight size={14} className="ltr:rotate-180" aria-hidden />{tt('المشاريع', 'Projects')}</Link><h1 className="display text-xl font-bold">{tt('مشروع جديد', 'New project')}</h1></div>
      <Card><div className="grid gap-3 md:grid-cols-2">
        <label className={`${lab} md:col-span-2`}>{tt('اسم المشروع', 'Project name')}<input id="p-name" required className={`${input} mt-1 w-full`} value={f.name} onChange={s('name')} />{fe('name')}</label>
        <label className={lab}>{tt('العميل', 'Customer')}<select className={`${input} mt-1 w-full`} value={f.customer_id} onChange={s('customer_id')}><option value="">—</option>{customers.data?.map((c) => <option key={c.value} value={c.value}>{c.label_en}</option>)}</select></label>
        <label className={lab}>{tt('النوع', 'Type')}<input className={`${input} mt-1 w-full`} value={f.type} onChange={s('type')} placeholder={tt('مثال: تصوير، حملة', 'e.g. photography, campaign')} /></label>
        <label className={lab}>{tt('الأولوية', 'Priority')}<select className={`${input} mt-1 w-full`} value={f.priority} onChange={s('priority')}>{PRIORITIES.map((p) => <option key={p.value} value={p.value}>{tt(p.label_ar, p.label_en)}</option>)}</select></label>
        <label className={lab}>{tt('مدير المشروع', 'Manager')}<select className={`${input} mt-1 w-full`} value={f.manager_id} onChange={s('manager_id')}><option value="">{tt('أنا', 'Me')}</option>{users.data?.map((u) => <option key={u.value} value={u.value}>{u.label_en}</option>)}</select></label>
        <label className={lab}>{tt('البداية', 'Start date')}<input type="date" className={`${input} mt-1 w-full`} value={f.start_date} onChange={s('start_date')} />{fe('start_date')}</label>
        <label className={lab}>{tt('التسليم', 'Due date')}<input type="date" className={`${input} mt-1 w-full`} value={f.due_date} onChange={s('due_date')} />{fe('due_date')}</label>
        <label className={`${lab} md:col-span-2`}>{tt('الوصف', 'Description')}<textarea rows={4} className={`${input} mt-1 w-full`} value={f.description} onChange={s('description')} /></label>
      </div></Card>
      <div className="flex justify-end gap-2"><Link to="/admin/projects" className="btn btn-outline">{tt('إلغاء', 'Cancel')}</Link><Button type="submit" disabled={save.isPending}>{tt('حفظ', 'Save')}</Button></div>
    </form>
  )
}

export function ProjectDetail() {
  const { id } = useParams(); const nav = useNavigate(); const { can } = useAuth(); const toast = useToast(); const qc = useQueryClient(); const { lang, tt } = useLang()
  const key = ['project', id]
  const q = useQuery({ queryKey: key, queryFn: () => api.get<{ data: any }>(`/admin/projects/${id}`).then((r) => r.data) })
  const tasks = useQuery({ queryKey: ['project-tasks', id], queryFn: () => api.get<{ data: any[] }>(`/admin/projects/${id}/tasks`).then((r) => r.data) })
  const tl = useQuery({ queryKey: ['project-tl', id], queryFn: () => api.get<{ data: any[] }>(`/admin/projects/${id}/timeline`).then((r) => r.data) })
  const [task, setTask] = useState({ title: '', due_at: '' }); const [ms, setMs] = useState({ title: '', due_date: '' }); const [note, setNote] = useState(''); const [del, setDel] = useState(false)
  const refresh = () => { ['project', 'project-tasks', 'project-tl'].forEach((k) => qc.invalidateQueries({ queryKey: [k, id] })); qc.invalidateQueries({ queryKey: ['projects-board'] }); qc.invalidateQueries({ queryKey: ['resource', 'projects'] }) }
  const fail = (e: unknown) => { setDel(false); toast(msg(e, tt('حدث خطأ', 'Error')), 'error') }
  const ok = (m?: string) => { toast(m ?? tt('تم', 'Done')); refresh() }
  const status = useMutation({ mutationFn: (s: string) => api.post(`/admin/projects/${id}/status`, { status: s }), onSuccess: () => ok(), onError: fail })
  const addTask = useMutation({ mutationFn: () => api.post('/admin/tasks', { title: task.title, due_at: task.due_at || null, taskable_type: 'project', taskable_id: Number(id) }), onSuccess: () => { setTask({ title: '', due_at: '' }); ok() }, onError: fail })
  const toggle = useMutation({ mutationFn: (t: any) => api.post(`/admin/tasks/${t.id}/${t.status === 'done' ? 'reopen' : 'complete'}`), onSuccess: () => ok(), onError: fail })
  const putMs = useMutation({ mutationFn: (list: any[]) => api.put(`/admin/projects/${id}`, { name: q.data.name, milestones: list }), onSuccess: () => { setMs({ title: '', due_date: '' }); ok() }, onError: fail })
  const addNote = useMutation({ mutationFn: () => api.post(`/admin/projects/${id}/notes`, { body: note }), onSuccess: () => { setNote(''); ok() }, onError: fail })
  const remove = useMutation({ mutationFn: () => api.delete(`/admin/projects/${id}`), onSuccess: () => { qc.invalidateQueries({ queryKey: ['resource', 'projects'] }); nav('/admin/projects') }, onError: fail })
  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  const p = q.data; const canEdit = can('projects.edit') || can('projects.edit_assigned')
  const mlist = (p.milestones ?? []).map((m: any) => ({ id: m.id, title: m.title, description: m.description, due_date: m.due_date?.slice(0, 10) ?? null, status: m.status }))
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/projects" className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><ArrowRight size={14} className="ltr:rotate-180" aria-hidden />{tt('المشاريع', 'Projects')}</Link>
        <h1 className="display text-xl font-bold">{p.name}</h1><span id="p-status" className="rounded-full bg-lit/15 px-3 py-1 text-xs font-semibold">{lb(PROJECT_STATUSES, p.status, lang)}</span>
        {can('projects.delete') && <Button variant="outline" className="ms-auto !text-danger" onClick={() => setDel(true)}>{tt('حذف', 'Delete')}</Button>}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title={tt('نظرة عامة', 'Overview')}>
            <div className="mb-3 h-2 overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-lit transition-all" style={{ width: `${p.progress}%` }} /></div>
            <dl className="grid gap-2 text-sm sm:grid-cols-2">
              <div><dt className="text-xs text-muted">{tt('التقدّم', 'Progress')}</dt><dd id="p-progress">{p.progress}%</dd></div>
              <div><dt className="text-xs text-muted">{tt('العميل', 'Customer')}</dt><dd>{p.customer?.name ?? '—'}</dd></div>
              <div><dt className="text-xs text-muted">{tt('المدير', 'Manager')}</dt><dd>{p.manager?.name ?? '—'}</dd></div>
              <div><dt className="text-xs text-muted">{tt('الأولوية', 'Priority')}</dt><dd>{lb(PRIORITIES, p.priority, lang)}</dd></div>
              <div><dt className="text-xs text-muted">{tt('البداية', 'Start')}</dt><dd>{p.start_date ? fmtDate(p.start_date, lang) : '—'}</dd></div>
              <div><dt className="text-xs text-muted">{tt('التسليم', 'Due')}</dt><dd>{p.due_date ? fmtDate(p.due_date, lang) : '—'}</dd></div>
            </dl>
            {p.description && <p className="mt-3 whitespace-pre-wrap text-sm text-muted">{p.description}</p>}
          </Card>
          <Card title={tt('المهام', 'Tasks')}>
            <ul className="divide-y divide-line text-sm">
              {(tasks.data ?? []).map((t) => <li key={t.id} className="flex items-center gap-2 py-2"><input type="checkbox" aria-label={t.title} className="h-4 w-4 accent-[rgb(var(--c-lit))]" checked={t.status === 'done'} disabled={!canEdit} onChange={() => toggle.mutate(t)} />
                <span className={clsx(t.status === 'done' && 'text-muted line-through')}>{t.title}</span><span className="ms-auto text-xs text-muted">{t.assignee?.name} {t.due_at ? fmtDate(t.due_at, lang) : ''}</span></li>)}
              {tasks.data?.length === 0 && <li className="py-2 text-muted">—</li>}
            </ul>
            {canEdit && <form className="mt-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); if (task.title.trim()) addTask.mutate() }}>
              <input id="t-title" aria-label={tt('مهمة جديدة', 'New task')} placeholder={tt('مهمة جديدة', 'New task')} className={`${input} min-w-48 flex-1`} value={task.title} onChange={(e) => setTask({ ...task, title: e.target.value })} />
              <input type="date" aria-label="due" className={input} value={task.due_at} onChange={(e) => setTask({ ...task, due_at: e.target.value })} /><Button type="submit" disabled={addTask.isPending}>{tt('إضافة', 'Add')}</Button></form>}
          </Card>
          <Card title={tt('المراحل', 'Milestones')}>
            <ul className="divide-y divide-line text-sm">
              {mlist.map((m: any, i: number) => <li key={m.id} className="flex items-center gap-2 py-2"><input type="checkbox" aria-label={m.title} className="h-4 w-4 accent-[rgb(var(--c-lit))]" checked={m.status === 'done'} disabled={!canEdit} onChange={() => putMs.mutate(mlist.map((x: any, j: number) => (j === i ? { ...x, status: x.status === 'done' ? 'pending' : 'done' } : x)))} />
                <span className={clsx(m.status === 'done' && 'text-muted line-through')}>{m.title}</span><span className="ms-auto text-xs text-muted">{m.due_date ? fmtDate(m.due_date, lang) : ''}</span>
                {canEdit && <button className="icon-btn danger" aria-label={tt('حذف', 'Remove')} onClick={() => putMs.mutate(mlist.filter((_: any, j: number) => j !== i))}><Trash2 size={14} /></button>}</li>)}
              {mlist.length === 0 && <li className="py-2 text-muted">—</li>}
            </ul>
            {canEdit && <form className="mt-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); if (ms.title.trim()) putMs.mutate([...mlist, { title: ms.title, due_date: ms.due_date || null }]) }}>
              <input id="m-title" aria-label={tt('مرحلة جديدة', 'New milestone')} placeholder={tt('مرحلة جديدة', 'New milestone')} className={`${input} min-w-48 flex-1`} value={ms.title} onChange={(e) => setMs({ ...ms, title: e.target.value })} />
              <input type="date" aria-label="due" className={input} value={ms.due_date} onChange={(e) => setMs({ ...ms, due_date: e.target.value })} /><Button type="submit" disabled={putMs.isPending}>{tt('إضافة', 'Add')}</Button></form>}
          </Card>
        </div>
        <div className="space-y-4">
          {canEdit && <Card title={tt('الحالة', 'Status')}><div className="flex flex-wrap gap-2">{PROJECT_STATUSES.filter((s) => s.value !== p.status).map((s) => <Button key={s.value} variant="outline" disabled={status.isPending} onClick={() => status.mutate(String(s.value))}>{tt(s.label_ar, s.label_en)}</Button>)}</div></Card>}
          <Card title={tt('ملاحظات وسجل النشاط', 'Notes & activity')}>
            {canEdit && <form className="mb-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (note.trim()) addNote.mutate() }}><input id="n-body" aria-label={tt('ملاحظة', 'Note')} className={`${input} flex-1`} value={note} onChange={(e) => setNote(e.target.value)} maxLength={3000} /><Button type="submit" disabled={addNote.isPending || !note.trim()}>{tt('إضافة', 'Add')}</Button></form>}
            <ol className="space-y-2 text-sm">{(tl.data ?? []).map((a) => <li key={a.id} className="rounded-lg bg-surface-2/60 p-2"><p>{a.body ?? a.type}</p><p className="mt-1 text-xs text-muted">{a.user?.name ?? tt('النظام', 'System')} · {fmtDate(a.created_at, lang)}</p></li>)}</ol>
          </Card>
        </div>
      </div>
      <ConfirmDialog open={del} danger busy={remove.isPending} title={tt('حذف المشروع؟', 'Delete this project?')} message={tt('سيتم حذف المشروع ومهامه.', 'The project and its tasks will be removed.')} confirmLabel={tt('حذف', 'Delete')} onCancel={() => setDel(false)} onConfirm={() => remove.mutate()} />
    </div>
  )
}
