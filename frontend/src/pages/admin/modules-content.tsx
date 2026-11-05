import type { FieldDef, ResourceConfig, Option, Bi } from '@/components/crud/types'

const STATUS: Option[] = [
  { value: 'draft', label_ar: 'مسودة', label_en: 'Draft' },
  { value: 'published', label_ar: 'منشور', label_en: 'Published' },
  { value: 'archived', label_ar: 'مؤرشف', label_en: 'Archived' },
]
const PRODUCT_TYPES: Option[] = [
  { value: 'digital_download', label_ar: 'تحميل رقمي', label_en: 'Digital download' },
  { value: 'template', label_ar: 'قالب تسويقي', label_en: 'Marketing template' },
  { value: 'creative_package', label_ar: 'باقة إبداعية', label_en: 'Creative package' },
  { value: 'fixed_package', label_ar: 'باقة بسعر ثابت', label_en: 'Fixed-price package' },
  { value: 'subscription', label_ar: 'اشتراك', label_en: 'Subscription' },
  { value: 'custom', label_ar: 'منتج مخصص', label_en: 'Custom product' },
]

const L = (ar: string, en: string): Bi => ({ ar, en })
/** Arabic + English variants of one field. */
const pair = (base: string, ar: string, en: string, type: FieldDef['type'] = 'text', extra: Partial<FieldDef> = {}): FieldDef[] => [
  { name: `${base}_ar`, label: L(`${ar} (عربي)`, `${en} (Arabic)`), type, ...extra },
  { name: `${base}_en`, label: L(`${ar} (إنجليزي)`, `${en} (English)`), type, dir: 'ltr', ...extra, required: extra.required },
]
const seo: FieldDef[] = [
  ...pair('seo_title', 'عنوان SEO', 'SEO title'),
  ...pair('seo_description', 'وصف SEO', 'SEO description', 'textarea'),
  { name: 'seo_noindex', label: L('منع الفهرسة', 'Hide from search engines'), type: 'bool' },
]
const statusField: FieldDef = { name: 'status', label: L('الحالة', 'Status'), type: 'select', options: STATUS, required: true }
const nameCol = (key: string, ar: string, en: string, base: string) => ({ key, label: L(ar, en), sortable: true, render: (r: any, l: 'ar' | 'en') => r[`${base}_${l}`] ?? r[`${base}_en`] })
const statusCol = { key: 'status', label: L('الحالة', 'Status'), sortable: true, badge: true }

export const productCategories: ResourceConfig = {
  path: 'product-categories', module: 'product_categories', title: L('فئات المنتجات', 'Product categories'), defaultSort: 'sort_order', reorder: true,
  columns: [nameCol('name_en', 'الفئة', 'Category', 'name'), { key: 'is_active', label: L('نشطة', 'Active') }, { key: 'sort_order', label: L('الترتيب', 'Order'), sortable: true }],
  fields: [...pair('name', 'الاسم', 'Name', 'text', { required: true }), ...pair('description', 'الوصف', 'Description', 'textarea'), { name: 'is_active', label: L('نشطة', 'Active'), type: 'bool' }],
  defaults: { is_active: true },
}

