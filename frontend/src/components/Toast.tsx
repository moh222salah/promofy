import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import clsx from 'clsx'

type Kind = 'success' | 'error'
interface Item { id: number; kind: Kind; text: string }
const Ctx = createContext<(text: string, kind?: Kind) => void>(() => undefined)
export const useToast = () => useContext(Ctx)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Item[]>([])
  const push = useCallback((text: string, kind: Kind = 'success') => {
    const id = Date.now() + Math.random()
    setItems((x) => [...x, { id, kind, text }])
    setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), 4500)
  }, [])
  return (
    <Ctx.Provider value={push}>
      {children}
      <div className="pointer-events-none fixed bottom-4 end-4 z-[60] flex max-w-sm flex-col gap-2" aria-live="polite">
        {items.map((i) => (
          <div key={i.id} role={i.kind === 'error' ? 'alert' : 'status'} className={clsx('pointer-events-auto rounded-lg border px-4 py-2 text-sm shadow-lg', i.kind === 'error' ? 'border-danger bg-surface text-danger' : 'border-success bg-surface text-ink')}>{i.text}</div>
        ))}
      </div>
    </Ctx.Provider>
  )
}
