import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Copy, Download, Pencil, Plus, Trash2, X, Eye, EyeOff, Archive } from 'lucide-react'
import { api, ApiError, qs } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { Button, Card, EmptyState, ErrorState, Spinner } from '@/components/ui'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useToast } from '@/components/Toast'
import { FieldInput, useOptions } from './FormFields'
import type { FilterDef, ResourceConfig } from './types'

type Lang = 'ar' | 'en'
interface Page { data: any[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }

function StatusBadge({ value }: { value: string }) {
  const { t } = useTranslation()
  const tone = value === 'published' ? 'bg-success/15 text-success' : value === 'archived' ? 'bg-muted/15 text-muted' : 'bg-warn/15 text-warn'
  return <span className={clsx('rounded-full px-2 py-0.5 text-xs font-medium', tone)}>{t(`status.${value}`, { defaultValue: value })}</span>
}

function FilterSelect({ def, value, onChange, lang }: { def: FilterDef; value: string; onChange: (v: string) => void; lang: Lang }) {
  const { t } = useTranslation()
  const opts = useOptions(def)
  return (
    <select aria-label={def.label[lang]} className="rounded-lg border border-line bg-surface p-2 text-sm" value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{def.label[lang]}: {t('crud.all')}</option>
      {opts.map((o) => <option key={o.value} value={o.value}>{lang === 'ar' ? o.label_ar : o.label_en}</option>)}
    </select>
  )
}

function messageOf(e: unknown, fallback: string): string {
  return e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : fallback
}

export default function ResourcePage({ cfg }: { cfg: ResourceConfig }) {
  const { t, i18n } = useTranslation()
  const lang: Lang = i18n.language === 'ar' ? 'ar' : 'en'
  const { can } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const base = `/admin/${cfg.path}`
  const perm = (a: string) => can(`${cfg.module}.${a}`)
  const key = ['resource', cfg.path]

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [sort, setSort] = useState(cfg.defaultSort ?? '-id')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [editing, setEditing] = useState<{ id: number | null; values: Record<string, any>; original?: Record<string, any> } | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [confirm, setConfirm] = useState<null | { kind: 'delete' | 'bulk-delete'; id?: number }>(null)

  useEffect(() => { const h = setTimeout(() => { setTerm(search); setPage(1) }, 300); return () => clearTimeout(h) }, [search])

  const params = useMemo(() => {
    const p: Record<string, string | number> = { page, per_page: 20, sort }
    if (term) p.search = term
    for (const [k, v] of Object.entries(filters)) if (v) p[`filter[${k}]`] = v
    return p
  }, [page, term, sort, filters])

  const list = useQuery({ queryKey: [...key, params], queryFn: () => api.get<Page>(base, params), placeholderData: (prev) => prev })
  const refresh = () => { qc.invalidateQueries({ queryKey: key }); setSelected(new Set()) }

  const save = useMutation({
    mutationFn: ({ id, values }: { id: number | null; values: Record<string, any> }) => (id ? api.put(`${base}/${id}`, values) : api.post(base, values)),
    onSuccess: () => { toast(t('crud.saved')); setEditing(null); setErrors({}); refresh() },
    onError: (e) => {
      if (e instanceof ApiError && e.status === 422) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])))
      else toast(messageOf(e, t('app.error')), 'error')
    },
  })
  const act = useMutation({
    mutationFn: ({ id, action }: { id: number; action: 'duplicate' | 'publish' | 'unpublish' | 'archive' }) => api.post(`${base}/${id}/${action}`),
    onSuccess: () => { toast(t('crud.done')); refresh() },
    onError: (e) => toast(messageOf(e, t('app.error')), 'error'),
  })
  const del = useMutation({
    mutationFn: (id: number) => api.delete(`${base}/${id}`),
    onSuccess: () => { toast(t('crud.deleted')); setConfirm(null); refresh() },
    onError: (e) => { toast(messageOf(e, t('app.error')), 'error'); setConfirm(null) },
  })
  const bulk = useMutation({
    mutationFn: (action: string) => api.post(`${base}/bulk`, { action, ids: [...selected], confirm: action === 'delete' ? true : undefined }),
    onSuccess: () => { toast(t('crud.done')); setConfirm(null); refresh() },
    onError: (e) => { toast(messageOf(e, t('app.error')), 'error'); setConfirm(null) },
  })
  const move = useMutation({
    mutationFn: (ids: number[]) => api.post(`${base}/reorder`, { ids }),
    onSuccess: refresh, onError: (e) => toast(messageOf(e, t('app.error')), 'error'),
  })

  const openEdit = async (id: number | null) => {
    setErrors({})
    if (id === null) return setEditing({ id: null, values: { ...(cfg.defaults ?? {}) } })
    try { const r = await api.get<{ data: Record<string, any> }>(`${base}/${id}`); setEditing({ id, values: r.data, original: r.data }) } catch (e) { toast(messageOf(e, t('crud.loadFail')), 'error') }
  }
  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!editing) return
    const missing: Record<string, string> = {}
    for (const f of cfg.fields) if (f.required && (f.showIf?.(editing.values) ?? true) && (editing.values[f.name] === undefined || editing.values[f.name] === null || editing.values[f.name] === '')) missing[f.name] = t('crud.required')
    if (Object.keys(missing).length) return setErrors(missing)
    const values: Record<string, any> = {}
    for (const f of cfg.fields) {
      if (!(f.showIf?.(editing.values) ?? true)) continue
      const v = editing.values[f.name]
      if (f.type === 'bool') { values[f.name] = !!v; continue }
      // never-set optional fields are omitted (the API's "sometimes" rules reject null); a value the user cleared is sent as null
      if ((v === undefined || v === null || v === '') && (editing.original?.[f.name] ?? null) === null) continue
      values[f.name] = v === '' ? null : v
    }
    save.mutate({ id: editing.id, values })
  }
  const exportHref = `/api/v1${base}/export${qs({ search: term || undefined, sort })}`

  const rows = list.data?.data ?? []
  const meta = list.data?.meta
  const sortBy = (key: string) => setSort(sort === key ? `-${key}` : key)
  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.id))
  const bulkActions = (cfg.bulk ?? []).filter((a) => (a === 'delete' ? perm('delete') : perm(cfg.perms?.publish ?? 'publish')))
  const moveRow = (idx: number, dir: -1 | 1) => {
    const ids = rows.map((r) => r.id); const j = idx + dir
    if (j < 0 || j >= ids.length) return
    ;[ids[idx], ids[j]] = [ids[j], ids[idx]]; move.mutate(ids)
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="me-auto text-xl font-bold">{cfg.title[lang]}</h1>
        {cfg.exportCsv && perm('export') && <a href={exportHref} className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm hover:bg-surface-2"><Download size={16} aria-hidden />{t('crud.export')}</a>}
        {perm('create') && <Button onClick={() => openEdit(null)}><Plus size={16} aria-hidden />{t('crud.create')}</Button>}
      </div>

      <Card>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <input type="search" aria-label={t('crud.search')} placeholder={t('crud.search')} className="min-w-48 flex-1 rounded-lg border border-line bg-bg p-2 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
          {cfg.filters?.map((f) => <FilterSelect key={f.name} def={f} lang={lang} value={filters[f.name] ?? ''} onChange={(v) => { setFilters({ ...filters, [f.name]: v }); setPage(1) }} />)}
          {selected.size > 0 && bulkActions.length > 0 && (
            <div className="flex items-center gap-1 text-sm"><span className="text-muted">{selected.size} {t('crud.selected')}</span>
              {bulkActions.map((a) => <Button key={a} variant="outline" disabled={bulk.isPending} className={a === 'delete' ? '!text-danger' : ''} onClick={() => (a === 'delete' ? setConfirm({ kind: 'bulk-delete' }) : bulk.mutate(a))}>{t(`crud.${a}`)}</Button>)}
            </div>)}
        </div>

        {list.isLoading ? <Spinner /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : rows.length === 0 ? <EmptyState message={t('app.empty')} /> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-muted">
                  {bulkActions.length > 0 && <th className="w-8 p-2"><input type="checkbox" aria-label="select all" checked={allSelected} onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)))} /></th>}
                  {cfg.columns.map((c) => (
                    <th key={c.key} className="p-2 text-start font-medium" aria-sort={sort === c.key ? 'ascending' : sort === `-${c.key}` ? 'descending' : undefined}>
                      {c.sortable ? <button className="hover:text-ink" onClick={() => sortBy(c.key)}>{c.label[lang]}{sort === c.key ? ' ↑' : sort === `-${c.key}` ? ' ↓' : ''}</button> : c.label[lang]}
                    </th>))}
                  <th className="p-2 text-end font-medium">{t('crud.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, idx) => (
                  <tr key={r.id} className="border-b border-line last:border-0 hover:bg-surface-2/50">
                    {bulkActions.length > 0 && <td className="p-2"><input type="checkbox" aria-label={`select ${r.id}`} checked={selected.has(r.id)} onChange={() => { const n = new Set(selected); n.has(r.id) ? n.delete(r.id) : n.add(r.id); setSelected(n) }} /></td>}
                    {cfg.columns.map((c) => <td key={c.key} className="p-2">{c.render ? c.render(r, lang) : c.badge ? <StatusBadge value={r[c.key]} /> : (typeof r[c.key] === 'boolean' ? (r[c.key] ? t('crud.yes') : t('crud.no')) : (r[c.key] ?? t('crud.none')))}</td>)}
                    <td className="p-2"><div className="flex justify-end gap-1">
                      {cfg.reorder && perm('edit') && <>
                        <button className="rounded p-1.5 hover:bg-surface-2" aria-label={t('crud.reorder')} disabled={idx === 0 || move.isPending} onClick={() => moveRow(idx, -1)}><ArrowUp size={15} /></button>
                        <button className="rounded p-1.5 hover:bg-surface-2" aria-label={t('crud.reorderDown')} disabled={idx === rows.length - 1 || move.isPending} onClick={() => moveRow(idx, 1)}><ArrowDown size={15} /></button></>}
                      {cfg.workflow && perm(cfg.perms?.publish ?? 'publish') && (r.status === 'published'
                        ? <button className="rounded p-1.5 hover:bg-surface-2" title={t('crud.unpublish')} aria-label={t('crud.unpublish')} onClick={() => act.mutate({ id: r.id, action: 'unpublish' })}><EyeOff size={15} /></button>
                        : <button className="rounded p-1.5 hover:bg-surface-2" title={t('crud.publish')} aria-label={t('crud.publish')} onClick={() => act.mutate({ id: r.id, action: 'publish' })}><Eye size={15} /></button>)}
                      {cfg.workflow && perm(cfg.perms?.publish ?? 'publish') && r.status !== 'archived' && <button className="rounded p-1.5 hover:bg-surface-2" title={t('crud.archive')} aria-label={t('crud.archive')} onClick={() => act.mutate({ id: r.id, action: 'archive' })}><Archive size={15} /></button>}
                      {cfg.duplicate && perm('create') && <button className="rounded p-1.5 hover:bg-surface-2" title={t('crud.duplicate')} aria-label={t('crud.duplicate')} onClick={() => act.mutate({ id: r.id, action: 'duplicate' })}><Copy size={15} /></button>}
                      {perm('edit') && <button className="rounded p-1.5 hover:bg-surface-2" title={t('crud.edit')} aria-label={t('crud.edit')} onClick={() => openEdit(r.id)}><Pencil size={15} /></button>}
                      {perm('delete') && <button className="rounded p-1.5 text-danger hover:bg-surface-2" title={t('crud.delete')} aria-label={t('crud.delete')} onClick={() => setConfirm({ kind: 'delete', id: r.id })}><Trash2 size={15} /></button>}
                    </div></td>
                  </tr>))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="mt-3 flex items-center justify-between text-sm text-muted">
            <span>{meta.total} {t('crud.rows')}</span>
            <div className="flex items-center gap-2">
              <Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('crud.prev')}</Button>
              <span>{t('crud.page')} {meta.current_page} {t('crud.of')} {meta.last_page}</span>
              <Button variant="outline" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>{t('crud.next')}</Button>
            </div>
          </div>)}
      </Card>

      {editing && (
        <div className="fixed inset-0 z-40 flex justify-end bg-black/40" onMouseDown={() => setEditing(null)}>
          <form role="dialog" aria-modal="true" aria-label={cfg.title[lang]} onSubmit={submit} onMouseDown={(e) => e.stopPropagation()} className="flex h-full w-full max-w-xl flex-col bg-surface shadow-2xl">
            <header className="flex items-center justify-between border-b border-line p-4">
              <h2 className="font-bold">{editing.id ? t('crud.edit') : t('crud.create')} — {cfg.title[lang]}</h2>
              <button type="button" aria-label={t('crud.cancel')} className="rounded p-1 hover:bg-surface-2" onClick={() => setEditing(null)}><X size={18} /></button>
            </header>
            <div className="grid flex-1 content-start gap-4 overflow-y-auto p-4 md:grid-cols-2">
              {cfg.fields.filter((f) => f.showIf?.(editing.values) ?? true).map((f) => (
                <FieldInput key={f.name} def={f} lang={lang} value={editing.values[f.name]} error={errors[f.name]} onChange={(v) => setEditing({ ...editing, values: { ...editing.values, [f.name]: v } })} />))}
            </div>
            <footer className="flex justify-end gap-2 border-t border-line p-4">
              <Button type="button" variant="outline" onClick={() => setEditing(null)}>{t('crud.cancel')}</Button>
              <Button type="submit" disabled={save.isPending}>{t('crud.save')}</Button>
            </footer>
          </form>
        </div>)}

      <ConfirmDialog open={confirm !== null} danger busy={del.isPending || bulk.isPending}
        title={confirm?.kind === 'bulk-delete' ? t('crud.bulkDeleteTitle') : t('crud.deleteTitle')} message={t('crud.deleteMsg')} confirmLabel={t('crud.delete')}
        onCancel={() => setConfirm(null)} onConfirm={() => (confirm?.kind === 'bulk-delete' ? bulk.mutate('delete') : confirm?.id && del.mutate(confirm.id))} />
    </div>
  )
}
