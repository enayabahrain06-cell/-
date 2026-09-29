import type { i18n as I18n } from 'i18next'
import ar from './locales/ar.json'
import en from './locales/en.json'

/** Package texts per language, namespace "certificates". */
export const certificatesResources: Record<string, Record<string, unknown>> = { en, ar }

/**
 * Register the package texts with the host's i18next instance. Keys the host already defines in the
 * "certificates" namespace win, so a host overrides wording by shipping only the keys it changes.
 * Pass `extra` for more languages ({ fr: {...} }).
 */
export function registerCertificatesI18n(i18n: I18n, extra: Record<string, Record<string, unknown>> = {}) {
  for (const [lng, bundle] of Object.entries({ ...certificatesResources, ...extra })) {
    i18n.addResourceBundle(lng, 'certificates', bundle, true, false)
  }
}
