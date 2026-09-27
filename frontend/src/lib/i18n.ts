import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import arCommon from '../locales/ar/common.json'
import arAuth from '../locales/ar/auth.json'
import enCommon from '../locales/en/common.json'
import enAuth from '../locales/en/auth.json'
import arNav from '../locales/ar/nav.json'
import enNav from '../locales/en/nav.json'
import arDashboard from '../locales/ar/dashboard.json'
import enDashboard from '../locales/en/dashboard.json'

export type AppLocale = 'ar' | 'en'
const STORAGE_KEY = 'ahl.locale'

function readStoredLocale(): AppLocale {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    if (v === 'ar' || v === 'en') return v
  } catch {
    /* storage unavailable */
  }
  return 'ar'
}

/** Keep <html lang/dir> in step with the active language so Tailwind logical utilities flip correctly. */
export function applyDocumentLocale(locale: AppLocale) {
  document.documentElement.lang = locale
  document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr'
  document.title = i18n.t('common:app_name')
}

export function setLocale(locale: AppLocale) {
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    /* ignore */
  }
  void i18n.changeLanguage(locale)
}

void i18n.use(initReactI18next).init({
  resources: {
    ar: { common: arCommon, auth: arAuth, nav: arNav, dashboard: arDashboard },
    en: { common: enCommon, auth: enAuth, nav: enNav, dashboard: enDashboard },
  },
  lng: readStoredLocale(),
  fallbackLng: 'ar',
  defaultNS: 'common',
  interpolation: { escapeValue: false },
})

i18n.on('languageChanged', (lng) => applyDocumentLocale(lng as AppLocale))
applyDocumentLocale(i18n.language as AppLocale)

export default i18n
