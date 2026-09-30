import type { ReactNode } from 'react'

export interface ScoreRow {
  key: string
  title: ReactNode
  total?: ReactNode
  values: { label: string; value: ReactNode; danger?: boolean }[]
}

/**
 * A small table (dates × criteria) as cards below lg (spec §5: no horizontally scrolling table on mobile).
 * Each row: title and total on one line, then its values as a labelled grid.
 */
export default function ScoreRows({ rows, className = '' }: { rows: ScoreRow[]; className?: string }) {
  return (
    <ul className={`space-y-2 lg:hidden ${className}`}>
      {rows.map((r) => (
        <li key={r.key} className="rounded-card border border-ink/10 bg-white px-3.5 py-3">
          <div className="flex items-center justify-between gap-3">
            <span className="min-w-0 truncate text-[15px] font-semibold text-ink">{r.title}</span>
            {r.total !== undefined && <span className="shrink-0 text-[15px] font-semibold tabular-nums text-ink">{r.total}</span>}
          </div>
          <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 sm:grid-cols-4">
            {r.values.map((v) => (
              <div key={v.label} className="min-w-0">
                <dt className="truncate text-xs text-ink/65">{v.label}</dt>
                <dd className={`text-[15px] tabular-nums ${v.danger ? 'font-semibold text-danger' : 'text-ink'}`}>{v.value}</dd>
              </div>
            ))}
          </dl>
        </li>
      ))}
    </ul>
  )
}
