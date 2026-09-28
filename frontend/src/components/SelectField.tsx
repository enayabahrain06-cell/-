import { forwardRef, useId, type SelectHTMLAttributes } from 'react'

interface Props extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string
  options: { value: string; label: string }[]
  hideLabel?: boolean
}

/**
 * Labelled native select (keyboard and screen-reader friendly, RTL-aware chevron).
 * forwardRef: React 18 drops `ref` on function components, and react-hook-form register() needs it.
 */
const SelectField = forwardRef<HTMLSelectElement, Props>(function SelectField({ label, options, hideLabel, className = '', id, ...rest }, ref) {
  const auto = useId()
  const selectId = id ?? auto

  return (
    <div className={className}>
      <label htmlFor={selectId} className={hideLabel ? 'sr-only' : 'mb-1.5 block text-sm font-medium text-ink/75'}>
        {label}
      </label>
      <select
        ref={ref}
        id={selectId}
        className="block w-full appearance-none rounded-xl border border-ink/15 bg-white bg-[length:1rem] bg-[position:left_0.75rem_center] bg-no-repeat min-h-10 py-2 pe-9 ps-3 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 ltr:bg-[position:right_0.75rem_center]"
        style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%231B2B28' stroke-opacity='.5' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }}
        {...rest}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  )
})

export default SelectField
