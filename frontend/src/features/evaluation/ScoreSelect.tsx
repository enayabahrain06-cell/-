import { inputClass } from '../../components/ui'
import { formatNumber } from '../../lib/format'

/** 0–10 integer score as a compact native select (fast on phones, fully keyboard accessible). */
export default function ScoreSelect({ id, label, value, onChange, threshold, locale, labelClassName = '' }: {
  id: string
  label: string
  /** Extra classes for the label, e.g. `xl:sr-only` where a column header already names the score. */
  labelClassName?: string
  value: number | null
  onChange: (v: number | null) => void
  threshold: number
  locale: string
}) {
  const low = value !== null && value < threshold
  return (
    <div className="min-w-0">
      <label htmlFor={id} className={`mb-1 block truncate text-xs text-ink/55 ${labelClassName}`}>{label}</label>
      <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        className={inputClass('sm', 'select-chevron min-h-10 w-full appearance-none bg-[length:0.875rem] bg-[position:left_0.5rem_center] bg-no-repeat ps-2 pe-7 text-center text-base font-semibold tabular-nums ltr:bg-[position:right_0.5rem_center]', low)}>
        <option value="">—</option>
        {Array.from({ length: 11 }, (_, i) => 10 - i).map((n) => <option key={n} value={n}>{formatNumber(n, locale)}</option>)}
      </select>
    </div>
  )
}
