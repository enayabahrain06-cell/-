import { useEffect, useId, type ButtonHTMLAttributes, type ComponentType, type ReactNode } from 'react'

/**
 * The few components the package draws. A host passes its own through IdCardProvider's `ui`; these defaults use
 * Tailwind classes and the colour tokens in theme.css (brand, ink, gold, danger).
 */
export interface UiKit {
  PrimaryButton: ComponentType<ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean }>
  SecondaryButton: ComponentType<ButtonHTMLAttributes<HTMLButtonElement>>
  Modal: ComponentType<{ title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }>
  Notice: ComponentType<{ tone?: 'success' | 'error' | 'info'; children: ReactNode }>
  /** Icon shown on the read button, or null for none. `name` is always "id-card". */
  Icon: ComponentType<{ name: string; className?: string }> | null
}

const BTN = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-60'

function PrimaryButton({ children, className = '', loading, disabled, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { loading?: boolean }) {
  return (
    <button type="button" {...rest} disabled={disabled || loading} aria-busy={loading || undefined} className={`${BTN} bg-brand-700 text-white hover:bg-brand-800 ${className}`}>
      {loading && <span aria-hidden className="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />}
      {children}
    </button>
  )
}

function SecondaryButton({ children, className = '', ...rest }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return <button type="button" {...rest} className={`${BTN} border border-ink/15 bg-white text-ink hover:bg-ink/5 ${className}`}>{children}</button>
}

function Modal({ title, onClose, children, footer, wide = false }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const id = useId()
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])
  return (
    <div className="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby={id}>
      <button type="button" aria-label="Close" className="absolute inset-0 bg-ink/50" onClick={onClose} />
      <div className={`relative flex max-h-[90vh] w-full flex-col rounded-2xl bg-white shadow-2xl ${wide ? 'max-w-2xl' : 'max-w-lg'}`}>
        <h2 id={id} className="border-b border-ink/8 px-5 py-4 text-lg font-semibold text-ink">{title}</h2>
        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-ink/8 px-5 py-3">{footer}</div>}
      </div>
    </div>
  )
}

const NOTICE = { success: 'border-brand-600/20 bg-brand-50 text-brand-800', error: 'border-danger/25 bg-danger/5 text-danger', info: 'border-ink/10 bg-ink/5 text-ink/80' } as const

function Notice({ tone = 'success', children }: { tone?: 'success' | 'error' | 'info'; children: ReactNode }) {
  return <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-4 py-3 text-sm ${NOTICE[tone]}`}>{children}</div>
}

export const defaultUi: UiKit = { PrimaryButton, SecondaryButton, Modal, Notice, Icon: null }
