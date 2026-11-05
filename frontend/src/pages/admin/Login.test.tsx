import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import '@/i18n'
import { AuthProvider } from '@/auth/AuthContext'
import Login from './Login'

function mockApi(handlers: Record<string, () => Response>) {
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const key = Object.keys(handlers).find((k) => url.includes(k))
    return key ? handlers[key]() : new Response(null, { status: 204 })
  }))
}

describe('Login', () => {
  it('shows the server error message on bad credentials', async () => {
    mockApi({
      '/auth/me': () => new Response('{}', { status: 401 }),
      '/auth/login': () => new Response(JSON.stringify({ message: 'x', errors: { email: ['These credentials do not match our records.'] } }), { status: 422 }),
    })
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><AuthProvider><Login /></AuthProvider></MemoryRouter></QueryClientProvider>)
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText(/البريد|Email/), 'a@b.co')
    await u.type(screen.getByLabelText(/كلمة المرور|Password/), 'wrong')
    await u.click(screen.getByRole('button'))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('These credentials do not match'))
  })

  it('moves to the 2FA step when the API asks for it', async () => {
    mockApi({
      '/auth/me': () => new Response('{}', { status: 401 }),
      '/auth/login': () => new Response(JSON.stringify({ two_factor_required: true }), { status: 200 }),
    })
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><AuthProvider><Login /></AuthProvider></MemoryRouter></QueryClientProvider>)
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText(/البريد|Email/), 'a@b.co')
    await u.type(screen.getByLabelText(/كلمة المرور|Password/), 'pw')
    await u.click(screen.getByRole('button'))
    expect(await screen.findByLabelText(/رمز المصادقة|Authentication code/)).toBeInTheDocument()
  })
})

describe('Login success', () => {
  it('unwraps the { data } resource envelope and leaves the login page', async () => {
    let signedIn = false
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url.includes('/auth/me')) return signedIn ? new Response(JSON.stringify({ data: { id: 1, name: 'A', email: 'a@b.co', type: 'staff', roles: [], permissions: ['*'] } }), { status: 200 }) : new Response('{}', { status: 401 })
      if (url.includes('/auth/login')) { signedIn = true; return new Response(JSON.stringify({ data: { id: 1, name: 'A', email: 'a@b.co', type: 'staff', roles: [], permissions: ['*'] } }), { status: 200 }) }
      return new Response(null, { status: 204 })
    }))
    const { Routes, Route } = await import('react-router-dom')
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/admin/login']}><AuthProvider>
      <Routes><Route path="/admin/login" element={<Login />} /><Route path="/admin" element={<div>HOME</div>} /></Routes></AuthProvider></MemoryRouter></QueryClientProvider>)
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText(/البريد|Email/), 'a@b.co')
    await u.type(screen.getByLabelText(/كلمة المرور|Password/), 'pw')
    await u.click(screen.getByRole('button'))
    expect(await screen.findByText('HOME')).toBeInTheDocument()
  })
})
