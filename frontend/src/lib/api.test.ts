import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, qs } from './api'
import { permissionMatches } from '@/auth/AuthContext'

describe('qs', () => {
  it('drops empty values', () => expect(qs({ a: 1, b: '', c: undefined, d: false })).toBe('?a=1&d=false'))
})

describe('permissionMatches', () => {
  it('supports exact, module wildcard and super wildcard', () => {
    expect(permissionMatches(['orders.view'], 'orders.view')).toBe(true)
    expect(permissionMatches(['orders.view'], 'orders.edit')).toBe(false)
    expect(permissionMatches(['orders.*'], 'orders.edit')).toBe(true)
    expect(permissionMatches(['*'], 'x.y')).toBe(true)
  })
})

describe('api client', () => {
  const calls: { url: string; init?: RequestInit }[] = []
  beforeEach(() => {
    calls.length = 0
    document.cookie = 'XSRF-TOKEN=abc%3D; path=/'
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, init })
      if (url.includes('csrf-cookie')) return new Response(null, { status: 204 })
      if (url.endsWith('/fail')) return new Response(JSON.stringify({ message: 'bad', errors: { email: ['required'] } }), { status: 422 })
      return new Response(JSON.stringify({ ok: true }), { status: 200 })
    }))
  })
  afterEach(() => vi.unstubAllGlobals())

  it('sends the decoded XSRF header and cookies on mutations after fetching the csrf cookie', async () => {
    await api.post('/thing', { a: 1 })
    expect(calls[0].url).toBe('/sanctum/csrf-cookie')
    const post = calls.find((c) => c.url === '/api/v1/thing')!
    expect((post.init!.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=')
    expect(post.init!.credentials).toBe('include')
  })
  it('surfaces validation errors', async () => {
    await expect(api.get('/fail')).rejects.toMatchObject({ status: 422, errors: { email: ['required'] } } satisfies Partial<ApiError>)
  })
})
