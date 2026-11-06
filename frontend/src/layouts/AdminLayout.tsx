import { useEffect, useMemo, useRef, useState } from 'react'
import { NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { LayoutDashboard, Briefcase, Layers, Package, Tags, Images, Newspaper, FolderTree, Quote, Users, Contact, ShoppingCart, FileText, CreditCard, UserRound, LogOut, Languages, Moon, Sun, PanelLeftClose, PanelLeftOpen, Menu, Search, Bell, X, type LucideIcon } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { applyLocale, fmtDate, type Locale } from '@/i18n'
import { applyTheme, initialTheme, type Theme } from '@/lib/theme'
import { api } from '@/lib/api'
import { Spinner } from '@/components/ui'
import Stage from '@/components/fx/Stage'

export interface NavItem { to: string; label: string; icon: LucideIcon; permission: string; group: 'groupOverview' | 'groupSales' | 'groupCatalog' | 'groupContent' }
/** Only modules that exist are listed — new admin modules register here as they are built. */
export const ADMIN_NAV: NavItem[] = [
  { to: '/admin', label: 'nav.dashboard', icon: LayoutDashboard, permission: 'dashboard.view', group: 'groupOverview' },
  { to: '/admin/leads', label: 'nav.leads', icon: Contact, permission: 'leads.view|leads.view_assigned', group: 'groupSales' },
  { to: '/admin/orders', label: 'nav.orders', icon: ShoppingCart, permission: 'orders.view', group: 'groupSales' },
  { to: '/admin/quotations', label: 'nav.quotations', icon: FileText, permission: 'quotations.view|quotations.view_assigned', group: 'groupSales' },
  { to: '/admin/payments', label: 'nav.payments', icon: CreditCard, permission: 'payments.view', group: 'groupSales' },
  { to: '/admin/customers', label: 'nav.customers', icon: UserRound, permission: 'customers.view', group: 'groupSales' },
  { to: '/admin/services', label: 'nav.services', icon: Briefcase, permission: 'services.view', group: 'groupCatalog' },
  { to: '/admin/service-categories', label: 'nav.serviceCategories', icon: Layers, permission: 'services.view', group: 'groupCatalog' },
  { to: '/admin/products', label: 'nav.products', icon: Package, permission: 'products.view', group: 'groupCatalog' },
  { to: '/admin/product-categories', label: 'nav.productCategories', icon: Tags, permission: 'product_categories.view', group: 'groupCatalog' },
  { to: '/admin/portfolio', label: 'nav.portfolio', icon: Images, permission: 'portfolio.view', group: 'groupContent' },
  { to: '/admin/articles', label: 'nav.articles', icon: Newspaper, permission: 'articles.view', group: 'groupContent' },
  { to: '/admin/article-categories', label: 'nav.articleCategories', icon: FolderTree, permission: 'articles.view', group: 'groupContent' },
  { to: '/admin/testimonials', label: 'nav.testimonials', icon: Quote, permission: 'testimonials.view', group: 'groupContent' },
  { to: '/admin/team', label: 'nav.team', icon: Users, permission: 'team.view', group: 'groupContent' },
]

interface Notif { id: string; read: boolean; created_at: string; subject_ar?: string; subject_en?: string; body_ar?: string; body_en?: string }

function Bell_() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const count = useQuery({ queryKey: ['notif-count'], queryFn: () => api.get<any>('/admin/notifications/unread-count'), refetchInterval: 60_000 })
  const list = useQuery({ queryKey: ['notif-list'], queryFn: () => api.get<any>('/admin/notifications?per_page=8'), enabled: open })
  const unread = Number(count.data?.data?.unread ?? count.data?.unread ?? count.data?.count ?? 0)
  useEffect(() => {
    if (!open) return
    const h = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false) }
    const k = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', h); document.addEventListener('keydown', k)
    return () => { document.removeEventListener('mousedown', h); document.removeEventListener('keydown', k) }
  }, [open])
  const items: Notif[] = list.data?.data ?? []
  return (
    <div className="relative" ref={ref}>
      <button className="icon-btn relative" aria-label={t('nav.notifications')} aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <Bell size={17} />{unread > 0 && <span className="absolute -end-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-bold text-white">{unread > 9 ? '9+' : unread}</span>}
      </button>
      {open && (
        <div className="glass page-in absolute end-0 top-full z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl p-2">
          <div className="flex items-center justify-between px-2 py-1 text-sm font-semibold"><span>{t('nav.notifications')}</span>
            {unread > 0 && <button className="text-xs text-brand hover:underline" onClick={async () => { await api.post('/admin/notifications/read-all'); qc.invalidateQueries({ queryKey: ['notif-count'] }); qc.invalidateQueries({ queryKey: ['notif-list'] }) }}>{t('nav.markAll')}</button>}
          </div>
          <ul className="max-h-80 overflow-y-auto">
            {list.isLoading && <li className="p-3"><div className="skeleton h-10" /></li>}
            {!list.isLoading && items.length === 0 && <li className="p-4 text-center text-sm text-muted">{t('nav.noNotifications')}</li>}
            {items.map((n) => (
              <li key={n.id} className={clsx('rounded-xl p-2 text-sm hover:bg-lit/10', !n.read && 'bg-lit/10')}>
                <div className="flex items-center gap-2 font-medium">{!n.read && <span className="live-dot shrink-0" />}{(ar ? n.subject_ar : n.subject_en) || n.subject_en || n.subject_ar}</div>
                <div className="line-clamp-2 text-xs text-muted">{(ar ? n.body_ar : n.body_en) || n.body_en || n.body_ar}</div>
                <div className="mt-0.5 text-[11px] text-muted">{fmtDate(n.created_at)}</div>
              </li>))}
          </ul>
        </div>
      )}
    </div>
  )
}

