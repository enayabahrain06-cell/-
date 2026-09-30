import type { ButtonHTMLAttributes, ReactNode, TextareaHTMLAttributes } from 'react'
import { forwardRef, useEffect, useId } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState, StarSpinner } from './ornaments'
import Icon from './Icon'
import { useScrollLock } from './useScrollLock'

/** Shared building blocks for staff pages (cards, states, badges, segmented controls, text areas). */

/** White card surface; add padding at the call site (`p-4 sm:p-5` is the standard). */
export const SURFACE = 'rounded-2xl border border-ink/8 bg-white shadow-sm'

export function Card({ children, className = '', as: Tag = 'section', ...rest }: { children: ReactNode; className?: string; as?: 'section' | 'div' | 'article' } & Record<string, unknown>) {
  return (
    <Tag className={`${SURFACE} p-4 sm:p-5 ${className}`} {...rest}>
      {children}
    </Tag>
  )
}

export function CardTitle({ children, actions, id }: { children: ReactNode; actions?: ReactNode; id?: string }) {
  return (
    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h2 id={id} className="text-base font-semibold text-ink">{children}</h2>
      {actions}
    </div>
  )
}

export function LoadingState() {
  const { t } = useTranslation()
  return <div className="grid place-items-center py-20"><StarSpinner className="size-10 text-brand-600" label={t('loading')} /></div>
}

export function ErrorState({ message, onRetry }: { message?: string; onRetry?: () => void }) {
  const { t } = useTranslation()
  return (
    <div role="alert" className="rounded-2xl border border-danger/25 bg-danger/5 p-6 text-center text-danger">
      <p>{message ?? t('errors.unexpected')}</p>
      {onRetry && <button type="button" onClick={onRetry} className={buttonClass('secondary', 'mt-3')}>{t('retry')}</button>}
    </div>
  )
}

const TONES = {
  brand: 'bg-brand-50 text-brand-700',
  gold: 'bg-gold-500/12 text-gold-700',
  danger: 'bg-danger/10 text-danger',
  info: 'bg-info/10 text-info-700',
  muted: 'bg-ink/6 text-ink/65',
} as const
export type Tone = keyof typeof TONES

