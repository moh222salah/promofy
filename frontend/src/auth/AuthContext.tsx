import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, ensureCsrf, setUnauthenticatedHandler } from '@/lib/api'

export interface User {
  id: number; name: string; email: string; type: 'staff' | 'customer'; locale: string | null
  roles: { slug: string; name_ar: string; name_en: string }[]; permissions: string[]
  two_factor_enabled: boolean; avatar_url: string | null
}
type LoginResult = { status: 'ok' } | { status: 'two_factor' }

interface Ctx {
  user: User | null; loading: boolean
  login: (email: string, password: string) => Promise<LoginResult>
  twoFactor: (code?: string, recovery?: string) => Promise<void>
  logout: () => Promise<void>
  can: (permission: string) => boolean
}
const AuthCtx = createContext<Ctx | null>(null)

/** UI hint only — every action is authorised again by the Laravel API. */
export function permissionMatches(perms: string[], key: string): boolean {
  if (perms.includes('*') || perms.includes(key)) return true
  const [mod] = key.split('.')
  return perms.includes(`${mod}.*`)
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const qc = useQueryClient()

  useEffect(() => {
    setUnauthenticatedHandler(() => { setUser(null); qc.clear() })
    api.get<{ data: User }>('/auth/me').then((r) => setUser(r.data)).catch(() => setUser(null)).finally(() => setLoading(false))
  }, [qc])

  const login = useCallback(async (email: string, password: string): Promise<LoginResult> => {
    await ensureCsrf(true)
    // Laravel API resources wrap the user in { data }; a 2FA-required login returns { two_factor_required }
    const res = await api.post<{ data?: User; two_factor_required?: boolean }>('/auth/login', { email, password })
    if (res?.two_factor_required || !res?.data) return { status: 'two_factor' }
    setUser(res.data); return { status: 'ok' }
  }, [])
  const twoFactor = useCallback(async (code?: string, recovery?: string) => {
    setUser((await api.post<{ data: User }>('/auth/two-factor-challenge', code ? { code } : { recovery_code: recovery })).data)
  }, [])
  const logout = useCallback(async () => {
    try { await api.post('/auth/logout') } catch (e) { if (!(e instanceof ApiError)) throw e }
    setUser(null); qc.clear()
  }, [qc])
  const can = useCallback((p: string) => !!user && permissionMatches(user.permissions, p), [user])

  const value = useMemo(() => ({ user, loading, login, twoFactor, logout, can }), [user, loading, login, twoFactor, logout, can])
  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>
}

export function useAuth(): Ctx {
  const c = useContext(AuthCtx)
  if (!c) throw new Error('useAuth outside AuthProvider')
  return c
}
