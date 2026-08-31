import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './locales/en.json'
import ar from './locales/ar.json'

export const SUPPORTED_LOCALES = ['en', 'ar'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]

// Text direction is derived from the locale — never hard-coded per screen.
export const RTL_LOCALES: Locale[] = ['ar']
export const directionFor = (locale: string): 'rtl' | 'ltr' =>
  RTL_LOCALES.includes(locale as Locale) ? 'rtl' : 'ltr'

const stored = (typeof localStorage !== 'undefined' && localStorage.getItem('locale')) || 'en'

i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, ar: { translation: ar } },
  lng: SUPPORTED_LOCALES.includes(stored as Locale) ? stored : 'en',
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
})

/** Apply the locale to i18next AND the document (lang + dir) for RTL/LTR. */
export function applyLocale(locale: string): void {
  const safe = SUPPORTED_LOCALES.includes(locale as Locale) ? locale : 'en'
  i18n.changeLanguage(safe)
  try {
    localStorage.setItem('locale', safe)
  } catch {
    /* storage may be unavailable */
  }
  document.documentElement.lang = safe
  document.documentElement.dir = directionFor(safe)
}

applyLocale(i18n.language)

export default i18n
