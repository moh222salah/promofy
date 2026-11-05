import { useState } from 'react'
import { NavLink, Navigate, Outlet, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import clsx from 'clsx'
import { LayoutDashboard, Briefcase, Layers, LogOut, Languages, Moon, Sun, PanelLeftClose, PanelLeftOpen, type LucideIcon } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { applyLocale, type Locale } from '@/i18n'
import { applyTheme, initialTheme, type Theme } from '@/lib/theme'
import { Spinner } from '@/components/ui'

export interface NavItem { to: string; label: string; icon: LucideIcon; permission: string }
/** Only modules that exist are listed — new admin modules register here as they are built. */
export const ADMIN_NAV: NavItem[] = [
  { to: '/admin', label: 'nav.dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  { to: '/admin/services', label: 'nav.services', icon: Briefcase, permission: 'services.view' },
  { to: '/admin/service-categories', label: 'nav.serviceCategories', icon: Layers, permission: 'services.view' },
]

export default function AdminLayout() {
  const { user, loading, can, logout } = useAuth()
  const { t, i18n } = useTranslation()
  const nav = useNavigate()
  const [collapsed, setCollapsed] = useState(false)
  const [theme, setTheme] = useState<Theme>(() => { const th = initialTheme(); applyTheme(th); return th })

  if (loading) return <Spinner />
  if (!user) return <Navigate to="/admin/login" replace />
  if (user.type !== 'staff') return <Navigate to="/admin/login" replace state={{ notStaff: true }} />

  const items = ADMIN_NAV.filter((i) => can(i.permission))
  const other: Locale = i18n.language === 'ar' ? 'en' : 'ar'
  const Collapse = collapsed ? PanelLeftOpen : PanelLeftClose

  return (
    <div className="flex min-h-screen">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:start-2 focus:top-2 focus:z-50 focus:rounded focus:bg-surface focus:p-2">{t('nav.skip')}</a>
      <aside className={clsx('sticky top-0 flex h-screen flex-col border-e border-line bg-surface transition-[width]', collapsed ? 'w-16' : 'w-64')}>
        <div className="flex h-14 items-center gap-2 border-b border-line px-3">
          <img src="/logo.png" alt="" className="h-8 w-8 rounded-full" />
          {!collapsed && <span className="font-extrabold tracking-wide text-brand">PROMOFY</span>}
        </div>
        <nav aria-label="Admin" className="flex-1 space-y-1 overflow-y-auto p-2">
          {items.map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to} end title={t(label)} className={({ isActive }) => clsx('flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium', isActive ? 'bg-brand text-white dark:text-bg' : 'text-ink hover:bg-surface-2')}>
              <Icon size={18} aria-hidden />{!collapsed && <span>{t(label)}</span>}
            </NavLink>
          ))}
        </nav>
        <button onClick={() => setCollapsed((c) => !c)} className="m-2 flex items-center gap-2 rounded-lg p-2 text-sm text-muted hover:bg-surface-2" aria-label={t(collapsed ? 'nav.expand' : 'nav.collapse')}>
          <Collapse size={18} className="rtl:-scale-x-100" aria-hidden />{!collapsed && t('nav.collapse')}
        </button>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 flex h-14 items-center justify-end gap-2 border-b border-line bg-surface/90 px-4 backdrop-blur">
          <button className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm hover:bg-surface-2" onClick={() => applyLocale(other)} aria-label="Language"><Languages size={16} aria-hidden />{t('nav.language')}</button>
          <button className="rounded-lg p-2 hover:bg-surface-2" aria-label={t('nav.theme')} onClick={() => { const n: Theme = theme === 'dark' ? 'light' : 'dark'; applyTheme(n); setTheme(n) }}>
            {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
          </button>
          <span className="hidden px-2 text-sm text-muted sm:inline">{user.name}</span>
          <button className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm hover:bg-surface-2" onClick={async () => { await logout(); nav('/admin/login') }}><LogOut size={16} aria-hidden />{t('auth.logout')}</button>
        </header>
        <main id="main" className="flex-1 p-4 md:p-6"><Outlet /></main>
      </div>
    </div>
  )
}
