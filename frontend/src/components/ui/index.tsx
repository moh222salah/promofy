import clsx from 'clsx'
import type { ComponentProps, ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

export function Button({ variant = 'primary', className, ...p }: ComponentProps<'button'> & { variant?: 'primary' | 'ghost' | 'outline' }) {
  return <button {...p} className={clsx('btn disabled:opacity-50 disabled:cursor-not-allowed', variant === 'primary' && 'btn-primary', variant === 'outline' && 'btn-outline', variant === 'ghost' && 'btn-ghost', className)} />
}

export function Card({ title, actions, children, className, tilt, bare }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; tilt?: boolean; bare?: boolean }) {
  return (
    <section className={clsx('glass lit rounded-2xl', !bare && 'p-4', tilt && 'tilt3d', className)}>
      {(title || actions) && <header className="mb-3 flex items-center justify-between gap-2"><h3 className="display text-sm font-semibold tracking-wide text-ink">{title}</h3>{actions}</header>}
      {children}
    </section>
  )
}

export function Spinner() {
  const { t } = useTranslation()
  return (
    <div role="status" aria-label={t('app.loading')} className="grid min-h-24 gap-2 p-2">
      <div className="skeleton h-4 w-1/3" /><div className="skeleton h-16 w-full" /><div className="skeleton h-4 w-2/3" />
      <span className="sr-only">{t('app.loading')}</span>
    </div>
  )
}
export function EmptyState({ message }: { message?: string }) {
  const { t } = useTranslation()
  return <div className="flex min-h-24 items-center justify-center text-sm text-muted">{message ?? t('app.empty')}</div>
}
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const { t } = useTranslation()
  return (
    <div role="alert" className="flex min-h-24 flex-col items-center justify-center gap-2 text-sm text-danger">
      <span>{error instanceof Error && error.message ? error.message : t('app.error')}</span>
      {onRetry && <Button variant="outline" onClick={onRetry}>{t('app.retry')}</Button>}
    </div>
  )
}
