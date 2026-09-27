import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { dashboardApi, type DashboardAlert, type DashboardData, type LocationStatus, type TodaySession } from '../../api/dashboard'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { formatDate, formatHijri, formatMoney, formatNumber, formatPercent, formatTime, formatWeekday } from '../../lib/format'
import AttendanceChart from './AttendanceChart'
import { EmptyState, PageBand } from '../../components/ornaments'

export default function DashboardPage() {
  const { t, i18n } = useTranslation('dashboard')
  const { user } = useAuth()
  const locale = i18n.language
  const query = useQuery({ queryKey: ['dashboard', locale], queryFn: dashboardApi.get, refetchInterval: 60_000 })

  const today = query.data?.date ?? new Date().toISOString().slice(0, 10)

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageBand
        title={t('greeting', { name: user?.name })}
        subtitle={
          <>
            {formatWeekday(today, locale)}{locale === 'ar' ? '، ' : ', '}{formatDate(today, locale)} <span className="mx-1 text-white/50">·</span> {formatHijri(today, locale)}
          </>
        }
        actions={
          <>
            {query.data && (
              <span className="rounded-full bg-white/10 px-3 py-1 text-sm font-medium text-gold-300 ring-1 ring-gold-300/25">
                {query.data.scope.own_circles_only ? t('own_circles') : t(`track.${query.data.scope.track}`)}
              </span>
            )}
            <button
              type="button"
              onClick={() => void query.refetch()}
              className="inline-flex items-center gap-1.5 rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-sm text-white hover:bg-white/15"
            >
              <Icon name="refresh" className={`size-4 ${query.isFetching ? 'motion-safe:animate-spin' : ''}`} />
              {t('refresh')}
            </button>
          </>
        }
      />

      {query.isLoading ? (
        <Skeleton />
      ) : query.isError || !query.data ? (
        <div role="alert" className="rounded-2xl border border-danger/25 bg-danger/5 p-6 text-center text-danger">
          <p>{t('error')}</p>
          <button type="button" onClick={() => void query.refetch()} className="mt-3 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-ink shadow-sm">
            {t('retry')}
          </button>
        </div>
      ) : (
        <Content data={query.data} locale={locale} />
      )}
    </div>
  )
}

function Content({ data, locale }: { data: DashboardData; locale: string }) {
  const { t } = useTranslation('dashboard')
  const k = data.kpis

  return (
    <>
      <section aria-label={t('title')} className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <Kpi label={t('kpi.active_students')} value={formatNumber(k.active_students, locale)} icon="students" />
        <Kpi label={t('kpi.active_circles')} value={formatNumber(k.active_circles, locale)} icon="lessons" />
        <Kpi
          label={t('kpi.sessions_today')}
          value={formatNumber(k.sessions_today, locale)}
          icon="attendance"
          hint={t('kpi.attendance_taken', { taken: formatNumber(k.attendance_taken_today, locale), total: formatNumber(k.sessions_today, locale) })}
        />
        <Kpi label={t('kpi.attendance_rate_7d')} value={formatPercent(k.attendance_rate_7d, locale)} icon="check" />
        {k.pending_registrations !== undefined && (
          <Kpi label={t('kpi.pending_registrations')} value={formatNumber(k.pending_registrations, locale)} icon="packages" tone={k.pending_registrations > 0 ? 'gold' : undefined} />
        )}
        {k.collected_this_month_fils !== undefined && (
          <Kpi
            label={t('kpi.collected_this_month')}
            value={formatMoney(k.collected_this_month_fils, locale)}
            icon="payments"
            hint={k.students_due ? `${t('kpi.students_due')}: ${formatNumber(k.students_due, locale)} · ${t('kpi.outstanding', { amount: formatMoney(k.outstanding_fils ?? 0, locale) })}` : undefined}
          />
        )}
      </section>

      <div className="grid gap-6 lg:grid-cols-5">
        <TodayList sessions={data.today} locale={locale} />
        <AlertsBox alerts={data.alerts.items} total={data.alerts.total} locale={locale} />
      </div>

      <AttendanceChart days={data.attendance_chart} />
    </>
  )
}

function Kpi({ label, value, icon, hint, tone }: { label: string; value: string; icon: string; hint?: string; tone?: 'gold' }) {
  return (
    <div className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-ink/60">{label}</p>
        <span className={`rounded-lg p-1.5 ${tone === 'gold' ? 'bg-gold-500/12 text-gold-700' : 'bg-brand-50 text-brand-700'}`}>
          <Icon name={icon} className="size-4" />
        </span>
      </div>
      <p className="mt-2 text-2xl font-semibold tabular-nums text-ink">{value}</p>
      {hint && <p className="mt-1 text-xs leading-snug text-ink/55">{hint}</p>}
    </div>
  )
}

const STATUS_STYLE: Record<LocationStatus, string> = {
  ok: 'bg-brand-50 text-brand-700',
  changed: 'bg-gold-500/12 text-gold-700',
  conflict: 'bg-danger/10 text-danger',
  no_hall: 'bg-danger/10 text-danger',
  cancelled: 'bg-ink/8 text-ink/60',
}

