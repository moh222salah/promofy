import type { ResourceConfig, Option, Bi } from '@/components/crud/types'
import { fmtDate } from '@/i18n'

const L = (ar: string, en: string): Bi => ({ ar, en })
const O = (value: string, ar: string, en: string): Option => ({ value, label_ar: ar, label_en: en })

export const ORDER_STATUSES: Option[] = [
  O('pending', 'قيد الانتظار', 'Pending'), O('awaiting_payment', 'بانتظار الدفع', 'Awaiting payment'), O('paid', 'مدفوع', 'Paid'), O('processing', 'قيد المعالجة', 'Processing'),
  O('in_progress', 'قيد التنفيذ', 'In progress'), O('completed', 'مكتمل', 'Completed'), O('cancelled', 'ملغي', 'Cancelled'), O('refunded', 'مسترد', 'Refunded'),
]
export const PAYMENT_STATUSES: Option[] = [
  O('pending', 'معلّق', 'Pending'), O('succeeded', 'ناجح', 'Succeeded'), O('failed', 'فاشل', 'Failed'), O('partially_refunded', 'مسترد جزئيًا', 'Partially refunded'), O('refunded', 'مسترد', 'Refunded'),
]
const METHODS: Option[] = [O('online', 'دفع إلكتروني', 'Online payment'), O('whatsapp', 'واتساب / مبيعات', 'WhatsApp / sales-assisted')]
export const label = (opts: Option[], v: string, l: 'ar' | 'en') => opts.find((o) => o.value === v)?.[`label_${l}`] ?? v

export const orders: ResourceConfig = {
  path: 'orders', module: 'orders', title: L('إدارة الطلبات', 'Orders'), readOnly: true, exportCsv: true, detailPath: '/admin/orders',
  columns: [
    { key: 'number', label: L('رقم الطلب', 'Order'), sortable: true },
    { key: 'customer_name', label: L('العميل', 'Customer'), sortable: true },
    { key: 'method', label: L('الطريقة', 'Method'), render: (r, l) => label(METHODS, r.method, l) },
    { key: 'total', label: L('الإجمالي', 'Total'), sortable: true, render: (r) => `${r.total} ${r.currency_code}` },
    { key: 'status', label: L('الحالة', 'Status'), sortable: true, render: (r, l) => label(ORDER_STATUSES, r.status, l) },
    { key: 'created_at', label: L('التاريخ', 'Date'), sortable: true, render: (r, l) => fmtDate(r.created_at, l) },
  ],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: ORDER_STATUSES }, { name: 'method', label: L('الطريقة', 'Method'), options: METHODS },
    { name: 'currency_code', label: L('العملة', 'Currency'), optionsFrom: '/admin/currencies/options' }],
  fields: [], defaultSort: '-id',
}

export const payments: ResourceConfig = {
  path: 'payments', module: 'payments', title: L('المدفوعات والمعاملات', 'Payments & transactions'), readOnly: true, exportCsv: true,
  columns: [
    { key: 'id', label: L('#', '#'), sortable: true },
    { key: 'provider', label: L('المزوّد', 'Provider') },
    { key: 'provider_reference', label: L('المرجع', 'Reference') },
    { key: 'amount', label: L('المبلغ', 'Amount'), sortable: true, render: (r) => `${r.amount} ${r.currency_code}` },
    { key: 'refunded_total', label: L('المسترد', 'Refunded'), render: (r) => r.refunded_total },
    { key: 'status', label: L('الحالة', 'Status'), sortable: true, render: (r, l) => label(PAYMENT_STATUSES, r.status, l) },
    { key: 'created_at', label: L('التاريخ', 'Date'), sortable: true, render: (r, l) => fmtDate(r.created_at, l) },
  ],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: PAYMENT_STATUSES }, { name: 'currency_code', label: L('العملة', 'Currency'), optionsFrom: '/admin/currencies/options' }],
  fields: [], defaultSort: '-id',
}

const SOURCES = ['instagram', 'facebook', 'google', 'website', 'whatsapp', 'referral', 'direct', 'other'].map((s) => O(s, s, s))
export const customers: ResourceConfig = {
  path: 'customers', module: 'customers', title: L('العملاء', 'Customers'), exportCsv: true,
  columns: [
    { key: 'name', label: L('الاسم', 'Name'), sortable: true }, { key: 'company', label: L('الشركة', 'Company') }, { key: 'email', label: L('البريد', 'Email') },
    { key: 'phone', label: L('الهاتف', 'Phone') }, { key: 'country_code', label: L('الدولة', 'Country') },
  ],
  filters: [{ name: 'country_code', label: L('الدولة', 'Country'), optionsFrom: '/admin/countries/options' }, { name: 'source', label: L('المصدر', 'Source'), options: SOURCES }],
  fields: [
    { name: 'name', label: L('الاسم', 'Name'), type: 'text', required: true }, { name: 'company', label: L('الشركة', 'Company'), type: 'text' },
    { name: 'email', label: L('البريد الإلكتروني', 'Email'), type: 'text', dir: 'ltr' }, { name: 'phone', label: L('الهاتف', 'Phone'), type: 'text', dir: 'ltr' },
    { name: 'country_code', label: L('الدولة', 'Country'), type: 'select', optionsFrom: '/admin/countries/options' },
    { name: 'industry', label: L('المجال', 'Industry'), type: 'text' },
    { name: 'source', label: L('المصدر', 'Source'), type: 'select', options: SOURCES },
    { name: 'notes', label: L('ملاحظات', 'Notes'), type: 'textarea' },
  ],
}
