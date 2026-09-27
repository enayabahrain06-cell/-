import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { StudentProfile } from '../../../api/students'
import Icon from '../../../components/Icon'
import { formatDate, formatNumber } from '../../../lib/format'
import { SURFACE } from '../../../components/ui'

/**
 * 8-week trend, one line per criterion (0–10). Same validated 4-slot palette as the attendance chart
 * (light surface, all checks pass; one pair in the CVD 6–8 band → legend, tooltip and table carry identity too).
 */
const SERIES = [
  { key: 'memorization', color: '#2E8B57' },
  { key: 'tajweed', color: '#B8872E' },
  { key: 'revision', color: '#B0413A' },
  { key: 'behavior', color: '#3F74C0' },
] as const

type Week = StudentProfile['evaluation']['trend'][number]

export default function TrendChart({ weeks }: { weeks: Week[] }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const rtl = locale === 'ar'
  const [asTable, setAsTable] = useState(false)
  const data = rtl ? [...weeks].reverse() : weeks
  const hasData = weeks.some((w) => w.count > 0)
  const num = (v: number | null) => (v === null ? '—' : formatNumber(v, locale, { maximumFractionDigits: 1 }))

  return (
    <section className={`${SURFACE} p-4 sm:p-5`} aria-labelledby="trend-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 id="trend-title" className="font-semibold text-ink">{t('evaluation.trend')}</h3>
          <p className="text-sm text-ink/55">{t('evaluation.trend_subtitle')}</p>
        </div>
        {hasData && (
          <button type="button" onClick={() => setAsTable((v) => !v)} className="inline-flex items-center gap-1.5 rounded-lg border border-ink/10 px-2.5 py-1.5 text-sm text-ink/70 hover:bg-ink/5">
            <Icon name={asTable ? 'chart' : 'table'} className="size-4" />
            {asTable ? t('evaluation.show_chart') : t('evaluation.show_table')}
          </button>
        )}
      </div>
      <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink/70">
        {SERIES.map((s) => (
          <li key={s.key} className="inline-flex items-center gap-1.5">
            <span aria-hidden className="inline-block h-0.5 w-4 rounded" style={{ background: s.color }} />
            {t(`evaluation.criteria.${s.key}`)}
          </li>
        ))}
      </ul>

      {!hasData ? (
        <p className="py-10 text-center text-sm text-ink/50">{t('evaluation.empty')}</p>
      ) : asTable ? (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[30rem] text-sm">
            <thead>
              <tr className="border-b border-ink/10 text-ink/55">
                <th className="py-2 text-start font-medium">{t('evaluation.week_col')}</th>
                {SERIES.map((s) => <th key={s.key} className="py-2 text-end font-medium">{t(`evaluation.criteria.${s.key}`)}</th>)}
              </tr>
            </thead>
            <tbody>
              {weeks.map((w) => (
                <tr key={w.week_start} className="border-b border-ink/5 last:border-0">
                  <td className="py-2">{formatDate(w.week_start, locale, { day: 'numeric', month: 'short' })}</td>
                  {SERIES.map((s) => <td key={s.key} className="py-2 text-end tabular-nums">{num(w[s.key])}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="mt-4 h-56" dir="ltr">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 8, right: rtl ? 0 : 8, left: rtl ? 8 : 0, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke="#1B2B28" strokeOpacity={0.07} />
              <XAxis dataKey="week_start" tickLine={false} axisLine={{ stroke: '#1B2B28', strokeOpacity: 0.15 }} tick={{ fill: '#1B2B28', fillOpacity: 0.55, fontSize: 12 }}
                tickFormatter={(v: string) => formatDate(v, locale, { day: 'numeric', month: 'short' })} />
              <YAxis domain={[0, 10]} ticks={[0, 5, 10]} orientation={rtl ? 'right' : 'left'} tickLine={false} axisLine={false} width={28}
                tick={{ fill: '#1B2B28', fillOpacity: 0.55, fontSize: 12 }} tickFormatter={(v: number) => formatNumber(v, locale)} />
              <Tooltip content={<TrendTooltip locale={locale} />} cursor={{ stroke: '#1B2B28', strokeOpacity: 0.2 }} />
              {SERIES.map((s) => (
                <Line key={s.key} type="monotone" dataKey={s.key} stroke={s.color} strokeWidth={2} connectNulls
                  dot={{ r: 4, strokeWidth: 2, stroke: '#fff', fill: s.color }} activeDot={{ r: 5, strokeWidth: 2, stroke: '#fff' }} isAnimationActive={false} />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}
    </section>
  )
}

function TrendTooltip({ active, payload, label, locale }: { active?: boolean; payload?: Array<{ payload: Week }>; label?: string | number; locale: string }) {
  const { t } = useTranslation('students')
  if (!active || !payload?.length) return null
  const w = payload[0].payload
  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="min-w-40 rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm shadow-lg">
      <p className="mb-1 font-semibold text-ink">{t('evaluation.week_of', { date: formatDate(String(label), locale, { day: 'numeric', month: 'long' }) })}</p>
      {SERIES.map((s) => (
        <p key={s.key} className="flex items-center justify-between gap-4 text-ink/75">
          <span className="inline-flex items-center gap-1.5"><span aria-hidden className="inline-block size-2.5 rounded-full" style={{ background: s.color }} />{t(`evaluation.criteria.${s.key}`)}</span>
          <span className="tabular-nums text-ink">{w[s.key] === null ? '—' : formatNumber(w[s.key] as number, locale, { maximumFractionDigits: 1 })}</span>
        </p>
      ))}
      <p className="mt-1 border-t border-ink/10 pt-1 text-xs text-ink/55">{t('evaluation.count', { n: formatNumber(w.count, locale) })}</p>
    </div>
  )
}
