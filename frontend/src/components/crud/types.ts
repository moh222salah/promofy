export type Bi = { ar: string; en: string }
export interface Option { value: string | number; label_ar: string; label_en: string }

export interface FieldDef {
  name: string
  label: Bi
  type: 'text' | 'textarea' | 'number' | 'select' | 'bool' | 'date' | 'url'
  required?: boolean
  dir?: 'ltr'
  /** static options, or a CRUD "options" endpoint (e.g. "/admin/service-categories/options") */
  options?: Option[]
  optionsFrom?: string
  /** include an empty choice for selects */
  blank?: boolean
  hint?: Bi
  wide?: boolean
  showIf?: (values: Record<string, any>) => boolean
}

export interface ColumnDef {
  key: string
  label: Bi
  sortable?: boolean
  /** render from the record; defaults to the raw value */
  render?: (row: any, lang: 'ar' | 'en') => React.ReactNode
  badge?: boolean
}

export interface FilterDef { name: string; label: Bi; options?: Option[]; optionsFrom?: string }

export interface ResourceConfig {
  /** API path under /admin, e.g. "service-categories" */
  path: string
  /** permission module, e.g. "services" */
  module: string
  title: Bi
  columns: ColumnDef[]
  fields: FieldDef[]
  filters?: FilterDef[]
  defaults?: Record<string, any>
  defaultSort?: string
  /** status workflow (publish / unpublish / archive) available on this resource */
  workflow?: boolean
  duplicate?: boolean
  exportCsv?: boolean
  /** bulk actions offered for selected rows */
  bulk?: Array<'publish' | 'unpublish' | 'archive' | 'delete'>
  /** adds up/down controls that persist order through POST /reorder */
  reorder?: boolean
  /** list + detail only: no create/edit/delete from the table (records change through domain actions) */
  readOnly?: boolean
  /** the first column links to `${detailPath}/${id}` */
  detailPath?: string
  /** permission module/actions overrides */
  perms?: { create?: string; edit?: string; delete?: string; publish?: string; export?: string }
}
