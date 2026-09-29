import type { i18n as I18n } from 'i18next'
import ar from './locales/ar.json'
import en from './locales/en.json'

/** i18next namespace of the package texts. */
export const ID_CARD_NS = 'idCard'

/** Package texts per language. */
export const idCardResources: Record<string, Record<string, unknown>> = { en, ar }

/**
 * Register the package texts with the host's i18next instance. Keys the host already defines in the "idCard"
 * namespace win, so a host changes wording (e.g. "Boy"/"Girl" for gender) by shipping only those keys.
 * Pass `extra` for more languages ({ fr: {...} }).
 */
export function registerIdCardI18n(i18n: I18n, extra: Record<string, Record<string, unknown>> = {}) {
  for (const [lng, bundle] of Object.entries({ ...idCardResources, ...extra })) {
    i18n.addResourceBundle(lng, ID_CARD_NS, bundle, true, false)
  }
}
