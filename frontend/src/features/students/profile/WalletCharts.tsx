import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { WalletMonth, WalletView } from '../../../api/students'
import { CHART_AXIS_LINE, CHART_BAR_CURSOR, CHART_GRID, CHART_TICK } from '../../../components/chart'
import Icon from '../../../components/Icon'
import { EmptyState } from '../../../components/ornaments'
import { SURFACE, TableWrap } from '../../../components/ui'
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../../lib/format'
import { COLLECTED, OUTSTANDING } from '../../payments/FinanceCharts'

/**
 * Wallet charts. Colours are the finance pair (paid green, owed gold) shared with the payments screens.
 * Validated (dataviz validator, light surface): every check passes, but protan CVD ΔE is 6.2, which is legal
 * only with a second encoding. So identity never rests on colour: the donut has a 2px gap and a legend with
 * amounts; the bars keep a fixed side-by-side order, "charged" is striped, and both views have a table.
 */
const PAID = COLLECTED
const OWED = OUTSTANDING
const STRIPES = 'wallet-charged-stripes'

function ViewToggle({ table, onChange }: { table: boolean; onChange: (v: boolean) => void }) {
  const { t } = useTranslation('students')
  return (
    <button type="button" onClick={() => onChange(!table)} className="inline-flex items-center gap-1.5 rounded-lg border border-ink/10 px-2.5 py-1.5 text-sm text-ink/70 hover:bg-ink/5">
      <Icon name={table ? 'chart' : 'table'} className="size-4" />
      {table ? t('wallet_charts.show_chart') : t('wallet_charts.show_table')}
    </button>
  )
}

