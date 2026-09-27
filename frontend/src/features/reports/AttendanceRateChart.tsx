import { useTranslation } from 'react-i18next'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { AttendanceDayPoint } from '../../api/reports'
import { Card, CardTitle } from '../../components/ui'
import { formatDate, formatNumber, formatPercent, formatWeekday } from '../../lib/format'

/**
 * One series (attendance rate per day), so no legend: the title names it. Colour is the dashboard's validated
 * "present" green (#2E8B57) on the white card; the per-day table below the chart is the table view.
 */
const LINE = '#2E8B57'
const INK = '#1B2B28'

export default function AttendanceRateChart({ days }: { days: AttendanceDayPoint[] }) {
  const { t, i18n } = useTranslation('reports')
  const locale = i18n.language
  const rtl = locale === 'ar'
  const points = days.filter((d) => d.rate !== null)
  if (points.length < 2) return null
  // Recharts draws left to right; in Arabic the timeline reads right to left.
  const data = rtl ? [...points].reverse() : points
  const avg = Math.round(points.reduce((s, d) => s + (d.rate ?? 0), 0) / points.length)

  return (
    <Card>
      <CardTitle>
        {t('chart.rate_title')}
        <span className="ms-2 text-sm font-normal text-ink/55">{t('chart.average', { rate: formatPercent(avg, locale) })}</span>
      </CardTitle>
      <div className="h-56" dir="ltr">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data} margin={{ top: 8, right: rtl ? 0 : 12, left: rtl ? 12 : 0, bottom: 0 }}>
            <CartesianGrid vertical={false} stroke={INK} strokeOpacity={0.07} />
            <XAxis
              dataKey="date"
              tickLine={false}
              axisLine={{ stroke: INK, strokeOpacity: 0.15 }}
              tick={{ fill: INK, fillOpacity: 0.55, fontSize: 12 }}
              tickFormatter={(v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })}
              minTickGap={24}
            />
            <YAxis
              orientation={rtl ? 'right' : 'left'}
              domain={[0, 100]}
              ticks={[0, 25, 50, 75, 100]}
              tickLine={false}
              axisLine={false}
              width={40}
              tick={{ fill: INK, fillOpacity: 0.55, fontSize: 12 }}
              tickFormatter={(v: number) => formatPercent(v, locale)}
            />
            <Tooltip cursor={{ stroke: INK, strokeOpacity: 0.2 }} content={<RateTooltip locale={locale} />} />
            <Line
              type="monotone"
              dataKey="rate"
              stroke={LINE}
              strokeWidth={2}
              dot={points.length <= 31 ? { r: 3, fill: LINE, stroke: '#fff', strokeWidth: 2 } : false}
              activeDot={{ r: 5, fill: LINE, stroke: '#fff', strokeWidth: 2 }}
              isAnimationActive={false}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </Card>
  )
}

function RateTooltip({ active, payload, locale }: { active?: boolean; payload?: Array<{ payload: AttendanceDayPoint }>; locale: string }) {
  const { t } = useTranslation('reports')
  if (!active || !payload?.length) return null
  const d = payload[0].payload
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm shadow-lg">
      <p className="font-semibold text-ink">{formatWeekday(d.date, locale)} {formatDate(d.date, locale, { day: 'numeric', month: 'long' })}</p>
      <p className="tabular-nums text-ink">{t('chart.rate', { rate: formatPercent(d.rate, locale) })}</p>
      <p className="tabular-nums text-ink/60">{t('chart.breakdown', { present: n(d.present), late: n(d.late), absent: n(d.absent), excused: n(d.excused) })}</p>
    </div>
  )
}
