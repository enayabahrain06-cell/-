import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { paymentsApi, type FinanceReport } from '../../api/payments'
import { CHART_AXIS_LINE, CHART_GRID, CHART_LINE_CURSOR, CHART_TICK } from '../../components/chart'
import Icon from '../../components/Icon'
import { EmptyState } from '../../components/ornaments'
import { Card, CardTitle, ErrorState, SURFACE } from '../../components/ui'
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../lib/format'

type Data = FinanceReport['data']

/**
 * Finance charts, all fed by GET /reports/finance.
 * Colours: the validated pair already used by the finance report (#2E8B57 collected, #B8872E outstanding/refunded).
 * Single-measure charts use collected green alone, so they need no legend; the two-series package bars
 * have a legend and print both values as text, so identity is never colour alone.
 */
export const COLLECTED = '#2E8B57'
export const OUTSTANDING = '#B8872E'

const yearStart = () => new Date(new Date().getFullYear(), 0, 1).toLocaleDateString('en-CA')
const today = () => new Date().toLocaleDateString('en-CA')

/** This year at a glance, above the payments list: KPI tiles, collection rate, methods, packages, trend. */
export function FinanceOverview() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const params = { from: yearStart(), to: today() }
  const q = useQuery({ queryKey: ['finance', params, locale], queryFn: () => paymentsApi.finance(params) })

  return (
    <section aria-labelledby="fin-overview-title" className="space-y-4">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h2 id="fin-overview-title" className="text-lg font-semibold text-ink">{t('overview.title')}</h2>
        <p className="text-sm text-ink/55">{t('overview.period', { year: formatNumber(new Date().getFullYear(), locale, { useGrouping: false }) })}</p>
        <Link to="?tab=report" className="ms-auto inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
          {t('overview.open_report')}<Icon name="chevron" className="size-4 rtl:rotate-180" />
        </Link>
      </div>
      {q.isLoading ? <OverviewSkeleton /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : <OverviewBody d={q.data.data} />}
    </section>
  )
}

function OverviewSkeleton() {
  return (
    <div aria-hidden="true" className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[0, 1, 2, 3].map((i) => <div key={i} className={`${SURFACE} h-28 animate-pulse`} />)}</div>
      <div className="grid gap-4 lg:grid-cols-2">{[0, 1].map((i) => <div key={i} className={`${SURFACE} h-56 animate-pulse`} />)}</div>
    </div>
  )
}

function OverviewBody({ d }: { d: Data }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const m = (v: number) => formatMoney(v, locale)
  const rate = d.totals.invoiced > 0 ? Math.round((d.totals.collected / d.totals.invoiced) * 100) : null

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4">
        <Kpi icon="payments" label={t('report.collected')} value={m(d.totals.collected)} hint={t('overview.payments_count', { n: formatNumber(d.totals.payments, locale) })} />
        <Kpi icon="alert" label={t('report.outstanding')} value={m(d.totals.outstanding)} hint={`${t('report.students_due')}: ${formatNumber(d.totals.students_due, locale)}`} />
        <Kpi icon="chart" label={t('overview.rate')} value={rate === null ? '—' : formatPercent(rate, locale)} hint={t('overview.of_invoiced', { amount: m(d.totals.invoiced) })}>
          {rate !== null && <Meter value={rate} label={t('overview.rate')} />}
        </Kpi>
        <Kpi icon="refresh" label={t('report.refunded')} value={m(d.totals.refunded)} hint={`${t('report.net')}: ${m(d.totals.net)}`} />
      </div>

      {d.by_month.length >= 2 && <TrendCard months={d.by_month} />}

      <div className="grid gap-4 *:min-w-0 lg:grid-cols-2">
        <MethodBars rows={d.by_method} />
        <PackageBars rows={d.by_package} />
      </div>
    </div>
  )
}

function Kpi({ icon, label, value, hint, children }: { icon: string; label: string; value: string; hint?: string; children?: React.ReactNode }) {
  return (
    <div className={`${SURFACE} min-w-0 p-4`}>
      <div className="flex items-center gap-2">
        <span className="rounded-lg bg-brand-50 p-1.5 text-brand-700"><Icon name={icon} className="size-4" /></span>
        <p className="truncate text-sm text-ink/60">{label}</p>
      </div>
      <p className="mt-2 truncate text-2xl font-semibold tabular-nums text-ink">{value}</p>
      {children}
      {hint && <p className="mt-1 truncate text-xs text-ink/50">{hint}</p>}
    </div>
  )
}