export const products: ResourceConfig = {
  path: 'products', module: 'products', title: L('إدارة المنتجات', 'Products'), workflow: true, duplicate: true, exportCsv: true, bulk: ['publish', 'unpublish', 'archive', 'delete'],
  columns: [
    nameCol('name_en', 'المنتج', 'Product', 'name'),
    { key: 'sku', label: L('SKU', 'SKU'), sortable: true },
    { key: 'type', label: L('النوع', 'Type'), render: (r, l) => PRODUCT_TYPES.find((t) => t.value === r.type)?.[`label_${l}`] ?? r.type },
    { key: 'price', label: L('السعر', 'Price'), render: (r) => `${r.discount_price ?? r.price} ${r.currency_code}` },
    { key: 'stock_quantity', label: L('المخزون', 'Stock'), render: (r, l) => (r.track_stock ? r.stock_quantity : l === 'ar' ? 'غير محدود' : 'Unlimited') },
    statusCol,
  ],
  filters: [
    { name: 'status', label: L('الحالة', 'Status'), options: STATUS },
    { name: 'type', label: L('النوع', 'Type'), options: PRODUCT_TYPES },
    { name: 'product_category_id', label: L('الفئة', 'Category'), optionsFrom: '/admin/product-categories/options' },
  ],
  fields: [
    { name: 'type', label: L('نوع المنتج', 'Product type'), type: 'select', options: PRODUCT_TYPES, required: true },
    { name: 'product_category_id', label: L('الفئة', 'Category'), type: 'select', optionsFrom: '/admin/product-categories/options' },
    { name: 'sku', label: L('SKU', 'SKU'), type: 'text', required: true, dir: 'ltr' },
    ...pair('name', 'الاسم', 'Name', 'text', { required: true }),
    ...pair('slug', 'الرابط', 'Slug'),
    ...pair('short_description', 'وصف مختصر', 'Short description', 'textarea'),
    ...pair('description', 'الوصف الكامل', 'Full description', 'textarea'),
    { name: 'price', label: L('السعر', 'Price'), type: 'number', required: true },
    { name: 'discount_price', label: L('سعر الخصم', 'Discount price'), type: 'number' },
    { name: 'currency_code', label: L('العملة', 'Currency'), type: 'select', optionsFrom: '/admin/currencies/options', required: true },
    { name: 'subscription_interval', label: L('دورة الاشتراك', 'Billing interval'), type: 'select', showIf: (v) => v.type === 'subscription',
      options: [{ value: 'monthly', label_ar: 'شهري', label_en: 'Monthly' }, { value: 'yearly', label_ar: 'سنوي', label_en: 'Yearly' }] },
    { name: 'track_stock', label: L('تتبّع المخزون', 'Track stock'), type: 'bool' },
    { name: 'stock_quantity', label: L('الكمية المتاحة', 'Stock quantity'), type: 'number', showIf: (v) => !!v.track_stock },
    { name: 'low_stock_threshold', label: L('حد التنبيه بانخفاض المخزون', 'Low-stock alert at'), type: 'number', showIf: (v) => !!v.track_stock },
    { name: 'allow_backorder', label: L('السماح بالطلب المسبق عند نفاد المخزون', 'Allow backorders'), type: 'bool', showIf: (v) => !!v.track_stock },
    { name: 'allow_online_payment', label: L('السماح بالدفع الإلكتروني', 'Allow online payment'), type: 'bool' },
    { name: 'allow_whatsapp_order', label: L('السماح بالطلب عبر واتساب', 'Allow WhatsApp order'), type: 'bool' },
    { name: 'video_url', label: L('رابط الفيديو', 'Video URL'), type: 'url' },
    statusField,
    { name: 'is_featured', label: L('منتج مميز', 'Featured'), type: 'bool' },
    ...seo,
  ],
  defaults: { type: 'digital_download', status: 'draft', allow_online_payment: true, allow_whatsapp_order: true, track_stock: false, is_featured: false },
}

export const portfolio: ResourceConfig = {
  path: 'portfolio', module: 'portfolio', title: L('معرض الأعمال', 'Portfolio'), defaultSort: 'sort_order', workflow: true, duplicate: true, reorder: true, bulk: ['publish', 'unpublish', 'archive', 'delete'],
  columns: [nameCol('title_en', 'المشروع', 'Project', 'title'), { key: 'client_name', label: L('العميل', 'Client') }, { key: 'is_featured', label: L('مميز', 'Featured') }, statusCol],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: STATUS }, { name: 'service_category_id', label: L('الفئة', 'Category'), optionsFrom: '/admin/service-categories/options' }],
  fields: [
    { name: 'service_category_id', label: L('الفئة', 'Category'), type: 'select', optionsFrom: '/admin/service-categories/options' },
    ...pair('title', 'العنوان', 'Title', 'text', { required: true }),
    ...pair('slug', 'الرابط', 'Slug'),
    { name: 'client_name', label: L('اسم العميل', 'Client name'), type: 'text' },
    { name: 'client_approved', label: L('العميل وافق على عرض اسمه', 'Client approved showing their name'), type: 'bool', hint: L('لا يظهر اسم العميل للعامة دون موافقته', 'The client name stays hidden publicly without approval') },
    ...pair('summary', 'ملخص', 'Summary', 'textarea'),
    ...pair('brief', 'تفاصيل المشروع', 'Project brief', 'textarea'),
    { name: 'video_url', label: L('رابط الفيديو', 'Video URL'), type: 'url' },
    { name: 'completed_at', label: L('تاريخ الإنجاز', 'Completed on'), type: 'date' },
    statusField,
    { name: 'is_featured', label: L('مشروع مميز', 'Featured'), type: 'bool' },
    ...seo,
  ],
  defaults: { status: 'draft', is_featured: false, client_approved: false },
}