/** Share of everything invoiced that has been paid (cancelled invoices excluded). */
function PaidDonut({ wallet }: { wallet: WalletView }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const [table, setTable] = useState(false)
  const [active, setActive] = useState<number | null>(null)
  const live = wallet.invoices.filter((i) => i.status !== 'cancelled')
  const invoiced = live.reduce((s, i) => s + i.amount_fils, 0)
  const slices = [
    { key: 'paid', label: t('wallet_charts.paid'), value: live.reduce((s, i) => s + i.paid_fils, 0), color: PAID },
    { key: 'outstanding', label: t('wallet_charts.outstanding'), value: live.reduce((s, i) => s + i.outstanding_fils, 0), color: OWED },
  ]
  const m = (f: number) => formatMoney(f, locale)
  const pct = (v: number) => formatPercent(invoiced ? Math.round((v * 100) / invoiced) : null, locale)
  const shown = active !== null ? slices[active] : null
  // Only non-zero parts are drawn; hover indexes map back to them.
  const drawn = slices.filter((s) => s.value > 0)

  return (
    <section className={`${SURFACE} flex flex-col p-4 sm:p-5`} aria-labelledby="wallet-donut-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 id="wallet-donut-title" className="text-base font-semibold text-ink">{t('wallet_charts.paid_title')}</h3>
          <p className="text-sm text-ink/55">{t('wallet_charts.paid_subtitle', { total: m(invoiced) })}</p>
        </div>
        {invoiced > 0 && <ViewToggle table={table} onChange={setTable} />}
      </div>

      {invoiced === 0 ? <EmptyState size="sm" icon="payments" title={t('wallet.no_invoices')} /> : table ? (
        <TableWrap className="mt-4">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-ink/10 text-ink/55">
              <th className="py-2 text-start font-medium">{t('wallet_charts.part')}</th>
              <th className="py-2 text-end font-medium">{t('wallet_charts.amount')}</th>
              <th className="py-2 text-end font-medium">{t('wallet_charts.share')}</th>
            </tr></thead>
            <tbody>
              {slices.map((s) => (
                <tr key={s.key} className="border-b border-ink/5 last:border-0">
                  <td className="py-2 text-ink">{s.label}</td>
                  <td className="py-2 text-end tabular-nums text-ink/80">{m(s.value)}</td>
                  <td className="py-2 text-end tabular-nums text-ink/80">{pct(s.value)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      ) : (
        <div className="mt-2 flex flex-1 flex-col items-center gap-4 sm:flex-row">
          <div className="relative size-40 shrink-0" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={drawn} dataKey="value" nameKey="label" innerRadius="64%" outerRadius="100%"
                  startAngle={90} endAngle={locale === 'ar' ? 450 : -270} stroke="#ffffff" strokeWidth={drawn.length > 1 ? 2 : 0} cornerRadius={drawn.length > 1 ? 4 : 0} isAnimationActive={false}
                  onMouseEnter={(_, i) => setActive(slices.findIndex((s) => s.key === drawn[i]?.key))} onMouseLeave={() => setActive(null)}>
                  {drawn.map((s) => (
                    <Cell key={s.key} fill={s.color} fillOpacity={shown === null || shown.key === s.key ? 1 : 0.35} />
                  ))}
                </Pie>
              </PieChart>
            </ResponsiveContainer>
            {/* The centre is the readout: share paid, or the hovered part (no floating tooltip over the ring). */}
            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
              <div>
                <p className="text-2xl font-semibold tabular-nums text-ink">{pct(shown ? shown.value : slices[0].value)}</p>
                <p className="text-xs text-ink/55">{shown ? shown.label : t('wallet_charts.paid')}</p>
              </div>
            </div>
          </div>
          <ul className="w-full min-w-0 flex-1 space-y-1 text-sm">
            {slices.map((s, i) => (
              <li key={s.key} onMouseEnter={() => setActive(i)} onMouseLeave={() => setActive(null)}
                className={`flex items-center gap-2 rounded-lg px-2 py-1 ${active === i ? 'bg-ink/5' : ''}`}>
                <span className="inline-block size-2.5 shrink-0 rounded-sm" style={{ background: s.color }} aria-hidden />
                <span className="flex-1 text-ink/75">{s.label}</span>
                <span className="tabular-nums font-medium text-ink">{m(s.value)}</span>
                <span className="w-11 text-end tabular-nums text-ink/50">{pct(s.value)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

/** Charged and paid per month, last 12 months (grouped bars, one money axis). */
function MonthlyBars({ months }: { months: WalletMonth[] }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const rtl = locale === 'ar'
  const [table, setTable] = useState(false)
  const m = (f: number) => formatMoney(f, locale)
  const monthLabel = (ym: string, long = false) => formatDate(`${ym}-15`, locale, long ? { month: 'long', year: 'numeric' } : { month: 'short' })
  const any = months.some((x) => x.charged_fils || x.paid_fils || x.refunded_fils)
  const refunds = months.some((x) => x.refunded_fils)

  return (
    <section className={`${SURFACE} flex flex-col p-4 sm:p-5`} aria-labelledby="wallet-bars-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 id="wallet-bars-title" className="text-base font-semibold text-ink">{t('wallet_charts.monthly_title')}</h3>
          <p className="text-sm text-ink/55">{t('wallet_charts.monthly_subtitle')}</p>
        </div>
        {any && <ViewToggle table={table} onChange={setTable} />}
      </div>

      {!any ? <EmptyState size="sm" icon="payments" title={t('wallet.no_transactions')} /> : table ? (
        <TableWrap className="mt-4">
          <table className="w-full min-w-[26rem] text-sm">
            <thead><tr className="border-b border-ink/10 text-ink/55">
              <th className="py-2 text-start font-medium">{t('wallet_charts.month')}</th>
              <th className="py-2 text-end font-medium">{t('wallet_charts.charged')}</th>
              <th className="py-2 text-end font-medium">{t('wallet_charts.paid')}</th>
              {refunds && <th className="py-2 text-end font-medium">{t('wallet_charts.refunded')}</th>}
            </tr></thead>
            <tbody>
              {months.map((x) => (
                <tr key={x.month} className="border-b border-ink/5 last:border-0">
                  <td className="py-2 text-ink">{monthLabel(x.month, true)}</td>
                  <td className="py-2 text-end tabular-nums text-ink/80">{m(x.charged_fils)}</td>
                  <td className="py-2 text-end tabular-nums text-ink/80">{m(x.paid_fils)}</td>
                  {refunds && <td className="py-2 text-end tabular-nums text-ink/80">{m(x.refunded_fils)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      ) : (
        <>
          <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink/70" aria-label={t('wallet_charts.legend')}>
            <li className="inline-flex items-center gap-1.5"><Swatch striped />{t('wallet_charts.charged')}</li>
            <li className="inline-flex items-center gap-1.5"><Swatch />{t('wallet_charts.paid')}</li>
          </ul>
          <div className="mt-2 h-56" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={months} barGap={2} barCategoryGap="28%">
                <defs>
                  <pattern id={STRIPES} width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                    <rect width="6" height="6" fill={OWED} fillOpacity={0.35} />
                    <rect width="3" height="6" fill={OWED} />
                  </pattern>
                </defs>
                <CartesianGrid vertical={false} {...CHART_GRID} />
                <XAxis dataKey="month" reversed={rtl} tickLine={false} axisLine={CHART_AXIS_LINE} tick={CHART_TICK} interval="preserveStartEnd" minTickGap={8}
                  tickFormatter={(v: string) => monthLabel(v)} />
                <YAxis orientation={rtl ? 'right' : 'left'} tickLine={false} axisLine={false} width={44} tick={CHART_TICK} allowDecimals={false}
                  tickFormatter={(v: number) => formatNumber(v / 1000, locale, { maximumFractionDigits: 0 })} />
                <Tooltip cursor={CHART_BAR_CURSOR} content={<MonthTooltip locale={locale} monthLabel={monthLabel} />} />
                <Bar dataKey="charged_fils" name={t('wallet_charts.charged')} fill={`url(#${STRIPES})`} radius={[4, 4, 0, 0]} maxBarSize={22} isAnimationActive={false} />
                <Bar dataKey="paid_fils" name={t('wallet_charts.paid')} fill={PAID} radius={[4, 4, 0, 0]} maxBarSize={22} isAnimationActive={false} />
              </BarChart>
            </ResponsiveContainer>
          </div>
          <p className="mt-1 text-xs text-ink/45">{t('wallet_charts.axis_bhd')}</p>
        </>
      )}
    </section>
  )
}

function Swatch({ striped = false }: { striped?: boolean }) {
  return (
    <span aria-hidden className="inline-block size-3 shrink-0 rounded-sm"
      style={striped ? { background: `repeating-linear-gradient(45deg, ${OWED} 0 2px, color-mix(in srgb, ${OWED} 35%, white) 2px 4px)` } : { background: PAID }} />
  )
}

function MonthTooltip({ active, payload, label, locale, monthLabel }: { active?: boolean; payload?: Array<{ payload: WalletMonth }>; label?: string | number; locale: string; monthLabel: (ym: string, long?: boolean) => string }) {
  const { t } = useTranslation('students')
  const row = payload?.[0]?.payload
  if (!active || !row) return null
  const m = (f: number) => formatMoney(f, locale)
  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="min-w-44 rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm shadow-lg">
      <p className="mb-1 font-semibold text-ink">{monthLabel(String(label), true)}</p>
      <p className="flex justify-between gap-4"><span className="inline-flex items-center gap-1.5 text-ink/70"><Swatch striped />{t('wallet_charts.charged')}</span><span className="tabular-nums text-ink">{m(row.charged_fils)}</span></p>
      <p className="flex justify-between gap-4"><span className="inline-flex items-center gap-1.5 text-ink/70"><Swatch />{t('wallet_charts.paid')}</span><span className="tabular-nums text-ink">{m(row.paid_fils)}</span></p>
      {row.refunded_fils > 0 && <p className="flex justify-between gap-4 text-ink/60"><span>{t('wallet_charts.refunded')}</span><span className="tabular-nums">{m(row.refunded_fils)}</span></p>}
    </div>
  )
}

/** Donut (share paid) beside the monthly bars; stacks on phones and tablets. */
export default function WalletCharts({ wallet }: { wallet: WalletView }) {
  return (
    <div className="grid gap-5 *:min-w-0 lg:grid-cols-3">
      <PaidDonut wallet={wallet} />
      <div className="lg:col-span-2"><MonthlyBars months={wallet.monthly ?? []} /></div>
    </div>
  )
}
