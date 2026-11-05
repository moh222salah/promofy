import type { ResourceConfig, Option } from '@/components/crud/types'

const STATUS: Option[] = [
  { value: 'draft', label_ar: 'مسودة', label_en: 'Draft' },
  { value: 'published', label_ar: 'منشور', label_en: 'Published' },
  { value: 'archived', label_ar: 'مؤرشف', label_en: 'Archived' },
]
const PRICING: Option[] = [
  { value: 'quote', label_ar: 'طلب عرض سعر', label_en: 'Request a quote' },
  { value: 'range', label_ar: 'نطاق سعري', label_en: 'Price range' },
  { value: 'fixed', label_ar: 'سعر ثابت', label_en: 'Fixed price' },
]
const CTA: Option[] = [
  { value: 'quote', label_ar: 'طلب عرض سعر', label_en: 'Request a quote' },
  { value: 'whatsapp', label_ar: 'واتساب', label_en: 'WhatsApp' },
  { value: 'contact', label_ar: 'تواصل مع المبيعات', label_en: 'Contact sales' },
  { value: 'consultation', label_ar: 'حجز استشارة', label_en: 'Book a consultation' },
]

export const serviceCategories: ResourceConfig = {
  path: 'service-categories', module: 'services', title: { ar: 'فئات الخدمات', en: 'Service categories' },
  defaultSort: 'sort_order', reorder: true,
  columns: [
    { key: 'name_ar', label: { ar: 'الاسم (عربي)', en: 'Name (AR)' }, sortable: true },
    { key: 'name_en', label: { ar: 'الاسم (إنجليزي)', en: 'Name (EN)' }, sortable: true },
    { key: 'is_active', label: { ar: 'نشطة', en: 'Active' } },
    { key: 'sort_order', label: { ar: 'الترتيب', en: 'Order' }, sortable: true },
  ],
  fields: [
    { name: 'name_ar', label: { ar: 'الاسم (عربي)', en: 'Name (Arabic)' }, type: 'text', required: true },
    { name: 'name_en', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, type: 'text', required: true, dir: 'ltr' },
    { name: 'slug_ar', label: { ar: 'الرابط (عربي)', en: 'Slug (Arabic)' }, type: 'text', hint: { ar: 'يُولَّد تلقائيًا إن تُرك فارغًا', en: 'Generated automatically when empty' } },
    { name: 'slug_en', label: { ar: 'الرابط (إنجليزي)', en: 'Slug (English)' }, type: 'text', dir: 'ltr' },
    { name: 'description_ar', label: { ar: 'الوصف (عربي)', en: 'Description (Arabic)' }, type: 'textarea' },
    { name: 'description_en', label: { ar: 'الوصف (إنجليزي)', en: 'Description (English)' }, type: 'textarea', dir: 'ltr' },
    { name: 'icon', label: { ar: 'الأيقونة', en: 'Icon' }, type: 'text', dir: 'ltr' },
    { name: 'is_active', label: { ar: 'نشطة', en: 'Active' }, type: 'bool' },
  ],
  defaults: { is_active: true },
}

