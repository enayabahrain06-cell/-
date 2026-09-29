import { forwardRef, useEffect, useId, type RefAttributes, type ButtonHTMLAttributes, type ComponentType, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * The building blocks the certificate screens are drawn with. A host passes its own design-system
 * components through <CertificatesProvider ui={…}>; anything it leaves out falls back to the plain
 * Tailwind defaults below (tokens: brand, gold, ink, danger, page, paper, info; see theme.css).
 */
export type Tone = 'brand' | 'gold' | 'danger' | 'info' | 'muted'

type Labelled = { label: string; hideLabel?: boolean }

export interface UiKit {
  Badge: ComponentType<{ tone?: Tone; children: ReactNode; className?: string }>
  PrimaryButton: ComponentType<ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean; tone?: 'brand' | 'danger' }>
  SecondaryButton: ComponentType<ButtonHTMLAttributes<HTMLButtonElement>>
  TextInput: ComponentType<InputHTMLAttributes<HTMLInputElement> & Labelled>
  /** Must forward its ref to the <textarea> (the template editor inserts placeholders at the caret). */
  TextArea: ComponentType<TextareaHTMLAttributes<HTMLTextAreaElement> & Labelled & RefAttributes<HTMLTextAreaElement>>
  SelectField: ComponentType<SelectHTMLAttributes<HTMLSelectElement> & Labelled & { options: { value: string; label: string }[] }>
  SearchInput: ComponentType<InputHTMLAttributes<HTMLInputElement> & { label: string }>
  Segmented: <T extends string>(props: { name: string; value: T | null; options: { value: T; label: string }[]; onChange: (v: T) => void; label: string }) => ReactNode
  Notice: ComponentType<{ tone?: 'success' | 'error' | 'info'; children: ReactNode }>
  LoadingState: ComponentType
  ErrorState: ComponentType<{ message?: string; onRetry?: () => void }>
  EmptyCard: ComponentType<{ title: string; body?: string; icon?: string; size?: 'sm' | 'md' }>
  Card: ComponentType<{ children: ReactNode; className?: string }>
  FilterBar: ComponentType<{ children: ReactNode; className?: string; label?: string; layout?: 'stack' | 'grid' | 'row' }>
  TableWrap: ComponentType<{ children: ReactNode; surface?: boolean; className?: string }>
  Pagination: ComponentType<{ page: number; lastPage: number; total: number; onPage: (p: number) => void }>
  Modal: ComponentType<{ title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }>
  /** Page heading with optional actions (the host's page band / header). */
  PageHeader: ComponentType<{ title: ReactNode; subtitle?: ReactNode; actions?: ReactNode }>
  /** Wraps the public verification page (the host's public layout, logo, footer). */
  PublicLayout: ComponentType<{ children: ReactNode }>
  Spinner: ComponentType<{ className?: string }>
  /** Icon by name: certificate, check, close, eye, edit, trash, ban, messages, download, printer, share, camera, search, chevron. */
  Icon: ComponentType<{ name: string; className?: string }>
  /** Decorative rule under headings (null to omit). */
  Divider: ComponentType<{ className?: string; align?: 'start' | 'center' }> | null
  /** Class names: white card surface, table header row, the primary action on PageHeader. */
  classes: { surface: string; tableHead: string; headerAction: string }
}

/* ------------------------------------------------------------------ defaults */

const SURFACE = 'rounded-2xl border border-ink/8 bg-white shadow-sm'
const TONES: Record<Tone, string> = {
  brand: 'bg-brand-50 text-brand-700',
  gold: 'bg-gold-500/12 text-gold-700',
  danger: 'bg-danger/10 text-danger',
  info: 'bg-info/10 text-info-700',
  muted: 'bg-ink/6 text-ink/65',
}
const BTN = 'inline-flex min-h-10 items-center justify-center rounded-xl text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed'
const FIELD = 'block min-h-10 w-full rounded-xl border border-ink/15 bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink/40 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100'
const LABEL = 'mb-1.5 block text-sm font-medium text-ink/75'

function Spinner({ className = 'size-6' }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={`animate-spin ${className}`} aria-hidden fill="none">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  )
}

