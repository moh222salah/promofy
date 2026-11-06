import { useEffect, useRef, useState } from 'react'

/** Animates from the previous value to the new one; respects reduced motion. */
export function CountUp({ value, format, duration = 900 }: { value: number; format: (n: number) => string; duration?: number }) {
  const [shown, setShown] = useState(0)
  const from = useRef(0)
  useEffect(() => {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) { setShown(value); return }
    const start = performance.now(); const a = from.current
    let raf = 0
    const tick = (t: number) => {
      const p = Math.min(1, (t - start) / duration)
      const eased = 1 - Math.pow(1 - p, 3)
      setShown(a + (value - a) * eased)
      if (p < 1) raf = requestAnimationFrame(tick); else from.current = value
    }
    raf = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(raf)
  }, [value, duration])
  return <>{format(shown)}</>
}