const STATUS_ICON: Record<LocationStatus, string> = { ok: 'check', changed: 'pin', conflict: 'alert', no_hall: 'alert', cancelled: 'close' }

function TodayList({ sessions, locale }: { sessions: TodaySession[]; locale: string }) {
  const { t } = useTranslation('dashboard')

  return (
    <section className="rounded-2xl border border-ink/8 bg-white shadow-sm lg:col-span-3" aria-labelledby="today-title">
      <h2 id="today-title" className="border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        {t('today.title')} <span className="ms-1 text-ink/45">({formatNumber(sessions.length, locale)})</span>
      </h2>
      {sessions.length === 0 ? (
        <EmptyState size="sm" icon="lessons" title={t('today.empty')} />
      ) : (
        <ul className="divide-y divide-ink/6">
          {sessions.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <div className="w-20 shrink-0 text-sm tabular-nums text-ink/70">
                <p className="font-semibold text-ink">{formatTime(s.start_time, locale)}</p>
                <p>{formatTime(s.end_time, locale)}</p>
              </div>
              <div className="min-w-0 flex-1">
                <p dir="auto" className="truncate text-start font-medium text-ink">{s.lesson}</p>
                <p dir="auto" className="truncate text-start text-sm text-ink/55">
                  {s.teacher ?? t('today.no_teacher')}
                  {s.location && <> · {s.location}</>}
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_STYLE[s.location_status]}`}>
                  <Icon name={STATUS_ICON[s.location_status]} className="size-3.5" />
                  {t(`today.status.${s.location_status}`)}
                </span>
                <span className={`rounded-full px-2.5 py-1 text-xs ${s.attendance_taken ? 'bg-brand-50 text-brand-700' : 'bg-ink/6 text-ink/60'}`}>
                  {s.attendance_taken
                    ? t('today.present_of', { present: formatNumber(s.present, locale), enrolled: formatNumber(s.enrolled, locale) })
                    : t('today.attendance_pending')}
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

const SEVERITY_STYLE: Record<DashboardAlert['severity'], string> = {
  danger: 'border-s-danger bg-danger/5',
  warning: 'border-s-gold-500 bg-gold-500/6',
  info: 'border-s-[#3F74C0] bg-[#3F74C0]/5',
}

function AlertsBox({ alerts, total, locale }: { alerts: DashboardAlert[]; total: number; locale: string }) {
  const { t } = useTranslation('dashboard')
  const qc = useQueryClient()
  const resolve = useMutation({
    mutationFn: (id: number) => dashboardApi.resolveAlert(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['dashboard'] }),
  })

  return (
    <section className="rounded-2xl border border-ink/8 bg-white shadow-sm lg:col-span-2" aria-labelledby="alerts-title">
      <h2 id="alerts-title" className="flex items-center gap-2 border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        <Icon name="alert" className="size-5 text-gold-700" />
        {t('alerts.title')}
        {total > 0 && <span className="ms-auto rounded-full bg-danger px-2 py-0.5 text-xs font-semibold text-white tabular-nums">{formatNumber(total, locale)}</span>}
      </h2>
      {alerts.length === 0 ? (
        <EmptyState size="sm" icon="check" title={t('alerts.empty')} />
      ) : (
        <ul className="max-h-[26rem] space-y-2 overflow-y-auto p-3">
          {alerts.map((a, i) => (
            <li key={a.id ?? `c-${i}`} className={`rounded-xl border-s-4 px-3 py-2.5 ${SEVERITY_STYLE[a.severity]}`}>
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-medium text-ink/55">
                    {t(`alerts.severity.${a.severity}`)} · {a.type_label}
                  </p>
                  <p dir="auto" className="mt-0.5 text-start text-sm font-medium text-ink">{a.title}</p>
                  {a.body && <p dir="auto" className="mt-0.5 text-start text-sm text-ink/60">{a.body}</p>}
                </div>
                {a.resolvable && a.id !== null && (
                  <button
                    type="button"
                    disabled={resolve.isPending && resolve.variables === a.id}
                    onClick={() => resolve.mutate(a.id as number)}
                    className="shrink-0 rounded-lg border border-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-ink/70 hover:bg-ink/5 disabled:opacity-60"
                  >
                    {resolve.isPending && resolve.variables === a.id ? t('alerts.resolving') : t('alerts.resolve')}
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function Skeleton() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {Array.from({ length: 6 }).map((_, i) => <div key={i} className="h-28 animate-pulse rounded-2xl bg-white/70" />)}
      </div>
      <div className="grid gap-6 lg:grid-cols-5">
        <div className="h-72 animate-pulse rounded-2xl bg-white/70 lg:col-span-3" />
        <div className="h-72 animate-pulse rounded-2xl bg-white/70 lg:col-span-2" />
      </div>
    </div>
  )
}
