import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/auth/AuthContext'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui'

const schema = z.object({ email: z.string().email(), password: z.string().min(1) })
type Form = z.infer<typeof schema>

export default function Login() {
  const { t } = useTranslation()
  const { user, login, twoFactor } = useAuth()
  const nav = useNavigate()
  const location = useLocation()
  const [step, setStep] = useState<'creds' | 'code'>('creds')
  const [error, setError] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState('')
  const { register, handleSubmit, formState: { isSubmitting, errors } } = useForm<Form>({ resolver: zodResolver(schema) })

  if (user?.type === 'staff') return <Navigate to="/admin" replace />

  const fail = (e: unknown) => setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('app.error'))

  return (
    <div className="flex min-h-screen items-center justify-center bg-navy-900 p-4">
      <div className="w-full max-w-sm rounded-2xl border border-line bg-surface p-6 shadow-xl">
        <img src="/logo.png" alt="PROMOFY" className="mx-auto mb-3 h-14 w-14 rounded-full" />
        <h1 className="mb-4 text-center text-lg font-bold">{t('auth.title')}</h1>
        {(location.state as any)?.notStaff && <p role="alert" className="mb-3 text-sm text-danger">{t('auth.notStaff')}</p>}
        {error && <p role="alert" className="mb-3 text-sm text-danger">{error}</p>}
        {step === 'creds' ? (
          <form className="space-y-3" onSubmit={handleSubmit(async (v) => {
            setError(null)
            try { const r = await login(v.email, v.password); if (r.status === 'two_factor') setStep('code'); else nav('/admin', { replace: true }) } catch (e) { fail(e) }
          })}>
            <label className="block text-sm">{t('auth.email')}
              <input type="email" autoComplete="username" dir="ltr" className="mt-1 w-full rounded-lg border border-line bg-bg p-2" aria-invalid={!!errors.email} {...register('email')} />
            </label>
            <label className="block text-sm">{t('auth.password')}
              <input type="password" autoComplete="current-password" dir="ltr" className="mt-1 w-full rounded-lg border border-line bg-bg p-2" {...register('password')} />
            </label>
            <Button type="submit" className="w-full" disabled={isSubmitting}>{t('auth.submit')}</Button>
          </form>
        ) : (
          <form className="space-y-3" onSubmit={async (e) => { e.preventDefault(); setError(null); try { await twoFactor(code || undefined, code ? undefined : recovery); nav('/admin', { replace: true }) } catch (x) { fail(x) } }}>
            <label className="block text-sm">{t('auth.code')}
              <input inputMode="numeric" autoComplete="one-time-code" dir="ltr" className="mt-1 w-full rounded-lg border border-line bg-bg p-2" value={code} onChange={(e) => setCode(e.target.value)} />
            </label>
            <label className="block text-sm text-muted">{t('auth.recovery')}
              <input dir="ltr" className="mt-1 w-full rounded-lg border border-line bg-bg p-2" value={recovery} onChange={(e) => setRecovery(e.target.value)} />
            </label>
            <Button type="submit" className="w-full">{t('auth.verify')}</Button>
          </form>
        )}
      </div>
    </div>
  )
}