export const services: ResourceConfig = {
  path: 'services', module: 'services', title: { ar: 'إدارة الخدمات', en: 'Services' },
  defaultSort: 'sort_order', workflow: true, duplicate: true, exportCsv: true, reorder: true,
  bulk: ['publish', 'unpublish', 'archive', 'delete'],
  columns: [
    { key: 'name_en', label: { ar: 'الخدمة', en: 'Service' }, sortable: true, render: (r, l) => (l === 'ar' ? r.name_ar : r.name_en) },
    { key: 'category', label: { ar: 'الفئة', en: 'Category' }, render: (r, l) => (l === 'ar' ? r.category?.name_ar : r.category?.name_en) ?? '—' },
    { key: 'pricing_type', label: { ar: 'التسعير', en: 'Pricing' } },
    { key: 'is_featured', label: { ar: 'مميزة', en: 'Featured' } },
    { key: 'status', label: { ar: 'الحالة', en: 'Status' }, sortable: true, badge: true },
  ],
  filters: [
    { name: 'status', label: { ar: 'الحالة', en: 'Status' }, options: STATUS },
    { name: 'service_category_id', label: { ar: 'الفئة', en: 'Category' }, optionsFrom: '/admin/service-categories/options' },
  ],
  fields: [
    { name: 'service_category_id', label: { ar: 'الفئة', en: 'Category' }, type: 'select', optionsFrom: '/admin/service-categories/options' },
    { name: 'name_ar', label: { ar: 'الاسم (عربي)', en: 'Name (Arabic)' }, type: 'text', required: true },
    { name: 'name_en', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, type: 'text', required: true, dir: 'ltr' },
    { name: 'slug_ar', label: { ar: 'الرابط (عربي)', en: 'Slug (Arabic)' }, type: 'text' },
    { name: 'slug_en', label: { ar: 'الرابط (إنجليزي)', en: 'Slug (English)' }, type: 'text', dir: 'ltr' },
    { name: 'short_description_ar', label: { ar: 'وصف مختصر (عربي)', en: 'Short description (Arabic)' }, type: 'textarea' },
    { name: 'short_description_en', label: { ar: 'وصف مختصر (إنجليزي)', en: 'Short description (English)' }, type: 'textarea', dir: 'ltr' },
    { name: 'description_ar', label: { ar: 'الوصف التفصيلي (عربي)', en: 'Description (Arabic)' }, type: 'textarea' },
    { name: 'description_en', label: { ar: 'الوصف التفصيلي (إنجليزي)', en: 'Description (English)' }, type: 'textarea', dir: 'ltr' },
    { name: 'duration_ar', label: { ar: 'المدة التقديرية (عربي)', en: 'Estimated duration (Arabic)' }, type: 'text' },
    { name: 'duration_en', label: { ar: 'المدة التقديرية (إنجليزي)', en: 'Estimated duration (English)' }, type: 'text', dir: 'ltr' },
    { name: 'pricing_type', label: { ar: 'نوع التسعير', en: 'Pricing type' }, type: 'select', options: PRICING, required: true },
    { name: 'currency_code', label: { ar: 'العملة', en: 'Currency' }, type: 'select', optionsFrom: '/admin/currencies/options', showIf: (v) => v.pricing_type !== 'quote', required: true },
    { name: 'price_from', label: { ar: 'السعر من', en: 'Price from' }, type: 'number', showIf: (v) => v.pricing_type !== 'quote', required: true },
    { name: 'price_to', label: { ar: 'السعر إلى', en: 'Price to' }, type: 'number', showIf: (v) => v.pricing_type === 'range', required: true },
    { name: 'cta_type', label: { ar: 'زر الإجراء', en: 'Call to action' }, type: 'select', options: CTA, required: true },
    { name: 'cta_label_ar', label: { ar: 'نص الزر (عربي)', en: 'CTA label (Arabic)' }, type: 'text' },
    { name: 'cta_label_en', label: { ar: 'نص الزر (إنجليزي)', en: 'CTA label (English)' }, type: 'text', dir: 'ltr' },
    { name: 'video_url', label: { ar: 'رابط الفيديو', en: 'Video URL' }, type: 'url' },
    { name: 'status', label: { ar: 'الحالة', en: 'Status' }, type: 'select', options: STATUS, required: true },
    { name: 'is_featured', label: { ar: 'خدمة مميزة', en: 'Featured service' }, type: 'bool' },
    { name: 'seo_title_ar', label: { ar: 'عنوان SEO (عربي)', en: 'SEO title (Arabic)' }, type: 'text' },
    { name: 'seo_title_en', label: { ar: 'عنوان SEO (إنجليزي)', en: 'SEO title (English)' }, type: 'text', dir: 'ltr' },
    { name: 'seo_description_ar', label: { ar: 'وصف SEO (عربي)', en: 'SEO description (Arabic)' }, type: 'textarea' },
    { name: 'seo_description_en', label: { ar: 'وصف SEO (إنجليزي)', en: 'SEO description (English)' }, type: 'textarea', dir: 'ltr' },
    { name: 'seo_noindex', label: { ar: 'منع الفهرسة', en: 'Hide from search engines' }, type: 'bool' },
  ],
  defaults: { pricing_type: 'quote', cta_type: 'quote', status: 'draft', is_featured: false },
}
