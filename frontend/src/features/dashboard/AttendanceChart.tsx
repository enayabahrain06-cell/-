import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { AttendanceDay } from '../../api/dashboard'
import Icon from '../../components/Icon'
import { EmptyState } from '../../components/ornaments'
import { formatDate, formatNumber, formatPercent, formatWeekday } from '../../lib/format'

/**
 * Stacked daily attendance. Palette validated (dataviz validator, light surface): all checks pass;
 * green↔gold sits in the CVD 6–8 band, so identity is also carried by the legend, 2px segment gaps,
 * the tooltip and the table view.
 */
const SERIES = [
  { key: 'present', color: '#2E8B57' },
  { key: 'late', color: '#B8872E' },
  { key: 'absent', color: '#B0413A' },
  { key: 'excused', color: '#3F74C0' },
] as const

type SeriesKey = (typeof SERIES)[number]['key']

export default function AttendanceChart({ days }: { days: AttendanceDay[] }) {
  const { t, i18n } = useTranslation('dashboard')
  const locale = i18n.language
  const [asTable, setAsTable] = useState(false)
  const rtl = locale === 'ar'
  const hasData = days.some((d) => d.present + d.late + d.absent + d.excused > 0)
  // Recharts draws left-to-right; in Arabic the timeline reads right-to-left.
  const data = rtl ? [...days].reverse() : days

  return (
    <section className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="attendance-chart-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="attendance-chart-title" className="text-base font-semibold text-ink">{t('chart.title')}</h2>
          <p className="text-sm text-ink/55">{t('chart.subtitle')}</p>
        </div>
        <button
          type="button"
          onClick={() => setAsTable((v) => !v)}
          className="inline-flex items-center gap-1.5 rounded-lg border border-ink/10 px-2.5 py-1.5 text-sm text-ink/70 hover:bg-ink/5"
        >
          <Icon name={asTable ? 'chart' : 'table'} className="size-4" />
          {asTable ? t('chart.show_chart') : t('chart.show_table')}
        </button>
      </div>

      {/* Legend: always present for multiple series */}
      <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink/70">
        {SERIES.map((s) => (
          <li key={s.key} className="inline-flex items-center gap-1.5">
            <span className="inline-block size-2.5 rounded-sm" style={{ background: s.color }} aria-hidden />
            {t(`chart.${s.key}`)}
          </li>
        ))}
      </ul>

      {!hasData ? (
        <EmptyState size="sm" icon="chart" title={t('chart.empty')} />
      ) : asTable ? (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[32rem] text-sm">
            <thead>
              <tr className="border-b border-ink/10 text-ink/55">
                <th className="py-2 text-start font-medium">{t('chart.date')}</th>
                {SERIES.map((s) => <th key={s.key} className="py-2 text-end font-medium">{t(`chart.${s.key}`)}</th>)}
                <th className="py-2 text-end font-medium">{t('chart.rate')}</th>
              </tr>
            </thead>
            <tbody>
              {days.map((d) => (
                <tr key={d.date} className="border-b border-ink/5 last:border-0">
                  <td className="py-2 text-ink">{formatWeekday(d.date, locale, 'short')} {formatDate(d.date, locale, { day: 'numeric', month: 'short' })}</td>
                  {SERIES.map((s) => <td key={s.key} className="py-2 text-end tabular-nums text-ink/80">{formatNumber(d[s.key], locale)}</td>)}
                  <td className="py-2 text-end tabular-nums font-medium text-ink">{formatPercent(d.rate, locale)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="mt-4 h-64" dir="ltr">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data} margin={{ top: 4, right: rtl ? 0 : 4, left: rtl ? 4 : 0, bottom: 0 }} barCategoryGap="28%">
              <CartesianGrid vertical={false} stroke="#1B2B28" strokeOpacity={0.07} />
              <XAxis
                dataKey="date"
                tickLine={false}
                axisLine={{ stroke: '#1B2B28', strokeOpacity: 0.15 }}
                tick={{ fill: '#1B2B28', fillOpacity: 0.55, fontSize: 12 }}
                tickFormatter={(v: string) => formatDate(v, locale, { day: 'numeric' })}
                interval={0}
              />
              <YAxis
                orientation={rtl ? 'right' : 'left'}
                allowDecimals={false}
                tickLine={false}
                axisLine={false}
                width={32}
                tick={{ fill: '#1B2B28', fillOpacity: 0.55, fontSize: 12 }}
                tickFormatter={(v: number) => formatNumber(v, locale)}
              />
              <Tooltip cursor={{ fill: '#1B2B28', fillOpacity: 0.05 }} content={<ChartTooltip locale={locale} />} />
              {SERIES.map((s, i) => (
                <Bar
                  key={s.key}
                  dataKey={s.key}
                  stackId="a"
                  fill={s.color}
                  stroke="#ffffff"
                  strokeWidth={2}
                  radius={i === SERIES.length - 1 ? [4, 4, 0, 0] : 0}
                  isAnimationActive={false}
                />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </section>
  )
}

interface ChartTooltipProps {
  active?: boolean
  payload?: Array<{ payload: AttendanceDay }>
  label?: string | number
  locale: string
}

function ChartTooltip({ active, payload, label, locale }: ChartTooltipProps) {
  const { t } = useTranslation('dashboard')
  if (!active || !payload?.length) return null
  const day = payload[0].payload as AttendanceDay

  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="min-w-44 rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm shadow-lg">
      <p className="mb-1 font-semibold text-ink">{formatWeekday(String(label), locale)} {formatDate(String(label), locale, { day: 'numeric', month: 'long' })}</p>
      {SERIES.map((s) => (
        <p key={s.key} className="flex items-center justify-between gap-4 text-ink/75">
          <span className="inline-flex items-center gap-1.5">
            <span className="inline-block size-2.5 rounded-sm" style={{ background: s.color }} aria-hidden />
            {t(`chart.${s.key}`)}
          </span>
          <span className="tabular-nums text-ink">{formatNumber(day[s.key as SeriesKey], locale)}</span>
        </p>
      ))}
      <p className="mt-1 flex justify-between gap-4 border-t border-ink/10 pt-1 text-ink/75">
        <span>{t('chart.rate')}</span>
        <span className="font-semibold tabular-nums text-ink">{formatPercent(day.rate, locale)}</span>
      </p>
    </div>
  )
}
