import clsx from 'clsx'
import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

export function Button({ variant = 'primary', className, ...p }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' | 'outline' }) {
  return <button {...p} className={clsx('inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed',
    variant === 'primary' && 'bg-brand text-white dark:text-bg hover:opacity-90',
    variant === 'outline' && 'border border-line bg-surface hover:bg-surface-2',
    variant === 'ghost' && 'hover:bg-surface-2', className)} />
}

export function Card({ title, actions, children, className }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={clsx('rounded-xl border border-line bg-surface p-4 shadow-sm', className)}>
      {(title || actions) && <header className="mb-3 flex items-center justify-between gap-2"><h3 className="text-sm font-semibold text-ink">{title}</h3>{actions}</header>}
      {children}
    </section>
  )
}

export function Spinner() {
  const { t } = useTranslation()
  return <div role="status" className="flex h-full min-h-24 items-center justify-center text-sm text-muted">{t('app.loading')}</div>
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