function Palette({ open, onClose, items, actions }: { open: boolean; onClose: () => void; items: NavItem[]; actions: Array<{ label: string; icon: LucideIcon; run: () => void }> }) {
  const { t } = useTranslation()
  const nav = useNavigate()
  const [q, setQ] = useState(''); const [idx, setIdx] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)
  useEffect(() => { if (open) { setQ(''); setIdx(0); setTimeout(() => inputRef.current?.focus(), 0) } }, [open])
  const rows = useMemo(() => {
    const all = [...items.map((i) => ({ label: t(i.label), icon: i.icon, run: () => nav(i.to) })), ...actions]
    const s = q.trim().toLowerCase()
    return s ? all.filter((r) => r.label.toLowerCase().includes(s)) : all
  }, [q, items, actions, t, nav])
  if (!open) return null
  const go = (i: number) => { rows[i]?.run(); onClose() }
  return (
    <div className="fixed inset-0 z-50 grid place-items-start justify-center bg-black/50 p-4 pt-[12vh] backdrop-blur-sm" onMouseDown={onClose}>
      <div role="dialog" aria-modal="true" aria-label={t('nav.search')} className="glass page-in w-full max-w-lg overflow-hidden rounded-2xl" onMouseDown={(e) => e.stopPropagation()}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose(); else if (e.key === 'ArrowDown') { e.preventDefault(); setIdx((i) => Math.min(i + 1, rows.length - 1)) } else if (e.key === 'ArrowUp') { e.preventDefault(); setIdx((i) => Math.max(i - 1, 0)) } else if (e.key === 'Enter') go(idx) }}>
        <div className="flex items-center gap-2 border-b border-line px-3"><Search size={16} className="text-muted" />
          <input ref={inputRef} className="w-full bg-transparent py-3 text-sm outline-none" placeholder={t('nav.search')} value={q} onChange={(e) => { setQ(e.target.value); setIdx(0) }} /></div>
        <ul className="max-h-72 overflow-y-auto p-1">
          {rows.length === 0 && <li className="p-4 text-center text-sm text-muted">{t('nav.noResults')}</li>}
          {rows.map((r, i) => <li key={r.label}><button className={clsx('flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm', i === idx ? 'bg-lit/20' : 'hover:bg-lit/10')} onMouseEnter={() => setIdx(i)} onClick={() => go(i)}><r.icon size={16} aria-hidden />{r.label}</button></li>)}
        </ul>
      </div>
    </div>
  )
}