/** Share bar, 0–100. The value is always printed beside it, so the bar is decorative for screen readers. */
function Meter({ value, label, color = COLLECTED }: { value: number; label: string; color?: string }) {
  return (
    <div role="meter" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={value} className="mt-2 h-2 overflow-hidden rounded-full bg-ink/6">
      <div className="h-full rounded-full" style={{ width: `${Math.min(100, Math.max(0, value))}%`, background: color }} />
    </div>
  )
}

/** Payment methods as a sorted bar list (share of the amount collected). */
export function MethodBars({ rows }: { rows: Data['by_method'] }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const total = rows.reduce((s, r) => s + r.amount, 0)
  const sorted = [...rows].sort((a, b) => b.amount - a.amount)
  const max = sorted[0]?.amount ?? 0

  return (
    <Card>
      <CardTitle>{t('report.by_method')}</CardTitle>
      {sorted.length === 0 || total === 0 ? <EmptyState size="sm" icon="payments" title={t('report.empty')} /> : (
        <ul className="space-y-3">
          {sorted.map((r) => (
            <li key={r.method}>
              <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                <span className="font-medium text-ink">{t(`methods.${r.method}`)}</span>
                <span className="text-xs text-ink/50">{t('overview.count', { n: formatNumber(r.count, locale) })}</span>
                <span className="ms-auto font-semibold tabular-nums text-ink">{formatMoney(r.amount, locale)}</span>
                <span className="w-10 text-end text-xs tabular-nums text-ink/55">{formatPercent(Math.round((r.amount / total) * 100), locale)}</span>
              </div>
              <div aria-hidden="true" className="mt-1.5 h-2 overflow-hidden rounded-full bg-ink/6">
                <div className="h-full rounded-full" style={{ width: `${max ? (r.amount / max) * 100 : 0}%`, background: COLLECTED }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

/** Per package: collected + outstanding as one stacked bar (together they make what was invoiced). */
export function PackageBars({ rows }: { rows: Data['by_package'] }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const m = (v: number) => formatMoney(v, locale)
  const sorted = [...rows].filter((r) => r.collected + r.outstanding > 0).sort((a, b) => b.collected + b.outstanding - (a.collected + a.outstanding))
  const max = Math.max(0, ...sorted.map((r) => r.collected + r.outstanding))

  return (
    <Card>
      <CardTitle>{t('overview.by_package')}</CardTitle>
      {sorted.length === 0 ? <EmptyState size="sm" icon="packages" title={t('report.empty')} /> : (
        <>
          <Legend items={[[t('report.collected'), COLLECTED], [t('report.outstanding'), OUTSTANDING]]} />
          <ul className="space-y-3">
            {sorted.map((r) => (
              <li key={r.name}>
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-sm">
                  <span dir="auto" className="min-w-0 flex-[1_1_10rem] truncate font-medium text-ink">{r.name}</span>
                  <span className="tabular-nums text-ink">{m(r.collected)}</span>
                  {r.outstanding > 0 && <span className="text-xs tabular-nums text-ink/55">{t('overview.due', { amount: m(r.outstanding) })}</span>}
                </div>
                {/* 2px surface gap between the two segments (dataviz: spacer between adjacent fills). */}
                <div aria-hidden="true" className="mt-1.5 flex h-2 gap-0.5 overflow-hidden rounded-full bg-ink/6">
                  <div className="h-full rounded-s-full" style={{ width: `${(r.collected / max) * 100}%`, background: COLLECTED }} />
                  {r.outstanding > 0 && <div className="h-full rounded-e-full" style={{ width: `${(r.outstanding / max) * 100}%`, background: OUTSTANDING }} />}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </Card>
  )
}

function Legend({ items }: { items: [string, string][] }) {
  return (
    <ul className="mb-3 flex flex-wrap gap-4 text-sm text-ink/70">
      {items.map(([label, color]) => (
        <li key={label} className="inline-flex items-center gap-1.5"><span aria-hidden className="size-2.5 rounded-sm" style={{ background: color }} />{label}</li>
      ))}
    </ul>
  )
}

/** Collected per month (one series, so the title names it and no legend is needed). */
function TrendCard({ months }: { months: Data['by_month'] }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const rtl = locale === 'ar'
  // Recharts draws left to right; in Arabic the timeline reads right to left.
  const data = rtl ? [...months].reverse() : months

  return (
    <Card>
      <CardTitle>{t('report.by_month')}</CardTitle>
      <div className="h-56" dir="ltr">
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={data} margin={{ top: 8, right: rtl ? 0 : 12, left: rtl ? 12 : 0, bottom: 0 }}>
            <CartesianGrid vertical={false} {...CHART_GRID} />
            <XAxis dataKey="period" tickLine={false} axisLine={CHART_AXIS_LINE} tick={CHART_TICK} minTickGap={16}
              tickFormatter={(v: string) => formatDate(`${v}-15`, locale, { month: 'short' })} />
            <YAxis orientation={rtl ? 'right' : 'left'} tickLine={false} axisLine={false} width={48} tick={CHART_TICK}
              tickFormatter={(v: number) => formatNumber(v / 1000, locale, { maximumFractionDigits: 0 })} />
            <Tooltip cursor={CHART_LINE_CURSOR} content={<MonthTooltip locale={locale} />} />
            <Area type="monotone" dataKey="collected" stroke={COLLECTED} strokeWidth={2} fill={COLLECTED} fillOpacity={0.12}
              dot={{ r: 3, fill: COLLECTED, stroke: '#fff', strokeWidth: 2 }} activeDot={{ r: 5, fill: COLLECTED, stroke: '#fff', strokeWidth: 2 }} isAnimationActive={false} />
          </AreaChart>
        </ResponsiveContainer>
      </div>
      {/* Table view for screen readers and anyone who wants exact numbers. */}
      <details className="mt-2 text-sm">
        <summary className="cursor-pointer text-brand-700">{t('report.show_table')}</summary>
        <table className="mt-2 w-full text-sm">
          <thead><tr className="border-b border-ink/10 text-ink/55"><th className="py-2 text-start font-medium">{t('report.month')}</th><th className="py-2 text-end font-medium">{t('report.collected')}</th><th className="py-2 text-end font-medium">{t('report.refunded')}</th></tr></thead>
          <tbody>{months.map((x) => <tr key={x.period} className="border-b border-ink/5"><td className="py-2">{formatDate(`${x.period}-15`, locale, { month: 'long', year: 'numeric' })}</td><td className="py-2 text-end tabular-nums">{formatMoney(x.collected, locale)}</td><td className="py-2 text-end tabular-nums">{formatMoney(x.refunded, locale)}</td></tr>)}</tbody>
        </table>
      </details>
    </Card>
  )
}

function MonthTooltip({ active, payload, locale }: { active?: boolean; payload?: Array<{ payload: Data['by_month'][number] }>; locale: string }) {
  const { t } = useTranslation('payments')
  if (!active || !payload?.length) return null
  const d = payload[0].payload
  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm shadow-lg">
      <p className="font-semibold text-ink">{formatDate(`${d.period}-15`, locale, { month: 'long', year: 'numeric' })}</p>
      <p className="tabular-nums text-ink">{t('report.collected')}: {formatMoney(d.collected, locale)}</p>
      {d.refunded > 0 && <p className="tabular-nums text-ink/60">{t('report.refunded')}: {formatMoney(d.refunded, locale)}</p>}
    </div>
  )
}

/** Largest outstanding balances, each with a bar relative to the largest one. Balances arrive negative (owed); the list shows the amount due. */
export function OutstandingBars({ rows }: { rows: Data['outstanding'] }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const max = Math.max(0, ...rows.map((r) => Math.abs(r.balance_fils)))

  return (
    <Card>
      <CardTitle>{t('report.outstanding_list')}</CardTitle>
      {rows.length === 0 ? <EmptyState size="sm" icon="check" title={t('report.empty')} /> : (
        <ul className="grid gap-x-8 gap-y-3 text-sm md:grid-cols-2">
          {rows.map((s) => (
            <li key={s.student_id} className="min-w-0">
              <div className="flex items-baseline gap-2">
                <Link to={`/students/${s.student_id}?tab=wallet`} dir="auto" className="min-w-0 flex-1 truncate font-medium text-ink hover:text-brand-700">{s.full_name}</Link>
                <span dir="ltr" className="text-xs tabular-nums text-ink/45">{s.student_no}</span>
                <span className="font-semibold tabular-nums text-ink">{formatMoney(Math.abs(s.balance_fils), locale)}</span>
              </div>
              <div aria-hidden="true" className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink/6">
                <div className="h-full rounded-full" style={{ width: `${max ? (Math.abs(s.balance_fils) / max) * 100 : 0}%`, background: OUTSTANDING }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
