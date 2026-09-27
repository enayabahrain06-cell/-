import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

// Every locales/<lang>/<namespace>.json file is picked up automatically (one namespace per module).
const files = import.meta.glob<{ default: Record<string, unknown> }>('../locales/*/*.json', { eager: true })
const resources: Record<string, Record<string, Record<string, unknown>>> = {}
for (const [path, mod] of Object.entries(files)) {
  const [, lang, ns] = path.match(/locales\/(\w+)\/([\w-]+)\.json$/) ?? []
  if (lang && ns) (resources[lang] ??= {})[ns] = mod.default
}

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
  resources,
  lng: readStoredLocale(),
  fallbackLng: 'ar',
  defaultNS: 'common',
  interpolation: { escapeValue: false },
})

i18n.on('languageChanged', (lng) => applyDocumentLocale(lng as AppLocale))
applyDocumentLocale(i18n.language as AppLocale)

export default i18n