const ICONS: Record<string, string> = {
  certificate: 'M5 4h14v11H5zM9 19l3-2 3 2v-4H9z',
  check: 'M5 12l5 5L20 7',
  close: 'M6 6l12 12M18 6L6 18',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
  ban: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM6 6l12 12',
  messages: 'M4 5h16v11H8l-4 4z',
  download: 'M12 4v11m0 0l-4-4m4 4l4-4M5 20h14',
  printer: 'M7 9V4h10v5M7 17H5v-7h14v7h-2M7 14h10v6H7z',
  share: 'M18 8a3 3 0 1 0-3-3M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4',
  camera: 'M4 8h4l2-3h4l2 3h4v11H4zM12 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
  chevron: 'M15 6l-6 6 6 6',
}
function Icon({ name, className = 'size-5' }: { name: string; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d={ICONS[name] ?? ICONS.certificate} />
    </svg>
  )
}

const TextInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & Labelled>(function TextInput({ label, hideLabel, className = '', id, ...rest }, ref) {
  const auto = useId()
  return (
    <div className={className}>
      <label htmlFor={id ?? auto} className={hideLabel ? 'sr-only' : LABEL}>{label}</label>
      <input ref={ref} id={id ?? auto} {...rest} className={FIELD} />
    </div>
  )
})

const TextArea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & Labelled>(function TextArea({ label, hideLabel, className = '', id, ...rest }, ref) {
  const auto = useId()
  return (
    <div className={className}>
      <label htmlFor={id ?? auto} className={hideLabel ? 'sr-only' : LABEL}>{label}</label>
      <textarea ref={ref} id={id ?? auto} rows={3} {...rest} className={FIELD} />
    </div>
  )
})

function SelectField({ label, hideLabel, options, className = '', id, ...rest }: SelectHTMLAttributes<HTMLSelectElement> & Labelled & { options: { value: string; label: string }[] }) {
  const auto = useId()
  return (
    <div className={className}>
      <label htmlFor={id ?? auto} className={hideLabel ? 'sr-only' : LABEL}>{label}</label>
      <select id={id ?? auto} {...rest} className={FIELD}>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </div>
  )
}

function SearchInput({ label, className = '', id, placeholder, ...rest }: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  const auto = useId()
  return (
    <div className={`relative min-w-0 ${className}`}>
      <label htmlFor={id ?? auto} className="sr-only">{label}</label>
      <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-ink/40" />
      <input id={id ?? auto} type="search" autoComplete="off" placeholder={placeholder ?? label} {...rest} className={`${FIELD} ps-9`} />
    </div>
  )
}

function Segmented<T extends string>({ name, value, options, onChange, label }: { name: string; value: T | null; options: { value: T; label: string }[]; onChange: (v: T) => void; label: string }) {
  return (
    <fieldset className="min-w-0">
      <legend className="sr-only">{label}</legend>
      <div className="inline-flex flex-wrap gap-1 rounded-xl bg-ink/5 p-1">
        {options.map((o) => (
          <label key={o.value} className={`cursor-pointer rounded-lg px-3 py-1.5 text-sm font-medium transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${value === o.value ? 'bg-white text-ink shadow-sm ring-1 ring-ink/10' : 'text-ink/60 hover:text-ink'}`}>
            <input type="radio" name={name} value={o.value} checked={value === o.value} onChange={() => onChange(o.value)} className="sr-only" />
            {o.label}
          </label>
        ))}
      </div>
    </fieldset>
  )
}

function Modal({ title, onClose, children, footer, wide = false }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const { t } = useTranslation('certificates')
  const titleId = useId()
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])
  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <button type="button" tabIndex={-1} className="fixed inset-0 bg-ink/50" aria-hidden onClick={onClose} />
      <div className={`relative flex max-h-[calc(100dvh-2rem)] w-full min-w-0 flex-col ${wide ? 'max-w-2xl' : 'max-w-lg'} rounded-2xl bg-white shadow-2xl`}>
        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-ink/8 px-5 py-4">
          <h2 id={titleId} className="min-w-0 text-lg font-semibold text-ink">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5 hover:text-ink" aria-label={t('actions.close')}><Icon name="close" className="size-5" /></button>
        </div>
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-ink/8 px-5 py-3">{footer}</div>}
      </div>
    </div>
  )
}

function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (p: number) => void }) {
  const { t } = useTranslation('certificates')
  if (lastPage <= 1) return null
  const btn = 'grid size-10 place-items-center rounded-lg border border-ink/10 bg-white text-ink/70 hover:bg-ink/5 disabled:opacity-40'
  return (
    <nav aria-label={t('pagination.label')} className="flex flex-wrap items-center justify-between gap-3 text-sm text-ink/60">
      <p>{t('pagination.summary', { page, last: lastPage, total })}</p>
      <div className="flex gap-1.5">
        <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className={btn} aria-label={t('pagination.previous')}><Icon name="chevron" className="size-4 ltr:rotate-180" /></button>
        <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)} className={btn} aria-label={t('pagination.next')}><Icon name="chevron" className="size-4 rtl:rotate-180" /></button>
      </div>
    </nav>
  )
}

