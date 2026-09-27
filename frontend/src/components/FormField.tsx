import { forwardRef, useId, type InputHTMLAttributes, type ReactNode } from 'react'

interface Props extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
  hint?: ReactNode
  end?: ReactNode
}

/**
 * Labelled input with error text wired through aria-describedby.
 * forwardRef is required on React 18: without it react-hook-form's register() ref is dropped
 * and every field submits undefined.
 */
const FormField = forwardRef<HTMLInputElement, Props>(function FormField({ label, error, hint, end, id, className = '', ...input }, ref) {
  const autoId = useId()
  const inputId = id ?? autoId
  const msgId = `${inputId}-msg`

  return (
    <div className={className}>
      <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-ink/75">
        {label}
      </label>
      <div className="relative">
        <input
          ref={ref}
          id={inputId}
          aria-invalid={!!error}
          aria-describedby={error || hint ? msgId : undefined}
          className={`block w-full rounded-xl border bg-white px-4 py-3 text-base shadow-sm transition placeholder:text-ink/40 focus:outline-none focus:ring-4 ${
            end ? 'pe-20' : ''
          } ${
            error
              ? 'border-danger/60 focus:border-danger focus:ring-danger/15'
              : 'border-ink/15 focus:border-brand-500 focus:ring-brand-100'
          }`}
          {...input}
        />
        {end && <div className="absolute inset-y-0 end-0 flex items-center pe-2">{end}</div>}
      </div>
      {(error || hint) && (
        <p id={msgId} className={`mt-1.5 text-sm ${error ? 'text-danger' : 'text-ink/55'}`}>
          {error || hint}
        </p>
      )}
    </div>
  )
})

export default FormField
