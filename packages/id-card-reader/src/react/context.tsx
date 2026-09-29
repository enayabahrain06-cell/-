import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { defaultUi, type UiKit } from './ui'

export interface IdCardConfig {
  /** Design-system components; missing ones use the package defaults. */
  ui?: Partial<UiKit>
  /** Birth dates in the compare dialog (defaults to Intl in the active language). */
  formatDate?: (value: string, locale: string) => string
  /** Turns a save error (from the host's onApply) into a message. */
  parseError?: (error: unknown) => { message: string }
}

interface IdCardContextValue {
  ui: UiKit
  formatDate: (value: string, locale: string) => string
  parseError: (error: unknown) => { message: string }
}

const defaultFormatDate = (value: string, locale: string) => {
  const d = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value)
  return Number.isNaN(d.getTime()) ? value : new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(d)
}

const defaultParseError = (error: unknown) => {
  const e = error as { response?: { data?: { message?: string } }; message?: string }
  return { message: e?.response?.data?.message ?? e?.message ?? 'Error' }
}

const Context = createContext<IdCardContextValue | null>(null)

/** Optional: wrap the app (or a screen) to draw the components with the host's design system. */
export function IdCardProvider({ children, ...config }: IdCardConfig & { children: ReactNode }) {
  const { ui, formatDate, parseError } = config
  const value = useMemo<IdCardContextValue>(() => ({
    ui: { ...defaultUi, ...ui },
    formatDate: formatDate ?? defaultFormatDate,
    parseError: parseError ?? defaultParseError,
  }), [ui, formatDate, parseError])
  return <Context.Provider value={value}>{children}</Context.Provider>
}

const fallback: IdCardContextValue = { ui: defaultUi, formatDate: defaultFormatDate, parseError: defaultParseError }

export const useIdCardConfig = () => useContext(Context) ?? fallback
