import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { dashboardFeedApi, type UpcomingItem, type UpcomingType } from '../../api/dashboard-feed'
import Icon from '../../components/Icon'
import { Badge, buttonClass, type Tone, SURFACE } from '../../components/ui'
import { EmptyState } from '../../components/ornaments'
import { formatDate, formatNumber, formatWeekday } from '../../lib/format'
import { useDashboardFilters } from './useDashboardFilters'

const TAG: Record<UpcomingType, { tone: Tone; icon: string }> = {
  exam: { tone: 'info', icon: 'exams' },
  certificates: { tone: 'gold', icon: 'certificate' },
  messages: { tone: 'brand', icon: 'messages' },
  package: { tone: 'muted', icon: 'packages' },
}

/** "Coming up this week": one list over today … today + 6, grouped under a day heading. */
export default function UpcomingCard() {
  const { t, i18n } = useTranslation('dashboard-feed')
  const locale = i18n.language
  const filters = useDashboardFilters()
  const q = useQuery({ queryKey: ['dashboard', 'upcoming', filters, locale], queryFn: () => dashboardFeedApi.upcoming(filters), refetchInterval: 5 * 60_000 })

  const days = q.data ? groupByDate(q.data.items) : []

  return (
    <section className={`${SURFACE} flex flex-col`} aria-labelledby="upcoming-title" aria-busy={q.isLoading}>
      <h2 id="upcoming-title" className="flex items-center gap-2 border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        <Icon name="clock" className="size-5 text-brand-700" />
        {t('upcoming.title')}
        {q.data && q.data.items.length > 0 && <span className="text-ink/45">({formatNumber(q.data.items.length, locale)})</span>}
      </h2>

      {q.isLoading ? (
        <ListSkeleton />
      ) : q.isError || !q.data ? (
        <CardError onRetry={() => void q.refetch()} />
      ) : days.length === 0 ? (
        <EmptyState size="sm" icon="clock" title={t('upcoming.empty')} body={t('upcoming.empty_body')} />
      ) : (
        <ol className="max-h-[28rem] overflow-y-auto">
          {days.map(([date, items]) => (
            <li key={date}>
              <h3 className="sticky top-0 z-[1] bg-white/95 px-5 py-1.5 text-xs font-semibold text-ink/55 backdrop-blur-sm">
                {dayLabel(date, q.data!.from, locale, t)}
              </h3>
              <ul className="divide-y divide-ink/6">
                {items.map((item) => (
                  <li key={item.id}>
                    <Row item={item} locale={locale} />
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}

function Row({ item, locale }: { item: UpcomingItem; locale: string }) {
  const { t } = useTranslation('dashboard-feed')
  const tag = TAG[item.type]
  const n = (v: number) => formatNumber(v, locale)
  const time = item.at ? new Date(item.at).toLocaleTimeString(locale === 'ar' ? 'ar-BH' : 'en-BH', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Bahrain' }) : null

  const detail = [
    item.subtitle,
    item.type === 'exam' && item.count !== null ? t('upcoming.students', { count: item.count, n: n(item.count) }) : null,
    time,
    item.since && item.since < item.date ? t('upcoming.since', { date: formatDate(item.since, locale, { day: 'numeric', month: 'short' }) }) : null,
  ].filter(Boolean)

  return (
    <Link to={item.link} className="flex items-center gap-3 px-5 py-3 hover:bg-ink/[0.03] focus-visible:bg-ink/[0.03] focus-visible:outline-none">
      <Badge tone={tag.tone} className="shrink-0">
        <Icon name={tag.icon} className="size-3.5" />
        {t(`upcoming.type.${item.type}`)}
      </Badge>
      <div className="min-w-0 flex-1">
        <p dir="auto" className="truncate text-start text-sm font-medium text-ink">
          {item.title}
          {(item.type === 'certificates' || item.type === 'messages') && item.count !== null && (
            <span className="ms-1.5 rounded-full bg-ink/6 px-1.5 text-xs font-semibold tabular-nums text-ink/70">{n(item.count)}</span>
          )}
          {item.draft && <span className="ms-1.5 text-xs font-normal text-gold-700">· {t('upcoming.draft')}</span>}
        </p>
        {detail.length > 0 && <p dir="auto" className="truncate text-start text-xs text-ink/55">{detail.join(' · ')}</p>}
      </div>
      <Icon name="chevron" className="size-4 shrink-0 text-ink/30 rtl:rotate-180" />
    </Link>
  )
}

function groupByDate(items: UpcomingItem[]): [string, UpcomingItem[]][] {
  const map = new Map<string, UpcomingItem[]>()
  for (const i of items) map.set(i.date, [...(map.get(i.date) ?? []), i])
  return [...map.entries()]
}

function dayLabel(date: string, today: string, locale: string, t: (k: string) => string): string {
  const diff = Math.round((new Date(`${date}T12:00:00Z`).getTime() - new Date(`${today}T12:00:00Z`).getTime()) / 86_400_000)
  const full = `${formatWeekday(date, locale)} ${formatDate(date, locale, { day: 'numeric', month: 'long' })}`
  if (diff === 0) return `${t('upcoming.today')} · ${full}`
  if (diff === 1) return `${t('upcoming.tomorrow')} · ${full}`
  return full
}

export function ListSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <div className="space-y-3 p-5" aria-hidden>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-3">
          <div className="h-5 w-16 animate-pulse rounded-full bg-ink/8" />
          <div className="flex-1 space-y-1.5">
            <div className="h-3.5 w-3/4 animate-pulse rounded bg-ink/8" />
            <div className="h-3 w-1/2 animate-pulse rounded bg-ink/5" />
          </div>
        </div>
      ))}
    </div>
  )
}

export function CardError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation('dashboard-feed')
  return (
    <div role="alert" className="m-4 rounded-xl border border-danger/25 bg-danger/5 p-4 text-center text-sm text-danger">
      <p>{t('error')}</p>
      <button type="button" onClick={onRetry} className={buttonClass('secondary', 'mt-2')}>
        {t('retry')}
      </button>
    </div>
  )
}