export default function AdminLayout() {
  const { user, loading, can, logout } = useAuth()
  const { t, i18n } = useTranslation()
  const nav = useNavigate()
  const loc = useLocation()
  const [collapsed, setCollapsed] = useState(false)
  const [drawer, setDrawer] = useState(false)
  const [pal, setPal] = useState(false)
  const [theme, setTheme] = useState<Theme>(() => { const th = initialTheme(); applyTheme(th); return th })

  useEffect(() => { setDrawer(false) }, [loc.pathname])
  useEffect(() => {
    const k = (e: KeyboardEvent) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPal((p) => !p) } }
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k)
  }, [])

  if (loading) return <Spinner />
  if (!user) return <Navigate to="/admin/login" replace />
  if (user.type !== 'staff') return <Navigate to="/admin/login" replace state={{ notStaff: true }} />

  const items = ADMIN_NAV.filter((i) => i.permission.split('|').some(can))
  const other: Locale = i18n.language === 'ar' ? 'en' : 'ar'
  const Collapse = collapsed ? PanelLeftOpen : PanelLeftClose
  const toggleTheme = () => { const n: Theme = theme === 'dark' ? 'light' : 'dark'; applyTheme(n); setTheme(n) }
  const doLogout = async () => { await logout(); nav('/admin/login') }
  const groups = Array.from(new Set(items.map((i) => i.group)))
  const mini = collapsed && !drawer

  return (
    <div className="flex min-h-screen">
      <Stage />
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:start-2 focus:top-2 focus:z-50 focus:rounded focus:bg-surface focus:p-2">{t('nav.skip')}</a>
      {drawer && <div className="fixed inset-0 z-30 bg-black/50 backdrop-blur-sm md:hidden" onClick={() => setDrawer(false)} aria-hidden />}
      <aside className={clsx('glass fixed inset-y-0 start-0 z-40 flex flex-col rounded-none border-y-0 border-s-0 transition-transform duration-300 md:sticky md:top-0 md:h-screen md:translate-x-0 md:transition-[width]',
        mini ? 'md:w-[4.5rem]' : 'md:w-64', 'w-72', drawer ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full md:rtl:translate-x-0')}>
        <div className="flex h-16 items-center gap-3 border-b border-line/60 px-4">
          <img src="/logo.png" alt="" className="h-9 w-9 rounded-full shadow-[0_0_18px_rgb(var(--c-lit)/.7)]" />
          {!mini && <span className="display text-lg font-extrabold tracking-[0.18em] title-gradient">PROMOFY</span>}
          <button className="icon-btn ms-auto md:hidden" aria-label={t('nav.close')} onClick={() => setDrawer(false)}><X size={18} /></button>
        </div>
        <nav aria-label="Admin" className="flex-1 space-y-4 overflow-y-auto p-3">
          {groups.map((g) => (
            <div key={g} className="space-y-1">
              {!mini && <div className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-muted/80">{t(`nav.${g}`)}</div>}
              {items.filter((i) => i.group === g).map(({ to, label, icon: Icon }) => (
                <NavLink key={to} to={to} end title={t(label)} className={({ isActive }) => clsx('navlink', isActive && 'active', mini && 'justify-center')}>
                  <Icon size={18} aria-hidden />{!mini && <span className="truncate">{t(label)}</span>}
                </NavLink>))}
            </div>))}
        </nav>
        <button onClick={() => setCollapsed((c) => !c)} className="navlink m-3 hidden md:flex" aria-label={t(collapsed ? 'nav.expand' : 'nav.collapse')}>
          <Collapse size={18} className="rtl:-scale-x-100" aria-hidden />{!mini && t('nav.collapse')}
        </button>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="glass sticky top-0 z-20 flex h-16 items-center gap-2 rounded-none border-x-0 border-t-0 px-3 md:px-5">
          <button className="icon-btn md:hidden" aria-label={t('nav.menu')} onClick={() => setDrawer(true)}><Menu size={19} /></button>
          <button className="field flex min-w-0 flex-1 items-center gap-2 px-3 py-2 text-sm text-muted md:max-w-sm md:flex-none md:basis-80" onClick={() => setPal(true)} aria-label={t('nav.search')}>
            <Search size={15} aria-hidden /><span className="truncate">{t('nav.search')}</span><kbd className="ms-auto hidden rounded border border-line px-1.5 text-[10px] md:inline">Ctrl K</kbd>
          </button>
          <div className="ms-auto flex items-center gap-1">
            <button className="btn btn-ghost !px-2" onClick={() => applyLocale(other)} aria-label="Language"><Languages size={16} aria-hidden /><span className="hidden sm:inline">{t('nav.language')}</span></button>
            <button className="icon-btn" aria-label={t('nav.theme')} onClick={toggleTheme}>{theme === 'dark' ? <Sun size={17} /> : <Moon size={17} />}</button>
            <Bell_ />
            <span className="mx-1 hidden items-center gap-2 lg:flex"><span className="badge3d !h-8 !w-8 !rounded-full text-xs font-bold">{user.name?.[0]?.toUpperCase()}</span><span className="text-sm">{user.name}</span></span>
            <button className="icon-btn danger" aria-label={t('auth.logout')} title={t('auth.logout')} onClick={doLogout}><LogOut size={17} /></button>
          </div>
        </header>
        <main id="main" key={loc.pathname} className="page-in flex-1 p-3 sm:p-4 md:p-6"><Outlet /></main>
      </div>
      <Palette open={pal} onClose={() => setPal(false)} items={items} actions={[
        { label: t('nav.theme'), icon: theme === 'dark' ? Sun : Moon, run: toggleTheme },
        { label: t('nav.language'), icon: Languages, run: () => applyLocale(other) },
        { label: t('auth.logout'), icon: LogOut, run: doLogout },
      ]} />
    </div>
  )
}
