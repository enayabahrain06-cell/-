import { forwardRef, useId, type SelectHTMLAttributes } from 'react'

interface Props extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  options: { value: string; label: string }[]
  hideLabel?: boolean
  error?: string
}

/**
 * Labelled native select (keyboard and screen-reader friendly, RTL-aware chevron).
 * forwardRef: React 18 drops `ref` on function components, and react-hook-form register() needs it.
 */
const SelectField = forwardRef<HTMLSelectElement, Props>(function SelectField({ label, options, hideLabel, error, className = '', id, ...rest }, ref) {
  const auto = useId()
  const selectId = id ?? auto
  const msgId = `${selectId}-msg`

  return (
    <div className={className}>
      <label htmlFor={selectId} className={hideLabel ? 'sr-only' : 'mb-1.5 block text-sm font-medium text-ink/75'}>
        {label}
      </label>
      <select
        ref={ref}
        id={selectId}
        aria-invalid={!!error}
        aria-describedby={error ? msgId : undefined}
        className={`block w-full select-chevron appearance-none rounded-xl border ${error ? 'border-danger/60 focus:border-danger focus:ring-danger/15' : 'border-ink/15 focus:border-brand-500 focus:ring-brand-100'} bg-white bg-[length:1rem] bg-[position:left_0.75rem_center] bg-no-repeat min-h-10 py-2 pe-9 ps-3 text-sm text-ink shadow-sm focus:outline-none focus:ring-4 ltr:bg-[position:right_0.75rem_center]`}
        {...rest}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      {error && (
        <p id={msgId} className="mt-1.5 text-sm text-danger">
          {error}
        </p>
      )}
    </div>
  )
})

export default SelectField
