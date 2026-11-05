import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar.json'
import en from './en.json'

export type Locale = 'ar' | 'en'
const stored = (() => { try { return localStorage.getItem('locale') } catch { return null } })()
export const initialLocale: Locale = stored === 'en' || stored === 'ar' ? stored : 'ar'

i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: initialLocale,
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
})

/** Keeps <html lang/dir> in step with the active language so RTL/LTR CSS and fonts switch together. */
export function applyLocale(l: Locale) {
  document.documentElement.lang = l
  document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr'
  try { localStorage.setItem('locale', l) } catch { /* storage unavailable */ }
  i18n.changeLanguage(l)
}
applyLocale(initialLocale)

export const fmtNumber = (n: number, l: Locale = i18n.language as Locale) => new Intl.NumberFormat(l === 'ar' ? 'ar-EG' : 'en-US').format(n)
export const fmtMoney = (n: number, currency: string, l: Locale = i18n.language as Locale) =>
  new Intl.NumberFormat(l === 'ar' ? 'ar-EG' : 'en-US', { style: 'currency', currency, maximumFractionDigits: 2 }).format(n)
export const fmtDate = (d: string | Date, l: Locale = i18n.language as Locale) =>
  new Intl.DateTimeFormat(l === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium' }).format(new Date(d))

export default i18n
