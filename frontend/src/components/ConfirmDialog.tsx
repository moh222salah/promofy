import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'

export function ConfirmDialog({ open, title, message, confirmLabel, danger, busy, onConfirm, onCancel }: {
  open: boolean; title: string; message?: string; confirmLabel?: string; danger?: boolean; busy?: boolean; onConfirm: () => void; onCancel: () => void
}) {
  const { t } = useTranslation()
  const ref = useRef<HTMLButtonElement>(null)
  useEffect(() => { if (open) ref.current?.focus() }, [open])
  useEffect(() => {
    if (!open) return
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onCancel()
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h)
  }, [open, onCancel])
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onMouseDown={onCancel}>
      <div role="alertdialog" aria-modal="true" aria-labelledby="cd-title" className="w-full max-w-sm rounded-xl border border-line bg-surface p-5 shadow-xl" onMouseDown={(e) => e.stopPropagation()}>
        <h2 id="cd-title" className="text-base font-bold">{title}</h2>
        {message && <p className="mt-2 text-sm text-muted">{message}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" onClick={onCancel}>{t('crud.cancel')}</Button>
          <Button ref={ref} className={danger ? '!bg-danger !text-white' : ''} disabled={busy} onClick={onConfirm}>{confirmLabel ?? t('crud.confirm')}</Button>
        </div>
      </div>
    </div>
  )
}
