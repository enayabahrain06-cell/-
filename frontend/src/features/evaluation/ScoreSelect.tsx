import { formatNumber } from '../../lib/format'

/** 0–10 integer score as a compact native select (fast on phones, fully keyboard accessible). */
export default function ScoreSelect({ id, label, value, onChange, threshold, locale }: {
  id: string
  label: string
  value: number | null
  onChange: (v: number | null) => void
  threshold: number
  locale: string
}) {
  const low = value !== null && value < threshold
  return (
    <div className="min-w-0">
      <label htmlFor={id} className="mb-1 block truncate text-xs text-ink/55">{label}</label>
      <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        className={`w-full rounded-lg border px-2 py-1.5 text-center text-base font-semibold tabular-nums shadow-sm focus:outline-none focus:ring-4 ${
          low ? 'border-danger/50 bg-danger/5 text-danger focus:ring-danger/15' : 'border-ink/15 bg-white text-ink focus:border-brand-500 focus:ring-brand-100'
        }`}
>
        <option value="">—</option>
        {Array.from({ length: 11 }, (_, i) => 10 - i).map((n) => <option key={n} value={n}>{formatNumber(n, locale)}</option>)}
      </select>
    </div>
  )
}
