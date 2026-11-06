import { useEffect } from 'react'

/** Fixed backdrop + one delegated pointer handler: feeds --lx/--ly to every .lit surface, tilt vars to .tilt3d, and a parallax offset to the stage. */
export function Stage() {
  useEffect(() => {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
    const root = document.documentElement
    let raf = 0
    let last: HTMLElement | null = null
    const reset = (el: HTMLElement | null) => { if (el) { el.style.setProperty('--rx', '0deg'); el.style.setProperty('--ry', '0deg') } }
    const onMove = (e: PointerEvent) => {
      if (e.pointerType === 'touch') return
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => {
        root.style.setProperty('--px', String((e.clientX / window.innerWidth - 0.5) * 2))
        root.style.setProperty('--py', String((e.clientY / window.innerHeight - 0.5) * 2))
        const target = e.target as HTMLElement | null
        const lit = target?.closest<HTMLElement>('.lit')
        if (lit) {
          const r = lit.getBoundingClientRect()
          lit.style.setProperty('--lx', `${e.clientX - r.left}px`)
          lit.style.setProperty('--ly', `${e.clientY - r.top}px`)
        }
        const tilt = target?.closest<HTMLElement>('.tilt3d')
        if (tilt !== last) { reset(last); last = tilt ?? null }
        if (tilt) {
          const r = tilt.getBoundingClientRect()
          const x = (e.clientX - r.left) / r.width - 0.5
          const y = (e.clientY - r.top) / r.height - 0.5
          tilt.style.setProperty('--ry', `${x * 9}deg`)
          tilt.style.setProperty('--rx', `${-y * 9}deg`)
        }
      })
    }
    window.addEventListener('pointermove', onMove, { passive: true })
    return () => { window.removeEventListener('pointermove', onMove); cancelAnimationFrame(raf) }
  }, [])
  return <div className="stage" aria-hidden="true"><div className="stage-grid" /></div>
}
export default Stage
