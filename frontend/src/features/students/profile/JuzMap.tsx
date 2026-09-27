import { useTranslation } from 'react-i18next'
import type { JuzCell } from '../../../api/students'
import { formatNumber, formatPercent } from '../../../lib/format'

/**
 * 30-cell juz map in the student's own order (Juz Amma first for backward circles).
 * Status is carried by fill + a text label per cell + the legend, never color alone:
 * memorized = solid emerald with a check, in progress = partial fill proportional to the percent, not started = outline.
 */
export default function JuzMap({ cells }: { cells: JuzCell[] }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language

  return (
    <div>
      <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink/65">
        <li className="inline-flex items-center gap-1.5"><span aria-hidden className="size-3 rounded-sm bg-brand-600" />{t('juz_map.memorized')}</li>
        <li className="inline-flex items-center gap-1.5"><span aria-hidden className="size-3 overflow-hidden rounded-sm border border-brand-600/40 bg-[linear-gradient(to_top,var(--color-brand-100)_55%,transparent_55%)]" />{t('juz_map.in_progress')}</li>
        <li className="inline-flex items-center gap-1.5"><span aria-hidden className="size-3 rounded-sm border border-ink/15 bg-white" />{t('juz_map.not_started')}</li>
      </ul>
      <ol className="grid max-w-2xl grid-cols-6 gap-1.5 sm:grid-cols-10" aria-label={t('juz_map.title')}>
        {cells.map((c) => {
          const label = t('juz_map.cell', { juz: formatNumber(c.juz, locale), percent: formatPercent(c.percent, locale), memorized: formatNumber(c.memorized, locale), total: formatNumber(c.total, locale) })
          return (
            <li key={c.juz} title={label} aria-label={label}
              className={`relative flex aspect-square flex-col items-center justify-center overflow-hidden rounded-lg text-xs tabular-nums ${
                c.status === 'memorized' ? 'bg-brand-600 text-white shadow-sm' : c.status === 'in_progress' ? 'border border-brand-600/40 bg-white text-brand-800' : 'border border-ink/10 bg-white text-ink/40'
              }`}
            >
              {c.status === 'in_progress' && <span aria-hidden className="absolute inset-x-0 bottom-0 bg-brand-100" style={{ height: `${Math.max(8, c.percent)}%` }} />}
              <span className="relative font-semibold">{formatNumber(c.juz, locale)}</span>
              {c.status === 'in_progress' && <span className="relative text-[0.65rem] leading-none">{formatPercent(c.percent, locale)}</span>}
              {c.status === 'memorized' && (
                <svg aria-hidden viewBox="0 0 24 24" className="relative size-3" fill="none" stroke="currentColor" strokeWidth={3}><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
              )}
            </li>
          )
        })}
      </ol>
    </div>
  )
}
