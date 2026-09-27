import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Cell, ColumnType, ReportSection } from '../../api/reports'
import Icon from '../../components/Icon'
import { formatDate, formatNumber, formatPercent } from '../../lib/format'
import { TABLE_HEAD_STICKY } from '../../components/ui'

const NUMERIC: ColumnType[] = ['number', 'percent', 'score']
const PAGE = 50

/** Older reports send no column types; a column whose filled cells all look numeric is treated as a number. */
function guessTypes(section: ReportSection): ColumnType[] {
  return section.headings.map((_, i) => {
    const filled = section.rows.map((r) => r[i]).filter((v) => v !== null && v !== '')
    const numeric = filled.length > 0 && filled.every((v) => typeof v === 'number' || /^-?[\d.,\s]+(%| ?BHD| ?د\.ب\.?)?$/.test(String(v)))
    return numeric ? 'number' : 'text'
  })
}

function formatCell(v: Cell, type: ColumnType, locale: string): string {
  if (v === null || v === '') return '—'
  switch (type) {
    case 'number':
      return typeof v === 'number' ? formatNumber(v, locale) : String(v)
    case 'score':
      return typeof v === 'number' ? formatNumber(v, locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : String(v)
    case 'percent':
      return typeof v === 'number' ? formatPercent(v, locale) : String(v)
    case 'date':
      return /^\d{4}-\d{2}-\d{2}$/.test(String(v)) ? formatDate(String(v), locale, { day: 'numeric', month: 'short', year: 'numeric' }) : String(v)
    case 'month':
      return /^\d{4}-\d{2}$/.test(String(v)) ? formatDate(`${v}-15`, locale, { month: 'long', year: 'numeric' }) : String(v)
    default:
      return String(v)
  }
}

/** Sort key: numbers numerically (empty last), everything else by locale-aware text. */
function compare(a: Cell, b: Cell, locale: string): number {
  if (a === null || a === '') return b === null || b === '' ? 0 : 1
  if (b === null || b === '') return -1
  if (typeof a === 'number' && typeof b === 'number') return a - b
  return String(a).localeCompare(String(b), locale === 'ar' ? 'ar' : 'en', { numeric: true })
}

export default function ReportTable({ section, locale }: { section: ReportSection; locale: string }) {
  const { t } = useTranslation('reports')
  const types = useMemo(() => section.types ?? guessTypes(section), [section])
  const [sort, setSort] = useState<{ col: number; dir: 1 | -1 } | null>(null)
  const [limit, setLimit] = useState(PAGE)
  // The column that names the row: the first, unless it only holds codes (student numbers), then the next.
  const primary = useMemo(() => (section.rows.length > 0 && section.rows.every((r) => r[0] === null || /^[A-Z]{1,3}\d{3,}$/.test(String(r[0]))) ? 1 : 0), [section.rows])

  const rows = useMemo(() => {
    if (!sort) return section.rows
    return [...section.rows].sort((a, b) => sort.dir * compare(a[sort.col], b[sort.col], locale))
  }, [section.rows, sort, locale])

  const toggle = (col: number) =>
    setSort((s) => (s?.col === col ? (s.dir === 1 ? { col, dir: -1 } : null) : { col, dir: NUMERIC.includes(types[col]) ? -1 : 1 }))

  if (section.rows.length === 0) {
    return <p className="rounded-xl bg-ink/[0.03] px-4 py-6 text-center text-sm text-ink/55">{t('no_rows')}</p>
  }

  return (
    <div>
      <div className="max-h-[32rem] overflow-auto rounded-xl border border-ink/8">
        <table className="w-full min-w-max text-sm">
          <thead className={TABLE_HEAD_STICKY}>
            <tr>
              {section.headings.map((h, i) => {
                const active = sort?.col === i
                const numeric = NUMERIC.includes(types[i])
                return (
                  <th
                    key={i}
                    scope="col"
                    aria-sort={active ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none'}
                    className={`whitespace-nowrap px-3 py-2.5 font-medium text-ink/60 ${numeric ? 'text-end' : 'text-start'}`}
                  >
                    <button type="button" onClick={() => toggle(i)} className={`inline-flex items-center gap-1 hover:text-ink ${numeric ? 'flex-row-reverse' : ''}`} title={t('sort_by', { column: h })}>
                      {h}
                      <Icon name="chevron" className={`size-3.5 transition ${active ? 'opacity-100' : 'opacity-0'} ${active && sort.dir === 1 ? 'rotate-90' : '-rotate-90'}`} />
                    </button>
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {rows.slice(0, limit).map((r, ri) => (
              <tr key={ri} className="border-t border-ink/5 hover:bg-brand-50/40">
                {r.map((c, ci) => {
                  const type = types[ci] ?? 'text'
                  const numeric = NUMERIC.includes(type)
                  const ltr = type === 'phone' || type === 'datetime'
                  return (
                    <td
                      key={ci}
                      dir={ltr ? 'ltr' : 'auto'}
                      className={`px-3 py-2 ${numeric ? 'text-end tabular-nums' : 'text-start'} ${ci === primary ? 'font-medium text-ink' : 'text-ink/80'} ${ltr ? 'tabular-nums' : ''} ${type === 'text' && String(c ?? '').length > 60 ? 'min-w-[16rem] whitespace-normal' : 'whitespace-nowrap'}`}
                    >
                      {formatCell(c, type, locale)}
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-ink/55">
        <span>{t('rows', { shown: formatNumber(Math.min(limit, rows.length), locale), total: formatNumber(rows.length, locale) })}</span>
        {rows.length > limit && (
          <button type="button" className="rounded-lg px-2 py-1 font-medium text-brand-700 hover:bg-brand-50" onClick={() => setLimit(rows.length)}>
            {t('show_all')}
          </button>
        )}
      </div>
    </div>
  )
}
