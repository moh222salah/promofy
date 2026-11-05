/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: ['class', '[data-theme="dark"]'],
  theme: {
    extend: {
      colors: {
        navy: { DEFAULT: '#102B5C', 900: '#081426' },
        royal: '#164E9B',
        mist: '#EAF1FC',
        // semantic tokens resolve to CSS variables so admins can re-brand and dark mode can swap them
        brand: 'rgb(var(--c-brand) / <alpha-value>)',
        'brand-2': 'rgb(var(--c-brand-2) / <alpha-value>)',
        bg: 'rgb(var(--c-bg) / <alpha-value>)',
        surface: 'rgb(var(--c-surface) / <alpha-value>)',
        'surface-2': 'rgb(var(--c-surface-2) / <alpha-value>)',
        line: 'rgb(var(--c-line) / <alpha-value>)',
        ink: 'rgb(var(--c-ink) / <alpha-value>)',
        muted: 'rgb(var(--c-muted) / <alpha-value>)',
        danger: 'rgb(var(--c-danger) / <alpha-value>)',
        success: 'rgb(var(--c-success) / <alpha-value>)',
        warn: 'rgb(var(--c-warn) / <alpha-value>)',
      },
      fontFamily: {
        sans: ['Inter', 'Manrope', 'system-ui', 'sans-serif'],
        arabic: ['Alexandria', '"IBM Plex Sans Arabic"', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