export const articleCategories: ResourceConfig = {
  path: 'article-categories', module: 'articles', title: L('تصنيفات المقالات', 'Article categories'), defaultSort: 'sort_order', reorder: true,
  columns: [nameCol('name_en', 'التصنيف', 'Category', 'name'), { key: 'is_active', label: L('نشط', 'Active') }, { key: 'sort_order', label: L('الترتيب', 'Order'), sortable: true }],
  fields: [...pair('name', 'الاسم', 'Name', 'text', { required: true }), ...pair('slug', 'الرابط', 'Slug'), { name: 'is_active', label: L('نشط', 'Active'), type: 'bool' }],
  defaults: { is_active: true },
}

export const articles: ResourceConfig = {
  path: 'articles', module: 'articles', title: L('المدونة والرؤى', 'Blog & Insights'), workflow: true, duplicate: true, bulk: ['publish', 'unpublish', 'archive', 'delete'],
  columns: [nameCol('title_en', 'المقال', 'Article', 'title'), { key: 'is_featured', label: L('مميز', 'Featured') }, statusCol],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: STATUS }, { name: 'article_category_id', label: L('التصنيف', 'Category'), optionsFrom: '/admin/article-categories/options' }],
  fields: [
    { name: 'article_category_id', label: L('التصنيف', 'Category'), type: 'select', optionsFrom: '/admin/article-categories/options' },
    ...pair('title', 'العنوان', 'Title', 'text', { required: true }),
    ...pair('slug', 'الرابط', 'Slug'),
    ...pair('excerpt', 'مقتطف', 'Excerpt', 'textarea'),
    ...pair('body', 'المحتوى', 'Body', 'textarea'),
    { name: 'reading_minutes', label: L('وقت القراءة (دقائق)', 'Reading time (min)'), type: 'number' },
    { name: 'video_url', label: L('رابط الفيديو', 'Video URL'), type: 'url' },
    statusField,
    { name: 'is_featured', label: L('مقال مميز', 'Featured'), type: 'bool' },
    ...seo,
  ],
  defaults: { status: 'draft', is_featured: false },
}

export const testimonials: ResourceConfig = {
  path: 'testimonials', module: 'testimonials', title: L('آراء العملاء', 'Testimonials'), defaultSort: 'sort_order', workflow: true, duplicate: true, reorder: true,
  columns: [{ key: 'author_name', label: L('الاسم', 'Author'), sortable: true }, { key: 'company', label: L('الشركة', 'Company') }, { key: 'consent_given', label: L('موافقة العميل', 'Consent') }, statusCol],
  filters: [{ name: 'status', label: L('الحالة', 'Status'), options: STATUS }],
  fields: [
    { name: 'author_name', label: L('اسم العميل', 'Author name'), type: 'text', required: true },
    { name: 'company', label: L('الشركة', 'Company'), type: 'text' },
    ...pair('author_role', 'المسمى الوظيفي', 'Role'),
    ...pair('quote', 'الاقتباس', 'Quote', 'textarea'),
    { name: 'rating', label: L('التقييم (1-5)', 'Rating (1-5)'), type: 'number' },
    { name: 'consent_given', label: L('العميل وافق على نشر رأيه', 'Customer consented to publish'), type: 'bool', hint: L('مطلوب قبل النشر', 'Required before publishing') },
    statusField,
    { name: 'is_featured', label: L('مميز', 'Featured'), type: 'bool' },
  ],
  defaults: { status: 'draft', consent_given: false, is_featured: false },
}

export const team: ResourceConfig = {
  path: 'team', module: 'team', title: L('فريق العمل', 'Team'), defaultSort: 'sort_order', duplicate: true, reorder: true,
  columns: [nameCol('name_en', 'الاسم', 'Name', 'name'), nameCol('title_en', 'المسمى', 'Title', 'title'), { key: 'is_active', label: L('ظاهر', 'Visible') }],
  fields: [...pair('name', 'الاسم', 'Name', 'text', { required: true }), ...pair('title', 'المسمى الوظيفي', 'Job title'), ...pair('bio', 'نبذة', 'Bio', 'textarea'), { name: 'is_active', label: L('ظاهر في الموقع', 'Visible on the site'), type: 'bool' }],
  defaults: { is_active: true },
}