export function Badge({ tone = 'muted', children, className = '' }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${TONES[tone]} ${className}`}>{children}</span>
}

const BUTTON_BASE = 'inline-flex items-center justify-center rounded-xl text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed'
const BUTTONS = {
  primary: `${BUTTON_BASE} gap-2 bg-brand-700 px-4 py-2 font-semibold text-white shadow-sm hover:bg-brand-800 active:bg-brand-900 disabled:opacity-60`,
  danger: `${BUTTON_BASE} gap-2 bg-danger px-4 py-2 font-semibold text-white shadow-sm hover:bg-danger/90 disabled:opacity-60`,
  secondary: `${BUTTON_BASE} gap-1.5 border border-ink/12 bg-white px-3 py-2 font-medium text-ink/80 hover:bg-ink/5 disabled:opacity-50`,
  /** White button on the deep PageBand surface. */
  onDeep: `${BUTTON_BASE} gap-2 bg-white px-4 py-2 font-semibold text-brand-800 shadow-sm hover:bg-white/90 disabled:opacity-60`,
  /** Quiet outlined button on the deep PageBand surface (refresh and other secondary band actions). */
  onDeepGhost: `${BUTTON_BASE} gap-1.5 border border-white/20 bg-white/10 px-3 py-2 font-medium text-white hover:bg-white/15 disabled:opacity-60`,
} as const
export type ButtonVariant = keyof typeof BUTTONS

/**
 * Button look for elements that cannot be a <button> (router <Link>, <a>, <label>).
 * Buttons are 40px tall, the same as inputs, selects and search, so filter rows line up.
 * Compact row buttons that pass their own `py-1`/`py-1.5` (or a `min-h-*`) keep their size.
 */
export function buttonClass(variant: ButtonVariant = 'primary', className = '') {
  const height = /(^|\s)(min-h-|py-(0|1)(\.5)?(\s|$))/.test(className) ? '' : 'min-h-10'
  return `${BUTTONS[variant]} ${height} ${className}`
}

export function PrimaryButton({ children, className = '', loading, tone = 'brand', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean; tone?: 'brand' | 'danger' }) {
  return (
    <button type="button" {...rest} disabled={rest.disabled || loading} aria-busy={loading}
      className={buttonClass(tone === 'danger' ? 'danger' : 'primary', className)}>
      {loading && <StarSpinner className="size-4 text-white" />}
      {children}
    </button>
  )
}

export function SecondaryButton({ children, className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" {...rest}
      className={buttonClass('secondary', className)}>
      {children}
    </button>
  )
}

/**
 * Small icon-only button (remove a row, dismiss a notice). `label` is required: it is the
 * accessible name and the tooltip. `remove` is muted until hover, then danger.
 */
/**
 * Icon-only button. `size="md"` gives a 40px tap target (touch pages, reorder rows) while the icon stays 16px;
 * `iconClassName` rotates or recolours the icon (for example `-rotate-90` for a vertical chevron).
 */
export function IconButton({ icon, label, tone = 'muted', size = 'sm', iconClassName = '', className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { icon: string; label: string; tone?: 'muted' | 'remove' | 'danger'; size?: 'sm' | 'md'; iconClassName?: string }) {
  const tones = {
    muted: 'text-ink/50 hover:bg-ink/5 hover:text-ink',
    remove: 'text-ink/40 hover:bg-danger/5 hover:text-danger',
    danger: 'text-danger hover:bg-danger/5',
  }
  return (
    <button type="button" aria-label={label} title={label} {...rest}
      className={`inline-grid shrink-0 place-items-center rounded-lg ${size === 'md' ? 'size-10' : 'p-1.5'} transition focus-visible:outline-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-30 ${tones[tone]} ${className}`}>
      <Icon name={icon} className={`size-4 ${iconClassName}`} />
    </button>
  )
}

/** Radio-group styled as a segmented control (keyboard: arrow keys via native radios). */
export function Segmented<T extends string>({ name, value, options, onChange, label, size = 'md', fill = false }: {
  name: string; value: T | null; options: { value: T; label: string; tone?: Tone }[]; onChange: (v: T) => void; label: string; size?: 'sm' | 'md'
  /** Below `sm`, stretch to the full row with equal-width options (for a control that wraps onto its own line). */
  fill?: boolean
}) {
  return (
    <fieldset className={fill ? 'w-full min-w-0 sm:w-auto' : 'min-w-0'}>
      <legend className="sr-only">{label}</legend>
      <div className={`${fill ? 'flex sm:inline-flex' : 'inline-flex'} flex-wrap gap-1 rounded-xl bg-ink/5 p-1`}>
        {options.map((o) => {
          const active = value === o.value
          return (
            <label key={o.value} className={`cursor-pointer rounded-lg ${fill ? 'flex-1 text-center sm:flex-none' : ''} ${size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm'} font-medium transition ${
              active ? `${o.tone ? TONES[o.tone] : 'bg-white text-ink'} shadow-sm ring-1 ring-ink/10` : 'text-ink/60 hover:text-ink'
            } has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500`}>
              <input type="radio" name={name} value={o.value} checked={active} onChange={() => onChange(o.value)} className="sr-only" />
              {o.label}
            </label>
          )
        })}
      </div>
    </fieldset>
  )
}

const FIELD_LOOK = 'block border shadow-sm placeholder:text-ink/40 focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-60 aria-invalid:border-danger/50 aria-invalid:bg-danger/5 aria-invalid:text-danger aria-invalid:focus:ring-danger/15'
const FIELD_TONE = {
  normal: 'border-ink/15 bg-white text-ink focus:border-brand-500 focus:ring-brand-100',
  /** A warning value (for example a score below the pass mark); not the same as invalid. */
  danger: 'border-danger/50 bg-danger/5 text-danger focus:border-danger focus:ring-danger/15',
} as const
const FIELD_SIZE = { md: 'min-h-10 rounded-xl px-3 py-2', sm: 'rounded-lg px-2 py-1.5' } as const

/**
 * Look of a bare <input>/<select>/<textarea> for places a labelled TextInput cannot go
 * (table cells, inline rows, date pickers). Width and font size come from `className`
 * (default `text-sm`). Invalid values set aria-invalid; `danger` marks a valid but alarming value.
 */
export function inputClass(size: keyof typeof FIELD_SIZE = 'md', className = '', danger = false) {
  const text = /(^|\s)text-(xs|sm|base|lg|xl|2xl)\b/.test(className) ? '' : 'text-sm'
  return `${FIELD_LOOK} ${FIELD_TONE[danger ? 'danger' : 'normal']} ${FIELD_SIZE[size]} ${text} ${className}`
}

const INPUT = inputClass('md', 'w-full')

export const TextArea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hideLabel?: boolean }>(
  function TextArea({ label, hideLabel, className = '', id, ...rest }, ref) {
    const auto = useId()
    const tid = id ?? auto
    return (
      <div className={className}>
        <label htmlFor={tid} className={hideLabel ? 'sr-only' : 'mb-1.5 block text-sm font-medium text-ink/75'}>{label}</label>
        <textarea ref={ref} id={tid} rows={3} {...rest}
          className={INPUT} />
      </div>
    )
  },
)

export const TextInput = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement> & { label: string; hideLabel?: boolean }>(
  function TextInput({ label, hideLabel, className = '', id, ...rest }, ref) {
    const auto = useId()
    const tid = id ?? auto
    return (
      <div className={className}>
        <label htmlFor={tid} className={hideLabel ? 'sr-only' : 'mb-1.5 block text-sm font-medium text-ink/75'}>{label}</label>
        <input ref={ref} id={tid} {...rest}
          className={INPUT} />
      </div>
    )
  },
)

export const NOTICE_TONES = {
  error: 'border-danger/25 bg-danger/5 text-danger',
  info: 'border-info/20 bg-info/5 text-info-700',
  success: 'border-brand-100 bg-brand-50 text-brand-800',
} as const

/** Status line under a form after save (role=status so screen readers announce it). */
export function Notice({ tone = 'success', children }: { tone?: 'success' | 'error' | 'info'; children: ReactNode }) {
  const cls = NOTICE_TONES[tone]
  return <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-4 py-2.5 text-sm ${cls}`}>{children}</div>
}

