import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { dashboardPanelsApi, type BehindPlanStudent } from '../../api/dashboard-panels'
import { useAuth } from '../../app/AuthContext'
import { EmptyState } from '../../components/ornaments'
import { formatNumber, formatPercent } from '../../lib/format'
import { PanelCard, PanelError, PanelSkeleton, StatTile } from './PanelParts'
import { useDashboardFilters } from './useDashboardFilters'

/** Memorization progress (تقدّم الحفظ): the week, reviews due, juz finished, top 5 behind plan. */
export default function MemorizationCard() {
  const { t, i18n } = useTranslation('dashboard-panels')
  const locale = i18n.language
  const filters = useDashboardFilters()
  const q = useQuery({ queryKey: ['dashboard', 'memorization', filters, locale], queryFn: () => dashboardPanelsApi.memorization(filters), refetchInterval: 5 * 60_000 })
  const n = (v: number, o?: Intl.NumberFormatOptions) => formatNumber(v, locale, o)
  const d = q.data

  return (
    <PanelCard id="memorization-title" title={t('memorization.title')} icon="evaluation" footer={{ to: '/evaluation', label: t('memorization.link') }}>
      {q.isLoading ? (
        <PanelSkeleton />
      ) : q.isError || !d ? (
        <PanelError onRetry={() => void q.refetch()} />
      ) : d.students === 0 ? (
        <EmptyState size="sm" icon="evaluation" title={t('memorization.empty')} body={t('memorization.empty_body')} />
      ) : (
        <div className="space-y-4 p-4">
          <div className="grid grid-cols-1 gap-2 @sm:grid-cols-3">
            <StatTile label={t('memorization.pages_week')} value={n(d.pages_this_week, { maximumFractionDigits: 1 })} hint={t('memorization.pages_hint')} />
            <StatTile label={t('memorization.reviews_due')} value={n(d.reviews_due)} tone={d.reviews_due > 0 ? 'gold' : undefined} hint={t('memorization.reviews_hint')} />
            <StatTile label={t('memorization.juz_month')} value={n(d.juz_finished_this_month)} hint={t('memorization.juz_hint')} />
          </div>

          <div>
            <h3 className="mb-1.5 flex items-baseline justify-between text-sm font-semibold text-ink">
              {t('memorization.behind_title')}
              {d.behind_total > 0 && <span className="text-xs font-normal text-ink/50">{t('memorization.behind_total', { n: n(d.behind_total) })}</span>}
            </h3>
            {d.behind.length === 0 ? (
              <p className="rounded-xl bg-brand-50 px-3 py-3 text-sm text-brand-700">{t('memorization.on_track')}</p>
            ) : (
              <ul className="divide-y divide-ink/6">
                {d.behind.map((s) => <BehindRow key={s.student_id} s={s} locale={locale} />)}
              </ul>
            )}
          </div>
        </div>
      )}
    </PanelCard>
  )
}

function BehindRow({ s, locale }: { s: BehindPlanStudent; locale: string }) {
  const { t } = useTranslation('dashboard-panels')
  const { can } = useAuth()
  const name = can('students.view') ? (
    <Link to={`/students/${s.student_id}`} dir="auto" className="block truncate text-start font-medium text-ink hover:text-brand-700">{s.name}</Link>
  ) : (
    <p dir="auto" className="truncate text-start font-medium text-ink">{s.name}</p>
  )

  return (
    <li className="py-2.5">
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          {name}
          {s.lesson && <p dir="auto" className="truncate text-start text-xs text-ink/55">{s.lesson}</p>}
        </div>
        <span className="shrink-0 rounded-full bg-danger/8 px-2 py-0.5 text-xs font-medium tabular-nums text-danger">
          {t('memorization.gap', { pages: formatNumber(s.gap_pages, locale, { maximumFractionDigits: 1 }) })}
        </span>
      </div>
      <div className="mt-1.5 flex items-center gap-2">
        <div
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={s.plan_percent}
          aria-label={t('memorization.plan_aria', { name: s.name })}
          className="h-1.5 flex-1 overflow-hidden rounded-full bg-ink/8"
        >
          <div className="h-full rounded-full bg-brand-600" style={{ width: `${Math.max(2, s.plan_percent)}%` }} />
        </div>
        <span className="w-24 shrink-0 text-end text-xs tabular-nums text-ink/60">{t('memorization.of_plan', { percent: formatPercent(s.plan_percent, locale) })}</span>
      </div>
    </li>
  )
}
