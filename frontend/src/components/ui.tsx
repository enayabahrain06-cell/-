import type { ButtonHTMLAttributes, ReactNode, TextareaHTMLAttributes } from 'react'
import { forwardRef, useEffect, useId } from 'react'
import { useTranslation } from 'react-i18next'
import { StarSpinner } from './ornaments'

/** Shared building blocks for staff pages (cards, states, badges, segmented controls, text areas). */

export function Card({ children, className = '', as: Tag = 'section', ...rest }: { children: ReactNode; className?: string; as?: 'section' | 'div' | 'article' } & Record<string, unknown>) {
  return (
    <Tag className={`rounded-2xl border border-ink/8 bg-white p-4 shadow-sm sm:p-5 ${className}`} {...rest}>
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
  info: 'bg-[#3F74C0]/10 text-[#2F5E9E]',
  muted: 'bg-ink/6 text-ink/65',
} as const
export type Tone = keyof typeof TONES

export function Badge({ tone = 'muted', children, className = '' }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${TONES[tone]} ${className}`}>{children}</span>
}

export function PrimaryButton({ children, className = '', loading, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean }) {
  return (
    <button type="button" {...rest} disabled={rest.disabled || loading} aria-busy={loading}
      className={`inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-60 ${className}`}>
      {loading && <StarSpinner className="size-4 text-white" />}
      {children}
    </button>
  )
}

export function SecondaryButton({ children, className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" {...rest}
      className={`inline-flex items-center justify-center gap-1.5 rounded-xl border border-ink/12 bg-white px-3 py-2 text-sm font-medium text-ink/80 transition hover:bg-ink/5 disabled:cursor-not-allowed disabled:opacity-50 ${className}`}>
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

export const TextArea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hideLabel?: boolean }>(
  function TextArea({ label, hideLabel, className = '', id, ...rest }, ref) {
    const auto = useId()
    const tid = id ?? auto
    return (
      <div className={className}>
        <label htmlFor={tid} className={hideLabel ? 'sr-only' : 'mb-1.5 block text-sm font-medium text-ink/75'}>{label}</label>
        <textarea ref={ref} id={tid} rows={3} {...rest}
          className="block w-full rounded-xl border border-ink/15 bg-white px-3 py-2 text-sm shadow-sm placeholder:text-ink/40 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100" />
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
          className="block w-full rounded-xl border border-ink/15 bg-white px-3 py-2 text-sm shadow-sm placeholder:text-ink/40 focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100" />
      </div>
    )
  },
)

/** Status line under a form after save (role=status so screen readers announce it). */
export function Notice({ tone = 'success', children }: { tone?: 'success' | 'error' | 'info'; children: ReactNode }) {
  const cls = tone === 'error' ? 'border-danger/25 bg-danger/5 text-danger' : tone === 'info' ? 'border-[#3F74C0]/20 bg-[#3F74C0]/5 text-[#2F5E9E]' : 'border-brand-100 bg-brand-50 text-brand-800'
  return <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-4 py-2.5 text-sm ${cls}`}>{children}</div>
}

/** Accessible modal: overlay click and Esc close it; the first focusable field receives focus. */
export function Modal({ title, onClose, children, footer, wide = false }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
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
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-ink/50 hover:bg-ink/5 hover:text-ink" aria-label="×">✕</button>
        </div>
        <div className="space-y-4 px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-ink/8 px-5 py-3">{footer}</div>}
      </div>
    </div>
  )
}
