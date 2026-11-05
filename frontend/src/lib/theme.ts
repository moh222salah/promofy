export type Theme = 'light' | 'dark'
export function initialTheme(): Theme {
  try {
    const s = localStorage.getItem('theme')
    if (s === 'light' || s === 'dark') return s
  } catch { /* ignore */ }
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}
export function applyTheme(t: Theme) {
  document.documentElement.dataset.theme = t
  try { localStorage.setItem('theme', t) } catch { /* ignore */ }
}
