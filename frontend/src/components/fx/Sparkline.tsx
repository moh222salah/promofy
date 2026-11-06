import { useId } from 'react'

/** Tiny trend line with a lit gradient area. Draws from real series data only. */
export function Sparkline({ data, color = '91 155 255', height = 34 }: { data: number[]; color?: string; height?: number }) {
  const id = useId()
  if (data.length < 2 || data.every((v) => v === 0)) return <div style={{ height }} className="rounded bg-gradient-to-r from-transparent via-line/60 to-transparent opacity-60" aria-hidden />
  const w = 120; const max = Math.max(...data); const min = Math.min(...data); const span = max - min || 1
  const pts = data.map((v, i) => [(i / (data.length - 1)) * w, height - 3 - ((v - min) / span) * (height - 8)] as const)
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const [lx, ly] = pts[pts.length - 1]
  return (
    <svg viewBox={`0 0 ${w} ${height}`} width="100%" height={height} preserveAspectRatio="none" aria-hidden>
      <defs><linearGradient id={id} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={`rgb(${color} / .45)`} /><stop offset="1" stopColor={`rgb(${color} / 0)`} /></linearGradient></defs>
      <path d={`${line} L${w},${height} L0,${height} Z`} fill={`url(#${id})`} />
      <path d={line} fill="none" stroke={`rgb(${color})`} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" style={{ filter: `drop-shadow(0 0 4px rgb(${color} / .8))` }} />
      <circle cx={lx} cy={ly} r="2.4" fill={`rgb(${color})`} />
    </svg>
  )
}
