import { createContext, useContext, useMemo, type ComponentType, type ReactNode } from 'react'
import type { AxiosInstance } from 'axios'
import { useTranslation } from 'react-i18next'
import { createCertificatesApi, type CertificatesApi } from './api'
import type { Recipient } from './types'
import { defaultUi, type UiKit } from './ui'

export interface ApiError {
  message: string
  fields: Record<string, string[]>
}

export interface CertificatesConfig {
  /** The host's axios instance (base URL ending in /api, auth, Accept-Language). */
  http: AxiosInstance
  /** Design-system components; missing ones use the package defaults. */
  ui?: Partial<UiKit>
  /** Permission check for certificates.issue and certificates.templates (buttons only; the API decides). */
  can?: (permission: string) => boolean
  /** Adds a recipient in the issue dialog. Without it, the dialog issues only to a preselected recipient. */
  RecipientPicker?: ComponentType<{ label: string; onPick: (recipient: Recipient) => void }>
  /** Recipient cell in lists, chips and drawers. `size="sm"` is a compact chip. Defaults to the name. */
  RecipientCard?: ComponentType<{ recipient: Recipient; size?: 'sm' | 'md' }>
  /** Link to the recipient in the host app (the profile page), or null. */
  recipientHref?: (recipient: Pick<Recipient, 'id' | 'type'>) => string | null
  /** Turns an axios error into a message and field errors. */
  parseError?: (error: unknown) => ApiError
  /** Date formatting (defaults to Intl in the active language). */
  formatDate?: (value: string, locale: string, options?: Intl.DateTimeFormatOptions) => string
  formatNumber?: (value: number, locale: string) => string
  /** Called after any change (issue, approve, revoke …), e.g. to refresh host screens that show counts. */
  onChanged?: () => void
}

export interface CertificatesContextValue {
  api: CertificatesApi
  ui: UiKit
  can: (permission: string) => boolean
  RecipientPicker: CertificatesConfig['RecipientPicker']
  RecipientCard: ComponentType<{ recipient: Recipient; size?: 'sm' | 'md' }>
  recipientHref: (recipient: Pick<Recipient, 'id' | 'type'>) => string | null
  parseError: (error: unknown) => ApiError
  formatDate: (value: string, locale: string, options?: Intl.DateTimeFormatOptions) => string
  formatNumber: (value: number, locale: string) => string
  onChanged: () => void
}

const Context = createContext<CertificatesContextValue | null>(null)

function DefaultRecipientCard({ recipient }: { recipient: Recipient; size?: 'sm' | 'md' }) {
  return <span dir="auto" className="font-medium text-ink">{recipient.name}</span>
}

function defaultParseError(error: unknown): ApiError {
  const e = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string }
  return { message: e?.response?.data?.message ?? e?.message ?? 'Error', fields: e?.response?.data?.errors ?? {} }
}

const defaultFormatDate = (value: string, locale: string, options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' }) => {
  const d = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value)
  return Number.isNaN(d.getTime()) ? value : new Intl.DateTimeFormat(locale, options).format(d)
}
const defaultFormatNumber = (value: number, locale: string) => new Intl.NumberFormat(locale).format(value)

/** Wrap the certificate screens (or the whole app) once. */
export function CertificatesProvider({ children, ...config }: CertificatesConfig & { children: ReactNode }) {
  const { http, ui, can, RecipientPicker, RecipientCard, recipientHref, parseError, formatDate, formatNumber, onChanged } = config
  const value = useMemo<CertificatesContextValue>(() => ({
    api: createCertificatesApi(http),
    ui: { ...defaultUi, ...ui, classes: { ...defaultUi.classes, ...ui?.classes } },
    can: can ?? (() => true),
    RecipientPicker,
    RecipientCard: RecipientCard ?? DefaultRecipientCard,
    recipientHref: recipientHref ?? (() => null),
    parseError: parseError ?? defaultParseError,
    formatDate: formatDate ?? defaultFormatDate,
    formatNumber: formatNumber ?? defaultFormatNumber,
    onChanged: onChanged ?? (() => {}),
  }), [http, ui, can, RecipientPicker, RecipientCard, recipientHref, parseError, formatDate, formatNumber, onChanged])

  return <Context.Provider value={value}>{children}</Context.Provider>
}

export function useCertificates(): CertificatesContextValue {
  const ctx = useContext(Context)
  if (!ctx) throw new Error('Certificate screens must be rendered inside <CertificatesProvider>.')
  return ctx
}

/** Translation in the package namespace, plus the active language. */
export function useCertT() {
  const { t, i18n } = useTranslation('certificates')
  return { t, locale: i18n.language }
}
