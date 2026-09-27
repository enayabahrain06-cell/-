import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { dashboardFeedApi, type ActivityItem, type ActivityType } from '../../api/dashboard-feed'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import { Modal, SURFACE } from '../../components/ui'
import { EmptyState } from '../../components/ornaments'
import { formatMoney, formatNumber } from '../../lib/format'
import { relativeTime } from './relativeTime'
import { CardError, ListSkeleton } from './UpcomingCard'
import { useDashboardFilters } from './useDashboardFilters'

const TYPE: Record<ActivityType, { icon: string; cls: string }> = {
  attendance: { icon: 'attendance', cls: 'bg-brand-50 text-brand-700' },
  evaluation: { icon: 'evaluation', cls: 'bg-gold-500/12 text-gold-700' },
  registration: { icon: 'enroll', cls: 'bg-info/10 text-info-700' },
  payment: { icon: 'payments', cls: 'bg-brand-50 text-brand-700' },
  schedule: { icon: 'pin', cls: 'bg-ink/6 text-ink/65' },
}

/** "Recent activity": the last 10 events, with the full 30-day log in a dialog. */
export default function ActivityCard() {
  const { t, i18n } = useTranslation('dashboard-feed')
  const locale = i18n.language
  const filters = useDashboardFilters()
  const [full, setFull] = useState(false)
  const q = useQuery({ queryKey: ['dashboard', 'activity', filters, locale], queryFn: () => dashboardFeedApi.activity(filters), refetchInterval: 60_000 })

  return (
    <section className={`${SURFACE} flex flex-col`} aria-labelledby="activity-title" aria-busy={q.isLoading}>
      <h2 id="activity-title" className="flex items-center gap-2 border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        <Icon name="refresh" className="size-5 text-brand-700" />
        {t('activity.title')}
        {q.data && q.data.length > 0 && (
          <button type="button" onClick={() => setFull(true)} className="ms-auto rounded-lg px-2 py-1 text-sm font-medium text-brand-700 hover:bg-brand-50">
            {t('activity.full_log')}
          </button>
        )}
      </h2>

      {q.isLoading ? (
        <ListSkeleton rows={5} />
      ) : q.isError || !q.data ? (
        <CardError onRetry={() => void q.refetch()} />
      ) : q.data.length === 0 ? (
        <EmptyState size="sm" icon="refresh" title={t('activity.empty')} body={t('activity.empty_body')} />
      ) : (
        <ActivityList items={q.data} locale={locale} />
      )}

      {full && <FullLog onClose={() => setFull(false)} />}
    </section>
  )
}

function ActivityList({ items, locale }: { items: ActivityItem[]; locale: string }) {
  return (
    <ul className="divide-y divide-ink/6">
      {items.map((a) => (
        <li key={a.id}>
          <Row item={a} locale={locale} />
        </li>
      ))}
    </ul>
  )
}

function Row({ item: a, locale }: { item: ActivityItem; locale: string }) {
  const { t } = useTranslation('dashboard-feed')
  const type = TYPE[a.type]
  const stamp = new Date(a.at).toLocaleString(locale === 'ar' ? 'ar-BH' : 'en-BH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bahrain' })
  const body = (
    <>
      <span className={`mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg ${type.cls}`} title={t(`activity.type.${a.type}`)}>
        <Icon name={type.icon} className="size-4" />
        <span className="sr-only">{t(`activity.type.${a.type}`)}</span>
      </span>
      <div className="min-w-0 flex-1">
        <p dir="auto" className="text-start text-sm text-ink">
          {a.description}
          {a.amount_fils !== undefined && <span className="ms-1 font-semibold tabular-nums text-brand-700">{formatMoney(a.amount_fils, locale)}</span>}
        </p>
        <p className="mt-0.5 text-xs text-ink/55">
          {a.user && <><span dir="auto">{a.user}</span> · </>}
          <time dateTime={a.at} title={stamp}>{relativeTime(a.at, locale)}</time>
        </p>
      </div>
    </>
  )
  const cls = 'flex items-start gap-3 px-5 py-3'

  return a.link ? (
    <Link to={a.link} className={`${cls} hover:bg-ink/[0.03] focus-visible:bg-ink/[0.03] focus-visible:outline-none`}>{body}</Link>
  ) : (
    <div className={cls}>{body}</div>
  )
}

function FullLog({ onClose }: { onClose: () => void }) {
  const { t, i18n } = useTranslation('dashboard-feed')
  const locale = i18n.language
  const filters = useDashboardFilters()
  const [page, setPage] = useState(1)
  const q = useQuery({
    queryKey: ['dashboard', 'activity-all', filters, page, locale],
    queryFn: () => dashboardFeedApi.activityAll({ ...filters, page, per_page: 20 }),
    placeholderData: keepPreviousData,
  })

  return (
    <Modal title={t('activity.full_log')} onClose={onClose} wide>
      {q.data && <p className="mb-3 text-sm text-ink/55">{t('activity.window', { days: formatNumber(q.data.meta.window_days, locale) })}</p>}
      {q.isLoading ? (
        <ListSkeleton rows={6} />
      ) : q.isError || !q.data ? (
        <CardError onRetry={() => void q.refetch()} />
      ) : q.data.data.length === 0 ? (
        <EmptyState size="sm" icon="refresh" title={t('activity.empty')} />
      ) : (
        <div className={`-mx-5 ${q.isFetching ? 'opacity-70' : ''}`}>
          <ActivityList items={q.data.data} locale={locale} />
        </div>
      )}
      {q.data && (
        <div className="mt-4">
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />
        </div>
      )}
    </Modal>
  )
}
