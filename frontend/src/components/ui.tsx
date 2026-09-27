import type { ButtonHTMLAttributes, ReactNode, TextareaHTMLAttributes } from 'react'
import { forwardRef, useEffect, useId } from 'react'
import { useTranslation } from 'react-i18next'
import { StarSpinner } from './ornaments'
import Icon from './Icon'

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
      {onRetry && <button type="button" onClick={onRetry} className="mt-3 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-ink shadow-sm">{t('retry')}</button>}
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
} as const
export type ButtonVariant = keyof typeof BUTTONS

/** Button look for elements that cannot be a <button> (router <Link>, <a>, <label>). */
export function buttonClass(variant: ButtonVariant = 'primary', className = '') {
  return `${BUTTONS[variant]} ${className}`
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

/** Radio-group styled as a segmented control (keyboard: arrow keys via native radios). */
export function Segmented<T extends string>({ name, value, options, onChange, label, size = 'md' }: {
  name: string; value: T | null; options: { value: T; label: string; tone?: Tone }[]; onChange: (v: T) => void; label: string; size?: 'sm' | 'md'
}) {
  return (
    <fieldset className="min-w-0">
      <legend className="sr-only">{label}</legend>
      <div className="inline-flex flex-wrap gap-1 rounded-xl bg-ink/5 p-1">
        {options.map((o) => {
          const active = value === o.value
          return (
            <label key={o.value} className={`cursor-pointer rounded-lg ${size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm'} font-medium transition ${
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

const INPUT_LOOK = 'block w-full rounded-xl border border-ink/15 bg-white text-sm shadow-sm placeholder:text-ink/40 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100'
const INPUT = `${INPUT_LOOK} px-3 py-2`

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
      <div data-modal className={`relative my-8 w-full ${wide ? 'max-w-2xl' : 'max-w-lg'} rounded-2xl bg-white shadow-2xl`}>
        <div className="flex items-center justify-between gap-3 border-b border-ink/8 px-5 py-4">
          <h2 id={titleId} className="text-lg font-semibold text-ink">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5 hover:text-ink" aria-label={t('close')}><Icon name="close" className="size-5" /></button>
        </div>
        <div className="space-y-4 px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-ink/8 px-5 py-3">{footer}</div>}
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
          className={`${INPUT_LOOK} py-2.5 pe-3 ps-9`} />
      </div>
    )
  },
)

/** Filter toolbar surface: controls stack full-width on phones, then wrap in a row from sm up. */
export function FilterBar({ children, className = '', label }: { children: ReactNode; className?: string; label?: string }) {
  return (
    <section aria-label={label} className={`${SURFACE} flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end ${className}`}>
      {children}
    </section>
  )
}

/** Header row style shared by data tables. */
export const TABLE_HEAD = 'bg-page/60 text-start text-xs text-ink/60'

/**
 * Horizontal scroll container for tables. `surface` draws the white table card;
 * without it the table bleeds to the edges of the Card it sits in.
 */
export function TableWrap({ children, surface = false, className = '' }: { children: ReactNode; surface?: boolean; className?: string }) {
  return surface
    ? <div className={`${SURFACE} overflow-hidden ${className}`}><div className="overflow-x-auto">{children}</div></div>
    : <div className={`-mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5 ${className}`}>{children}</div>
}