export const defaultUi: UiKit = {
  Badge: ({ tone = 'muted', children, className = '' }) => <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${TONES[tone]} ${className}`}>{children}</span>,
  PrimaryButton: ({ children, className = '', loading, tone = 'brand', ...rest }) => (
    <button type="button" {...rest} disabled={rest.disabled || loading} aria-busy={loading}
      className={`${BTN} gap-2 px-4 py-2 font-semibold text-white shadow-sm disabled:opacity-60 ${tone === 'danger' ? 'bg-danger hover:bg-danger/90' : 'bg-brand-700 hover:bg-brand-800'} ${className}`}>
      {loading && <Spinner className="size-4" />}{children}
    </button>
  ),
  SecondaryButton: ({ children, className = '', ...rest }) => (
    <button type="button" {...rest} className={`${BTN} gap-1.5 border border-ink/12 bg-white px-3 py-2 font-medium text-ink/80 hover:bg-ink/5 disabled:opacity-50 ${className}`}>{children}</button>
  ),
  TextInput,
  TextArea,
  SelectField,
  SearchInput,
  Segmented,
  Notice: ({ tone = 'success', children }) => {
    const cls = { error: 'border-danger/25 bg-danger/5 text-danger', info: 'border-info/20 bg-info/5 text-info-700', success: 'border-brand-100 bg-brand-50 text-brand-800' }[tone]
    return <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-4 py-2.5 text-sm ${cls}`}>{children}</div>
  },
  LoadingState: () => <div className="grid place-items-center py-20"><Spinner className="size-10 text-brand-600" /></div>,
  ErrorState: function ErrorState({ message, onRetry }) {
    const { t } = useTranslation('certificates')
    return (
      <div role="alert" className="rounded-2xl border border-danger/25 bg-danger/5 p-6 text-center text-danger">
        <p>{message ?? t('errors.unexpected')}</p>
        {onRetry && <button type="button" onClick={onRetry} className={`${BTN} mt-3 border border-ink/12 bg-white px-3 py-2 text-ink/80`}>{t('actions.retry')}</button>}
      </div>
    )
  },
  EmptyCard: ({ title, body, icon, size = 'md' }) => (
    <div className={`${SURFACE} grid place-items-center gap-2 px-6 text-center ${size === 'sm' ? 'py-8' : 'py-14'}`}>
      {icon && <Icon name={icon} className="size-8 text-gold-500" />}
      <p className="font-semibold text-ink">{title}</p>
      {body && <p className="text-sm text-ink/60">{body}</p>}
    </div>
  ),
  Card: ({ children, className = '' }) => <section className={`${SURFACE} p-4 sm:p-5 ${className}`}>{children}</section>,
  FilterBar: ({ children, className = '', label, layout = 'stack' }) => (
    <section aria-label={label} className={`${SURFACE} p-4 ${layout === 'grid' ? 'grid gap-3' : layout === 'row' ? 'flex flex-wrap items-center gap-2' : 'flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end'} ${className}`}>{children}</section>
  ),
  TableWrap: ({ children, surface = false, className = '' }) => surface
    ? <div className={`${SURFACE} overflow-hidden ${className}`}><div className="overflow-x-auto">{children}</div></div>
    : <div className={`overflow-x-auto ${className}`}>{children}</div>,
  Pagination,
  Modal,
  PageHeader: ({ title, subtitle, actions }) => (
    <header className="flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold text-ink">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-ink/60">{subtitle}</p>}
      </div>
      {actions}
    </header>
  ),
  PublicLayout: ({ children }) => <main className="min-h-dvh bg-page px-4 py-10">{children}</main>,
  Spinner,
  Icon,
  Divider: ({ className = '', align = 'start' }) => <span aria-hidden className={`block h-px w-16 bg-current ${align === 'center' ? 'mx-auto' : ''} ${className}`} />,
  classes: {
    surface: SURFACE,
    tableHead: 'bg-page/60 text-start text-xs text-ink/60',
    headerAction: `${BTN} gap-2 bg-brand-700 px-4 py-2 font-semibold text-white shadow-sm hover:bg-brand-800`,
  },
}
