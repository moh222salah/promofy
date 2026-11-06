import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { FieldDef, Option } from './types'

const input = 'mt-1 w-full field p-2 text-sm'

export function useOptions(def: { options?: Option[]; optionsFrom?: string }) {
  const q = useQuery({ queryKey: ['options', def.optionsFrom], queryFn: () => api.get<{ data: Option[] }>(def.optionsFrom!).then((r) => r.data), enabled: !!def.optionsFrom, staleTime: 60_000 })
  return def.options ?? q.data ?? []
}

export function FieldInput({ def, value, onChange, error, lang }: { def: FieldDef; value: any; onChange: (v: any) => void; error?: string; lang: 'ar' | 'en' }) {
  const opts = useOptions(def)
  const label = def.label[lang]
  const id = `f-${def.name}`
  const common = { id, 'aria-invalid': !!error, 'aria-describedby': error ? `${id}-err` : undefined }
  let control
  switch (def.type) {
    case 'textarea':
      control = <textarea {...common} rows={4} className={input} dir={def.dir} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />; break
    case 'number':
      control = <input {...common} type="number" step="any" dir="ltr" className={input} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />; break
    case 'select':
      control = (
        <select {...common} className={input} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : isNaN(Number(e.target.value)) ? e.target.value : Number(e.target.value))}>
          {(def.blank || !def.required) && <option value="">—</option>}
          {opts.map((o) => <option key={o.value} value={o.value}>{lang === 'ar' ? o.label_ar : o.label_en}</option>)}
        </select>)
      break
    case 'bool':
      return (
        <div className={def.wide ? 'md:col-span-2' : ''}>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4" checked={!!value} onChange={(e) => onChange(e.target.checked)} />{label}</label>
          {error && <p id={`${id}-err`} role="alert" className="mt-1 text-xs text-danger">{error}</p>}
        </div>)
    case 'date':
      control = <input {...common} type="date" dir="ltr" className={input} value={value ? String(value).slice(0, 10) : ''} onChange={(e) => onChange(e.target.value || null)} />; break
    case 'url':
      control = <input {...common} type="url" dir="ltr" className={input} value={value ?? ''} onChange={(e) => onChange(e.target.value || null)} />; break
    default:
      control = <input {...common} type="text" dir={def.dir} className={input} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  }
  return (
    <div className={def.wide || def.type === 'textarea' ? 'md:col-span-2' : ''}>
      <label htmlFor={id} className="text-sm font-medium">{label}{def.required && <span className="text-danger"> *</span>}</label>
      {control}
      {def.hint && !error && <p className="mt-1 text-xs text-muted">{def.hint[lang]}</p>}
      {error && <p id={`${id}-err`} role="alert" className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  )
}
