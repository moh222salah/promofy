export class ApiError extends Error {
  constructor(public status: number, message: string, public errors: Record<string, string[]> = {}) {
    super(message)
  }
}

const base = '/api/v1'

function cookie(name: string): string | null {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'))
  return m ? decodeURIComponent(m[1]) : null
}

let csrfReady: Promise<void> | null = null
/** Sanctum SPA auth: fetch the XSRF cookie once before the first state-changing request. */
export function ensureCsrf(force = false): Promise<void> {
  if (force || !csrfReady) {
    csrfReady = fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then(() => undefined)
  }
  return csrfReady
}

type Query = Record<string, string | number | boolean | null | undefined>

export function qs(params?: Query): string {
  if (!params) return ''
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') p.set(k, String(v))
  const s = p.toString()
  return s ? `?${s}` : ''
}

let onUnauthenticated: (() => void) | null = null
export const setUnauthenticatedHandler = (fn: () => void) => { onUnauthenticated = fn }

export async function request<T = unknown>(method: string, path: string, body?: unknown, query?: Query): Promise<T> {
  const isGet = method === 'GET'
  if (!isGet) await ensureCsrf()
  const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': document.documentElement.lang || 'ar' }
  const xsrf = cookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
  let payload: BodyInit | undefined
  if (body instanceof FormData) payload = body
  else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }

  const res = await fetch(base + path + qs(query), { method, headers, body: payload, credentials: 'include' })
  if (res.status === 419) { // CSRF token expired: refresh once and retry
    await ensureCsrf(true)
    if (!isGet) return request<T>(method, path, body, query)
  }
  if (res.status === 204) return undefined as T
  const text = await res.text()
  const json = text ? safeJson(text) : null
  if (!res.ok) {
    if (res.status === 401 && onUnauthenticated) onUnauthenticated()
    throw new ApiError(res.status, json?.message ?? res.statusText, json?.errors ?? {})
  }
  return json as T
}

function safeJson(t: string): any { try { return JSON.parse(t) } catch { return null } }

export const api = {
  get: <T = any>(p: string, q?: Query) => request<T>('GET', p, undefined, q),
  post: <T = any>(p: string, b?: unknown) => request<T>('POST', p, b ?? {}),
  put: <T = any>(p: string, b?: unknown) => request<T>('PUT', p, b ?? {}),
  patch: <T = any>(p: string, b?: unknown) => request<T>('PATCH', p, b ?? {}),
  delete: <T = any>(p: string) => request<T>('DELETE', p),
}
