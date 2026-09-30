import type { ReactNode } from 'react'

/** Step card header: "Step n of N", the step question, and one line of help. The card links to it via id="step-title". */
export function StepHeading({ step, title, help }: { step: string; title: string; help: string }) {
  return (
    <div>
      {/* Below lg the page's progress line already says "step n of N". */}
      <p className="text-xs font-medium text-gold-700 max-lg:hidden">{step}</p>
      <h2 id="step-title" className="mt-0.5 text-lg font-semibold text-ink">{title}</h2>
      <p className="mt-1 text-sm text-ink/60">{help}</p>
    </div>
  )
}

/**
 * Back + next. Below lg it is the sticky action bar (spec §4.5): pinned to the bottom edge, the primary (last child)
 * fills the row at 48px. PublicLayout's bottom padding keeps the end of the step clear of it.
 */
export function StepFooter({ children }: { children: ReactNode }) {
  return <div className={`flex flex-wrap justify-between gap-3 border-t border-ink/6 pt-5 ${STEP_BAR}`}>{children}</div>
}
const STEP_BAR = 'max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:flex-nowrap max-lg:gap-2.5 max-lg:border-ink/10 max-lg:bg-white max-lg:px-4 max-lg:pb-[max(1.25rem,env(safe-area-inset-bottom))] max-lg:pt-3 max-lg:[&>*]:min-h-12 max-lg:[&>*]:rounded-ctl max-lg:[&>*:last-child]:flex-1'

/** The same bar around a single primary button (first step). */
export function StepBar({ children }: { children: ReactNode }) {
  return <div className={`max-lg:flex max-lg:border-t ${STEP_BAR}`}>{children}</div>
}

export function FormSection({ title, aside, children }: { title: string; aside?: ReactNode; children: ReactNode }) {
  return (
    <fieldset className="min-w-0">
      <legend className="mb-3 flex w-full items-center gap-2 text-base font-semibold text-ink">{title}{aside}</legend>
      {children}
    </fieldset>
  )
}

export function Field({ children, err, hint, className = '' }: { children: ReactNode; err?: string; hint?: string; className?: string }) {
  return (
    <div className={className}>
      {children}
      {hint && !err && <p className="mt-1 text-xs text-ink/50">{hint}</p>}
      {err && <p className="mt-1 text-sm text-danger">{err}</p>}
    </div>
  )
}
