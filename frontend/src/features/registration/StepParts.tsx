import type { ReactNode } from 'react'

/** Step card header: "Step n of N", the step question, and one line of help. The card links to it via id="step-title". */
export function StepHeading({ step, title, help }: { step: string; title: string; help: string }) {
  return (
    <div>
      <p className="text-xs font-medium text-gold-700">{step}</p>
      <h2 id="step-title" className="mt-0.5 text-lg font-semibold text-ink">{title}</h2>
      <p className="mt-1 text-sm text-ink/60">{help}</p>
    </div>
  )
}

export function StepFooter({ children }: { children: ReactNode }) {
  return <div className="flex flex-wrap justify-between gap-3 border-t border-ink/6 pt-5">{children}</div>
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
