import type { ResourceConfig, Option, Bi } from '@/components/crud/types'
import { fmtDate } from '@/i18n'

const L = (ar: string, en: string): Bi => ({ ar, en })
const O = (value: string, ar: string, en: string): Option => ({ value, label_ar: ar, label_en: en })

export const LEAD_STATUSES: Option[] = [
  O('new', 'جديد', 'New'), O('contacted', 'تم التواصل', 'Contacted'), O('qualified', 'مؤهَّل', 'Qualified'), O('proposal_sent', 'تم إرسال العرض', 'Proposal sent'),
  O('negotiation', 'تفاوض', 'Negotiation'), O('won', 'مكسوب', 'Won'), O('lost', 'خاسر', 'Lost'),
]
export const SOURCES: Option[] = [
  O('instagram', 'إنستجرام', 'Instagram'), O('facebook', 'فيسبوك', 'Facebook'), O('google', 'جوجل', 'Google'), O('website', 'الموقع', 'Website'),
  O('whatsapp', 'واتساب', 'WhatsApp'), O('referral', 'ترشيح', 'Referral'), O('direct', 'مباشر', 'Direct'), O('other', 'أخرى', 'Other'),
]
export const PRIORITIES: Option[] = [O('low', 'منخفضة', 'Low'), O('normal', 'عادية', 'Normal'), O('high', 'عالية', 'High'), O('urgent', 'عاجلة', 'Urgent')]
export const label = (opts: Option[], v: string, l: 'ar' | 'en') => opts.find((o) => o.value === v)?.[`label_${l}`] ?? v

export const leads: ResourceConfig = {
  path: 'leads', module: 'leads', title: L('العملاء المحتملون', 'Leads'), exportCsv: true, bulk: ['delete'], detailPath: '/admin/leads', defaultSort: '-id',
  columns: [
    { key: 'name', label: L('الاسم', 'Name'), sortable: true },
    { key: 'company', label: L('الشركة', 'Company') },
    { key: 'source', label: L('المصدر', 'Source'), render: (r, l) => label(SOURCES, r.source, l) },
    { key: 'priority', label: L('الأولوية', 'Priority'), sortable: true, render: (r, l) => label(PRIORITIES, r.priority, l) },
    { key: 'status', label: L('الحالة', 'Status'), sortable: true, render: (r, l) => label(LEAD_STATUSES, r.status, l) },
    { key: 'next_followup_at', label: L('المتابعة القادمة', 'Next follow-up'), render: (r, l) => (r.next_followup_at ? fmtDate(r.next_followup_at, l) : '—') },
    { key: 'created_at', label: L('التاريخ', 'Created'), sortable: true, render: (r, l) => fmtDate(r.created_at, l) },
  ],
  filters: [
    { name: 'status', label: L('الحالة', 'Status'), options: LEAD_STATUSES }, { name: 'source', label: L('المصدر', 'Source'), options: SOURCES },
    { name: 'priority', label: L('الأولوية', 'Priority'), options: PRIORITIES }, { name: 'assigned_to', label: L('المسؤول', 'Assignee'), optionsFrom: '/admin/users/options' },
    { name: 'service_id', label: L('الخدمة', 'Service'), optionsFrom: '/admin/services/options' },
  ],
  fields: [
    { name: 'name', label: L('الاسم', 'Name'), type: 'text', required: true }, { name: 'company', label: L('الشركة', 'Company'), type: 'text' },
    { name: 'email', label: L('البريد الإلكتروني', 'Email'), type: 'text', dir: 'ltr', hint: L('البريد أو الهاتف مطلوب', 'Email or phone is required') },
    { name: 'phone', label: L('الهاتف', 'Phone'), type: 'text', dir: 'ltr' },
    { name: 'country_code', label: L('الدولة', 'Country'), type: 'select', optionsFrom: '/admin/countries/options' }, { name: 'industry', label: L('المجال', 'Industry'), type: 'text' },
    { name: 'source', label: L('المصدر', 'Source'), type: 'select', options: SOURCES, required: true },
    { name: 'service_id', label: L('الخدمة المطلوبة', 'Requested service'), type: 'select', optionsFrom: '/admin/services/options' },
    { name: 'budget', label: L('الميزانية', 'Budget'), type: 'text' }, { name: 'timeline', label: L('الإطار الزمني', 'Timeline'), type: 'text' },
    { name: 'contact_method', label: L('طريقة التواصل المفضلة', 'Preferred contact'), type: 'select', options: [O('email', 'البريد', 'Email'), O('phone', 'الهاتف', 'Phone'), O('whatsapp', 'واتساب', 'WhatsApp')] },
    { name: 'priority', label: L('الأولوية', 'Priority'), type: 'select', options: PRIORITIES, required: true },
    { name: 'assigned_to', label: L('المسؤول', 'Assigned to'), type: 'select', optionsFrom: '/admin/users/options' },
    { name: 'description', label: L('الوصف', 'Description'), type: 'textarea' },
  ],
  defaults: { source: 'direct', priority: 'normal' },
}