/** Accessible modal: overlay click and Esc close it; the first focusable field receives focus. */
export function Modal({ title, onClose, children, footer, wide = false }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const { t } = useTranslation()
  const titleId = useId()
  useScrollLock(true)
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    const el = document.querySelector<HTMLElement>('[data-modal] input, [data-modal] select, [data-modal] textarea')
    el?.focus()
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <button type="button" tabIndex={-1} className="fixed inset-0 bg-ink/50" aria-hidden onClick={onClose} />
      {/* Header and footer stay in view; a long form scrolls inside the body, never the page. */}
      <div data-modal className={`relative flex max-h-[calc(100dvh-2rem)] w-full min-w-0 flex-col ${wide ? 'max-w-2xl' : 'max-w-lg'} rounded-2xl bg-white shadow-2xl`}>
        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-ink/8 px-5 py-4">
          <h2 id={titleId} className="min-w-0 text-lg font-semibold text-ink">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5 hover:text-ink" aria-label={t('close')}><Icon name="close" className="size-5" /></button>
        </div>
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overflow-x-hidden px-5 py-4">{children}</div>
        {footer && <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-ink/8 px-5 py-3">{footer}</div>}
      </div>
    </div>
  )
}

/** Search box with a leading icon; the label is visually hidden and reused as the placeholder. */
export const SearchInput = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement> & { label: string }>(
  function SearchInput({ label, className = '', id, placeholder, ...rest }, ref) {
    const auto = useId()
    const sid = id ?? auto
    return (
      <div className={`relative min-w-0 ${className}`}>
        <label htmlFor={sid} className="sr-only">{label}</label>
        <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-ink/40" />
        <input ref={ref} id={sid} type="search" autoComplete="off" placeholder={placeholder ?? label} {...rest}
          className={`${FIELD_LOOK} ${FIELD_TONE.normal} min-h-10 w-full rounded-xl py-2 pe-3 ps-9 text-sm`} />
      </div>
    )
  },
)

/**
 * Filter toolbar surface: controls stack full-width on phones, then wrap in a row from sm up.
 * `layout="grid"` keeps a page's own column template (pass `sm:grid-cols-*` in className);
 * `layout="row"` keeps small controls on one wrapping row at every width (date steppers).
 */
const FILTER_LAYOUT = {
  stack: 'flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end',
  grid: 'grid gap-3',
  row: 'flex flex-wrap items-center gap-2',
} as const
export function FilterBar({ children, className = '', label, layout = 'stack' }: { children: ReactNode; className?: string; label?: string; layout?: keyof typeof FILTER_LAYOUT }) {
  return (
    <section aria-label={label} className={`${SURFACE} ${FILTER_LAYOUT[layout]} p-4 ${className}`}>
      {children}
    </section>
  )
}

/** Empty state inside the standard white card (list pages with no rows). */
export function EmptyCard(props: Parameters<typeof EmptyState>[0]) {
  return <div className={SURFACE}><EmptyState {...props} /></div>
}

/**
 * Main (name) cell of a wrapping list row: `<li className="flex flex-wrap items-center gap-3">`.
 * It grows to fill the row but keeps at least 12rem, so trailing badges and actions drop to the
 * next line instead of squeezing the name to a few letters. Written as one flex value so no
 * separate basis utility can override it.
 */
export const ROW_MAIN = 'min-w-0 flex-[1_1_12rem]'

/** Header row style shared by data tables. */
export const TABLE_HEAD = 'bg-page/60 text-start text-xs text-ink/60'
/** Same header for tables that scroll inside a fixed-height box (opaque, stays on top). */
export const TABLE_HEAD_STICKY = 'sticky top-0 z-10 bg-page text-start text-xs text-ink/60 shadow-[0_1px_0_rgba(27,43,40,0.08)]'

/**
 * Horizontal scroll container for tables. `surface` draws the white table card;
 * without it the table bleeds to the edges of the Card it sits in.
 */
export function TableWrap({ children, surface = false, className = '' }: { children: ReactNode; surface?: boolean; className?: string }) {
  return surface
    // `relative`: absolutely positioned children (sr-only header labels) stay inside the scroll box, not the page.
    ? <div className={`${SURFACE} overflow-hidden ${className}`}><div className="relative overflow-x-auto">{children}</div></div>
    : <div className={`relative -mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5 ${className}`}>{children}</div>
}
