import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { reportsApi, type AttendanceDayPoint, type CatalogEntry, type ReportCatalog } from '../../api/reports'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import { useBelowLg } from '../../components/mobile/useBelowLg'
import { Chip, ChipRow, MCard, MEmpty, MSearch, Skeleton, M_BTN_SECONDARY, M_CARD } from '../../components/mobile/atoms'
import { formatDate, formatNumber, formatPercent, formatWeekday } from '../../lib/format'
import { presets } from './presets'

/** Reports below lg (mobile-redesign-spec.md §6.23). Catalog, report queries and filters stay in ReportsHomePage. */

const THRESHOLD = 80
const STATES = [
  { key: 'present', bar: 'bg-chart-present' },
  { key: 'late', bar: 'bg-chart-late' },
  { key: 'absent', bar: 'bg-chart-absent' },
  { key: 'excused', bar: 'bg-chart-excused' },
] as const

export function MobileCatalog({ catalog, groups, query, onQuery }: {
  catalog: ReportCatalog; groups: { key: string; label: string; reports: CatalogEntry[] }[]; query: string; onQuery: (v: string) => void
}) {
  const { t, i18n } = useTranslation('reports')
  const locale = i18n.language
  const mobile = useBelowLg()
  const [period, setPeriod] = useState('this_month')
  const p = presets().find((x) => x.key === period) ?? presets()[0]
  const attendance = catalog.data.find((r) => r.chart === 'attendance_by_day')
  // Same key as the report viewer with only a period set, so opening the report reuses this response.
  const values = { from: p.from, to: p.to }
  const q = useQuery({
    queryKey: ['reports', attendance?.key, values, locale],
    queryFn: () => reportsApi.get(attendance!.endpoint, values),
    enabled: mobile && !!attendance,
  })
  const days = q.data?.data?.by_day ?? []

  return (
    <div className="space-y-5 lg:hidden">
      <MobilePage title={t('title')} back="/" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title') }]} />
      {attendance && (
        <>
          <ChipRow label={t('filters.presets')}>
            {presets().map((x) => <Chip key={x.key} active={x.key === period} onClick={() => setPeriod(x.key)}>{t(`filters.${x.key}`)}</Chip>)}
          </ChipRow>
          {q.isLoading ? <ChartSkeleton /> : q.isError ? (
            <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={() => void q.refetch()} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
          ) : (
            <>
              <DailyBars days={days} locale={locale} />
              <Distribution days={days} locale={locale} />
            </>
          )}
          <Link to={`/reports?report=${attendance.key}&from=${p.from}&to=${p.to}`} className={`${M_BTN_SECONDARY} w-full`}>
            {t('mobile.open_attendance')}<Icon name="chevron" className="size-4 rtl:rotate-180" />
          </Link>
        </>
      )}

      <section className="space-y-3">
        <h2 className="text-lg font-semibold text-ink">{t('mobile.all_reports')}</h2>
        <MSearch label={t('search')} value={query} onChange={onQuery} />
        {groups.length === 0 ? <MCard><MEmpty icon="search" text={t('no_match')} /></MCard> : groups.map((g) => (
          <div key={g.key} className="space-y-2">
            <h3 className="px-1 text-xs font-semibold text-ink/65">{g.label} <span className="tabular-nums">({formatNumber(g.reports.length, locale)})</span></h3>
            <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
              {g.reports.map((r) => (
                <li key={r.key}>
                  <Link to={`/reports?report=${r.key}`} className="flex min-h-16 items-center gap-3 px-4 py-2.5 active:bg-brand-50/60">
                    <span className="inline-grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700"><Icon name={r.icon} className="size-5" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-[15px] font-semibold text-ink">{r.title}</span>
                      <span className="mt-0.5 line-clamp-2 text-[13px] text-ink/65">{r.description}</span>
                    </span>
                    <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </section>
    </div>
  )
}

function ChartSkeleton() {
  return (
    <div aria-hidden className="space-y-5">
      <div className={`${M_CARD} space-y-3 p-4`}><Skeleton className="h-4 w-1/2" /><Skeleton className="h-28 w-full" /><Skeleton className="h-3 w-2/3" /></div>
      <div className={`${M_CARD} space-y-3 p-4`}><Skeleton className="h-4 w-1/3" /><Skeleton className="h-3 w-full" /><Skeleton className="h-10 w-full" /></div>
    </div>
  )
}

/** Last seven days with a rate: 14px bars (chart-present, chart-late below the threshold), value above, weekday below. */
function DailyBars({ days, locale }: { days: AttendanceDayPoint[]; locale: string }) {
  const { t } = useTranslation('reports')
  const last = days.filter((d) => d.rate !== null).slice(-7)
  if (last.length === 0) return <MCard><h2 className="text-base font-semibold text-ink">{t('mobile.daily_title')}</h2><MEmpty icon="attendance" text={t('mobile.no_days')} /></MCard>
  const low = last.reduce((a, b) => ((b.rate ?? 0) < (a.rate ?? 0) ? b : a))
  const avg = Math.round(last.reduce((s, d) => s + (d.rate ?? 0), 0) / last.length)
  return (
    <MCard>
      <h2 className="text-base font-semibold text-ink">{t('mobile.daily_title')}</h2>
      <ol className="mt-4 flex h-36 items-end justify-around gap-1" aria-label={t('mobile.daily_title')}>
        {last.map((d) => {
          const rate = d.rate ?? 0
          const label = `${formatWeekday(d.date, locale)} ${formatDate(d.date, locale, { day: 'numeric', month: 'short' })}: ${formatPercent(rate, locale)}`
          return (
            <li key={d.date} aria-label={label} title={label} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
              <span aria-hidden className="text-xs font-semibold tabular-nums text-ink">{formatPercent(rate, locale)}</span>
              {/* 88px = 100%; the value sits right above the bar. */}
              <span aria-hidden className={`block w-3.5 rounded-t-[4px] ${rate < THRESHOLD ? 'bg-chart-late' : 'bg-chart-present'}`} style={{ height: `${Math.max(2, Math.round((rate / 100) * 88))}px` }} />
              <span aria-hidden className="text-xs tabular-nums text-ink">{formatDate(d.date, locale, { day: 'numeric' })}</span>
              {/* Weekday names do not fit seven columns at 320px; the day number and the row's label still name it. */}
              <span aria-hidden className="max-w-full truncate text-xs text-ink/65 max-[359px]:hidden">{formatWeekday(d.date, locale, 'short')}</span>
            </li>
          )
        })}
      </ol>
      <p className="mt-3 border-t border-ink/10 pt-3 text-[13px] text-ink/65">
        {t('mobile.insight', { avg: formatPercent(avg, locale), day: formatWeekday(low.date, locale), low: formatPercent(low.rate, locale) })}
      </p>
    </MCard>
  )
}

/** One stacked bar (2px gaps) over the whole period, with a labelled legend (never colour alone). */
function Distribution({ days, locale }: { days: AttendanceDayPoint[]; locale: string }) {
  const { t } = useTranslation('reports')
  const sums = STATES.map((s) => ({ ...s, n: days.reduce((a, d) => a + d[s.key], 0) }))
  const total = sums.reduce((a, s) => a + s.n, 0)
  return (
    <MCard>
      <h2 className="text-base font-semibold text-ink">{t('mobile.dist_title')}</h2>
      {total === 0 ? <MEmpty icon="attendance" text={t('mobile.no_days')} /> : (
        <>
          <div aria-hidden className="mt-4 flex h-3 gap-[2px] overflow-hidden rounded-full">
            {sums.filter((s) => s.n > 0).map((s) => <span key={s.key} className={s.bar} style={{ width: `${(s.n / total) * 100}%` }} />)}
          </div>
          <ul className="mt-4 grid grid-cols-1 gap-x-4 gap-y-2 min-[360px]:grid-cols-2">
            {sums.map((s) => (
              <li key={s.key} className="flex min-w-0 items-center gap-2 text-[13px]">
                <span aria-hidden className={`size-2.5 shrink-0 rounded-sm ${s.bar}`} />
                <span className="min-w-0 truncate text-ink">{t(`mobile.states.${s.key}`)}</span>
                <span className="ms-auto shrink-0 tabular-nums text-ink/65">{formatNumber(s.n, locale)} · {formatPercent(Math.round((s.n / total) * 100), locale)}</span>
              </li>
            ))}
          </ul>
        </>
      )}
    </MCard>
  )
}

/** Header, period chips, filter sheet and export sheet for an open report below lg. */
export function MobileViewerBar({ entry, periodKeys, activePreset, onPreset, fields, filterCount, exporting, canExport, onExport }: {
  entry: CatalogEntry; periodKeys: string[] | null; activePreset?: string; onPreset: (k: string) => void; fields: ReactNode; filterCount: number
  exporting: 'xlsx' | 'pdf' | null; canExport: boolean; onExport: (f: 'xlsx' | 'pdf') => void
}) {
  const { t } = useTranslation('reports')
  const [sheet, setSheet] = useState<'filters' | 'export' | null>(null)
  return (
    <div className="space-y-3 lg:hidden">
      <MobilePage title={entry.title} back="/reports"
        breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/reports' }, { label: entry.title }]}
        actions={entry.formats.length > 0 ? <HeaderAction icon="download" label={t('mobile.export')} onClick={() => setSheet('export')} /> : undefined} />
      <p className="text-[13px] text-ink/65">{entry.description}</p>
      <div className="flex items-center gap-2">
        <div className="min-w-0 flex-1">
          {periodKeys && (
            <ChipRow label={t('filters.presets')}>
              {periodKeys.map((k) => <Chip key={k} active={activePreset === k} onClick={() => onPreset(k)}>{t(`filters.${k}`)}</Chip>)}
            </ChipRow>
          )}
        </div>
        <button type="button" onClick={() => setSheet('filters')} aria-label={t('mobile.filters')} title={t('mobile.filters')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="filter" className="size-5" />
          {filterCount > 0 && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>

      <BottomSheet open={sheet === 'filters'} onClose={() => setSheet(null)} title={t('mobile.filters')}
        footer={<button type="button" onClick={() => setSheet(null)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>}>
        <div className="space-y-4 [&_input]:min-h-12 [&_select]:min-h-12">{fields}</div>
      </BottomSheet>

      <BottomSheet open={sheet === 'export'} onClose={() => setSheet(null)} title={t('mobile.export')}>
        <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white">
          {(['pdf', 'xlsx'] as const).filter((f) => entry.formats.includes(f)).map((f) => (
            <li key={f}>
              <button type="button" disabled={exporting !== null || !canExport} onClick={() => { onExport(f); setSheet(null) }}
                className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink disabled:opacity-60">
                <Icon name={f === 'pdf' ? 'reports' : 'table'} className="size-5 text-brand-700" />
                <span className="flex-1 text-start">{exporting === f ? t('exporting') : t(`mobile.export_${f}`)}</span>
                <Icon name="download" className="size-5 text-ink/40" />
              </button>
            </li>
          ))}
        </ul>
      </BottomSheet>
    </div>
  )
}
