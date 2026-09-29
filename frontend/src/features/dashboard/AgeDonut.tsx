import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Cell, Pie, PieChart, ResponsiveContainer } from 'recharts'
import type { AgeBand, AgeDistribution } from '../../api/dashboard'
import Icon from '../../components/Icon'
import { EmptyState } from '../../components/ornaments'
import { formatNumber, formatPercent } from '../../lib/format'
import { SURFACE, TableWrap } from '../../components/ui'

/**
 * Ordinal one-hue ramp (brand green, light → dark = young → old). Validated with the dataviz
 * validator (--ordinal, white surface): monotone lightness, adjacent ΔL ≥ 0.06, light end 2.11:1.
 * Identity is also carried by the legend (always visible), the centre hover readout and the table view.
 */
const RAMP = ['#84bf9f', '#5fa882', '#3f8d66', '#2f734f', '#23593d', '#173f2b']

export default function AgeDonut({ data }: { data: AgeDistribution }) {
  const { t, i18n } = useTranslation('dashboard')
  const locale = i18n.language
  const [asTable, setAsTable] = useState(false)
  const [active, setActive] = useState<number | null>(null)
  const n = (v: number) => formatNumber(v, locale)
  const pct = (c: number) => formatPercent(data.total ? Math.round((c * 100) / data.total) : null, locale)
  const label = (b: AgeBand) => (b.max === null ? t('age.band_plus', { min: n(b.min) }) : b.min === 0 ? t('age.band_upto', { max: n(b.max) }) : t('age.band', { min: n(b.min), max: n(b.max) }))
  const bands = data.bands.map((b, i) => ({ ...b, label: label(b), color: RAMP[i] ?? RAMP[RAMP.length - 1] }))
  const shown = active !== null ? bands[active] : null

  return (
    <section className={`${SURFACE} @container flex flex-col p-4 sm:p-5`} aria-labelledby="age-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="age-title" className="text-base font-semibold text-ink">{t('age.title')}</h2>
          <p className="text-sm text-ink/55">
            {data.average !== null ? t('age.subtitle', { avg: formatNumber(data.average, locale, { maximumFractionDigits: 1 }) }) : t('age.subtitle_empty')}
          </p>
        </div>
        {data.total > 0 && (
          <button
            type="button"
            onClick={() => setAsTable((v) => !v)}
            className="inline-flex items-center gap-1.5 rounded-lg border border-ink/10 px-2.5 py-1.5 text-sm text-ink/70 hover:bg-ink/5"
          >
            <Icon name={asTable ? 'chart' : 'table'} className="size-4" />
            {asTable ? t('chart.show_chart') : t('chart.show_table')}
          </button>
        )}
      </div>

      {data.total === 0 ? (
        <EmptyState size="sm" icon="students" title={t('age.empty')} />
      ) : asTable ? (
        <TableWrap className="mt-4">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-ink/10 text-ink/55">
                <th className="py-2 text-start font-medium">{t('age.age')}</th>
                <th className="py-2 text-end font-medium">{t('age.students')}</th>
                <th className="py-2 text-end font-medium">{t('age.share')}</th>
              </tr>
            </thead>
            <tbody>
              {bands.map((b) => (
                <tr key={b.key} className="border-b border-ink/5 last:border-0">
                  <td className="py-2 text-ink">{b.label}</td>
                  <td className="py-2 text-end tabular-nums text-ink/80">{n(b.count)}</td>
                  <td className="py-2 text-end tabular-nums text-ink/80">{pct(b.count)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      ) : (
        <div className="mt-3 flex flex-1 flex-col items-center justify-center gap-4 @md:flex-row @md:gap-8">
          {/* By the card width, not the viewport: donut beside its legend from 28rem, stacked in a narrow column */}
          <div className="relative size-40 shrink-0" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={bands}
                  dataKey="count"
                  nameKey="label"
                  innerRadius="62%"
                  outerRadius="100%"
                  startAngle={90}
                  endAngle={locale === 'ar' ? 450 : -270}
                  stroke="#ffffff"
                  strokeWidth={2}
                  cornerRadius={4}
                  isAnimationActive={false}
                  onMouseEnter={(_, i) => setActive(i)}
                  onMouseLeave={() => setActive(null)}
                >
                  {bands.map((b, i) => (
                    <Cell key={b.key} fill={b.color} fillOpacity={active === null || active === i ? 1 : 0.35} />
                  ))}
                </Pie>
              </PieChart>
            </ResponsiveContainer>
            {/* Centre is the hover readout: total, or the hovered band with its share (no floating tooltip over it) */}
            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center" dir="auto">
              <div>
                <p className="text-2xl font-semibold tabular-nums text-ink">{n(shown ? shown.count : data.total)}</p>
                <p className="text-xs text-ink/55">{shown ? `${shown.label} · ${pct(shown.count)}` : t('age.students')}</p>
              </div>
            </div>
          </div>

          <ul className="w-full min-w-0 space-y-1 text-sm @md:max-w-sm @md:flex-1">
            {bands.map((b, i) => (
              <li
                key={b.key}
                onMouseEnter={() => setActive(i)}
                onMouseLeave={() => setActive(null)}
                className={`flex items-center gap-2 rounded-lg px-2 py-1 ${active === i ? 'bg-ink/5' : ''}`}
              >
                <span className="inline-block size-2.5 shrink-0 rounded-sm" style={{ background: b.color }} aria-hidden />
                <span className="flex-1 whitespace-nowrap text-ink/75">{b.label}</span>
                <span className="tabular-nums font-medium text-ink">{n(b.count)}</span>
                <span className="w-11 text-end tabular-nums text-ink/50">{pct(b.count)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

