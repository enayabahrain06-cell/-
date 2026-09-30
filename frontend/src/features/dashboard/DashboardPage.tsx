import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { dashboardApi, type DashboardData, type LocationStatus, type TodaySession } from '../../api/dashboard'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { buttonClass, ErrorState, Notice, SURFACE, ROW_MAIN } from '../../components/ui'
import { formatDate, formatHijri, formatMoney, formatNumber, formatPercent, formatTime, formatWeekday } from '../../lib/format'
import QuickAttendanceDialog from '../attendance/QuickAttendanceDialog'
import { RecordPaymentDialog } from '../payments/PaymentDialogs'
import AgeDonut from './AgeDonut'
import AlertsCard from './AlertsCard'
import ActivityCard from './ActivityCard'
import AttendanceChart from './AttendanceChart'
import FeesCard from './FeesCard'
import MemorizationCard from './MemorizationCard'
import UpcomingCard from './UpcomingCard'
import MobileDashboard from './MobileDashboard'
import { EmptyState, PageBand } from '../../components/ornaments'

export default function DashboardPage() {
  const { t, i18n } = useTranslation('dashboard')
  const { user } = useAuth()
  const locale = i18n.language
  const query = useQuery({ queryKey: ['dashboard', locale], queryFn: dashboardApi.get, refetchInterval: 60_000 })

  const today = query.data?.date ?? new Date().toISOString().slice(0, 10)

  return (
    <>
    <MobileDashboard query={query} />
    <div className="hidden space-y-5 lg:block">
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
            {query.data?.generated_at && (
              <span className="text-xs text-white/60">
                {t('updated_at', { time: new Date(query.data.generated_at).toLocaleTimeString(locale === 'ar' ? 'ar-BH' : 'en-BH', { hour: '2-digit', minute: '2-digit' }) })}
              </span>
            )}
            <button
              type="button"
              onClick={() => void query.refetch()}
              className={buttonClass('onDeepGhost')}
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
        <ErrorState message={t('error')} onRetry={() => void query.refetch()} />
      ) : (
        <Content data={query.data} locale={locale} />
      )}
    </div>
    </>
  )
}

function Content({ data, locale }: { data: DashboardData; locale: string }) {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const k = data.kpis
  const canAttendance = can('attendance.view', 'attendance.record')
  // Fees renders nothing without wallet access (teachers); its grid cell must go too, or it leaves an empty slot.
  const canFees = can('wallets.view')
  const kpiCount = 4 + (k.pending_registrations !== undefined ? 1 : 0) + (k.collected_this_month_fils !== undefined ? 1 : 0)

  return (
    <>
      <QuickActions />

      <section aria-label={t('title')} className={`grid grid-cols-2 gap-3 ${KPI_COLS[kpiCount]}`}>
        <Kpi label={t('kpi.active_students')} value={formatNumber(k.active_students, locale)} icon="students" to={can('students.view') ? '/students' : undefined} />
        <Kpi label={t('kpi.active_circles')} value={formatNumber(k.active_circles, locale)} icon="lessons" to={can('lessons.view') ? '/lessons' : undefined} />
        <Kpi
          label={t('kpi.sessions_today')}
          value={formatNumber(k.sessions_today, locale)}
          icon="attendance"
          hint={t('kpi.attendance_taken', { taken: formatNumber(k.attendance_taken_today, locale), total: formatNumber(k.sessions_today, locale) })}
          to={canAttendance ? '/attendance' : undefined}
        />
        <Kpi label={t('kpi.attendance_rate_7d')} value={formatPercent(k.attendance_rate_7d, locale)} icon="check" />
        {k.pending_registrations !== undefined && (
          <Kpi
            label={t('kpi.pending_registrations')}
            value={formatNumber(k.pending_registrations, locale)}
            icon="packages"
            tone={k.pending_registrations > 0 ? 'gold' : undefined}
            to="/packages?tab=requests&status=pending"
          />
        )}
        {k.collected_this_month_fils !== undefined && (
          <Kpi
            label={t('kpi.collected_this_month')}
            value={formatMoney(k.collected_this_month_fils, locale)}
            icon="payments"
            hint={k.students_due ? `${t('kpi.students_due')}: ${formatNumber(k.students_due, locale)} · ${t('kpi.outstanding', { amount: formatMoney(k.outstanding_fils ?? 0, locale) })}` : undefined}
            to={k.students_due && can('students.view') ? '/students?due=1' : '/payments'}
          />
        )}
      </section>

      {/* Rows pair cards of similar height so a stretched card is never left mostly empty. Today's circles and
          Coming up are both short, time-based lists, so they stack in one column beside the tall alerts list.
          md (2 columns) and xl (12 columns): alerts | today + coming up, then
          with fees: attendance (full width), fees | ages;
          without fees (teachers): attendance | ages on xl, ages full width (chart beside its legend) on md;
          then memorization | activity. Cards in a row stretch to the same height (*:*:h-full).
          1 column on phones. */}
      <div className="grid gap-5 *:*:h-full md:grid-cols-2 xl:grid-cols-12">
        <div className="min-w-0 xl:col-span-7"><AlertsCard /></div>
        <div className="min-w-0 xl:col-span-5">
          <div className="flex flex-col gap-5">
            <TodayList sessions={data.today} locale={locale} />
            <div className="min-h-0 flex-1 *:h-full"><UpcomingCard /></div>
          </div>
        </div>
        {canFees ? (
          <>
            <div className="min-w-0 md:col-span-2 xl:col-span-12"><AttendanceChart days={data.attendance_chart} /></div>
            <div className="min-w-0 xl:col-span-7"><FeesCard /></div>
            <div className="min-w-0 xl:col-span-5"><AgeDonut data={data.age_distribution} /></div>
          </>
        ) : (
          <>
            <div className="min-w-0 md:col-span-2 xl:col-span-8"><AttendanceChart days={data.attendance_chart} /></div>
            <div className="min-w-0 md:col-span-2 xl:col-span-4"><AgeDonut data={data.age_distribution} /></div>
          </>
        )}
        <div className="min-w-0 xl:col-span-6"><MemorizationCard /></div>
        <div className="min-w-0 xl:col-span-6"><ActivityCard /></div>
      </div>
    </>
  )
}

/** Shortcuts to the day's common tasks, each shown only with its permission. */
function QuickActions() {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const qc = useQueryClient()
  const [paying, setPaying] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const links: { to: string; icon: string; label: string; show: boolean }[] = [
    { to: '/attendance', icon: 'attendance', label: t('actions.attendance'), show: can('attendance.record') },
    { to: '/enrollment', icon: 'enroll', label: t('actions.enroll'), show: can('enrollment.quick') },
    { to: '/packages?tab=requests&status=pending', icon: 'packages', label: t('actions.requests'), show: can('registrations.view') },
    { to: '/lessons', icon: 'lessons', label: t('actions.halls'), show: can('lessons.view', 'locations.view') },
  ]
  const visible = links.filter((l) => l.show)
  if (visible.length === 0 && !can('payments.record')) return null

  const btn = 'inline-flex items-center gap-2 rounded-xl border border-ink/8 bg-white px-3.5 py-2 text-sm font-medium text-ink shadow-sm hover:border-brand-600/30 hover:bg-brand-50'

  return (
    <section aria-label={t('actions.title')} className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {visible.map((l) => (
          <Link key={l.to} to={l.to} className={btn}>
            <Icon name={l.icon} className="size-4 text-brand-700" />
            {l.label}
          </Link>
        ))}
        {can('payments.record') && (
          <button type="button" className={btn} onClick={() => setPaying(true)}>
            <Icon name="payments" className="size-4 text-brand-700" />
            {t('actions.payment')}
          </button>
        )}
      </div>
      {notice && <Notice>{notice}</Notice>}
      {paying && (
        <RecordPaymentDialog
          onClose={() => setPaying(false)}
          onDone={(msg) => {
            setPaying(false)
            setNotice(msg)
            void qc.invalidateQueries({ queryKey: ['dashboard'] })
          }}
        />
      )}
    </section>
  )
}

/** KPI columns by tile count, so a row never ends with one orphan tile (teachers see 4, admins 6). */
const KPI_COLS: Record<number, string> = {
  4: 'md:grid-cols-4',
  5: 'md:grid-cols-3 xl:grid-cols-5',
  6: 'md:grid-cols-3 2xl:grid-cols-6',
}

function Kpi({ label, value, icon, hint, tone, to }: { label: string; value: string; icon: string; hint?: string; tone?: 'gold'; to?: string }) {
  const body = (
    <>
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-ink/60">{label}</p>
        <span className={`rounded-lg p-1.5 ${tone === 'gold' ? 'bg-gold-500/12 text-gold-700' : 'bg-brand-50 text-brand-700'}`}>
          <Icon name={icon} className="size-4" />
        </span>
      </div>
      {/* Smaller on phones: a money value ("BHD 312.500", one unbreakable string) must fit a half-width tile at 320px */}
      <p className="mt-1.5 text-lg font-semibold tabular-nums text-ink sm:text-2xl">{value}</p>
      {hint && <p className="mt-1 text-xs leading-snug text-ink/55">{hint}</p>}
    </>
  )
  const cls = `${SURFACE} flex min-w-0 flex-col p-4`

  return to ? (
    <Link to={to} className={`${cls} transition hover:border-brand-600/30 hover:shadow-md focus-visible:outline-2 focus-visible:outline-brand-600`}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
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
  const { can } = useAuth()
  const canLessons = can('lessons.view')

  return (
    <section className={`${SURFACE} min-w-0`} aria-labelledby="today-title">
      <h2 id="today-title" className="border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        {t('today.title')} <span className="ms-1 text-ink/45">({formatNumber(sessions.length, locale)})</span>
      </h2>
      {sessions.length === 0 ? (
        <EmptyState size="sm" icon="lessons" title={t('today.empty')} />
      ) : (
        <ul className="divide-y divide-ink/6">
          {sessions.map((s) => {
            const cancelled = s.location_status === 'cancelled'
            const hallProblem = s.location_status === 'conflict' || s.location_status === 'no_hall'
            const status = (
              <>
                <Icon name={STATUS_ICON[s.location_status]} className="size-3.5" />
                {t(`today.status.${s.location_status}`)}
              </>
            )
            const statusCls = `inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_STYLE[s.location_status]}`

            return (
              <li key={s.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                <div className="w-20 shrink-0 text-sm tabular-nums text-ink/70">
                  <p className="font-semibold text-ink">{formatTime(s.start_time, locale)}</p>
                  <p>{formatTime(s.end_time, locale)}</p>
                </div>
                <div className={ROW_MAIN}>
                  {canLessons ? (
                    <Link to={`/lessons/${s.lesson_id}`} dir="auto" className="block truncate text-start font-medium text-ink hover:text-brand-700">{s.lesson}</Link>
                  ) : (
                    <p dir="auto" className="truncate text-start font-medium text-ink">{s.lesson}</p>
                  )}
                  <p dir="auto" className="truncate text-start text-sm text-ink/55">
                    {s.teacher ?? t('today.no_teacher')}
                    {s.location && <> · {s.location}</>}
                  </p>
                </div>
                <div className="flex basis-full flex-wrap items-center gap-2 ps-24 sm:basis-auto sm:ps-0">
                  {hallProblem && canLessons ? (
                    <Link to={`/lessons/${s.lesson_id}`} className={`${statusCls} hover:ring-1 hover:ring-danger/40`} title={t('today.fix_hall')}>{status}</Link>
                  ) : (
                    <span className={statusCls}>{status}</span>
                  )}
                  {!cancelled && <SessionAction session={s} locale={locale} />}
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}

/** Take attendance when pending; once taken, show the count (opens the sheet) and offer evaluation. */
function SessionAction({ session: s, locale }: { session: TodaySession; locale: string }) {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const canRecord = can('attendance.record')
  const canView = canRecord || can('attendance.view')
  const [quick, setQuick] = useState(false)

  if (!s.attendance_taken) {
    return canRecord ? (
      <>
        <button type="button" onClick={() => setQuick(true)} aria-haspopup="dialog" className={buttonClass('primary', 'shrink-0')}>
          <Icon name="attendance" className="size-4" />
          {t('today.take_attendance')}
        </button>
        {quick && <QuickAttendanceDialog sessionId={s.id} onClose={() => setQuick(false)} />}
      </>
    ) : (
      <span className="rounded-full bg-ink/6 px-2.5 py-1 text-xs text-ink/60">{t('today.attendance_pending')}</span>
    )
  }

  const count = t('today.present_of', { present: formatNumber(s.present, locale), enrolled: formatNumber(s.enrolled, locale) })

  return (
    <>
      {canView ? (
        <Link to={`/attendance/${s.id}`} className="rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700 hover:ring-1 hover:ring-brand-600/30">{count}</Link>
      ) : (
        <span className="rounded-full bg-brand-50 px-2.5 py-1 text-xs text-brand-700">{count}</span>
      )}
      {can('evaluations.record') && (
        <Link to={`/evaluation/${s.id}`} className="inline-flex items-center gap-1 rounded-full border border-ink/10 px-2.5 py-1 text-xs font-medium text-ink/70 hover:bg-ink/5">
          <Icon name="evaluation" className="size-3.5" />
          {t('today.evaluate')}
        </Link>
      )}
    </>
  )
}

function Skeleton() {
  return (
    <div className="space-y-5" aria-busy="true">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
        {Array.from({ length: 6 }).map((_, i) => <div key={i} className="h-28 animate-pulse rounded-2xl bg-white/70" />)}
      </div>
      <div className="grid gap-5 md:grid-cols-2">
        <div className="h-72 animate-pulse rounded-2xl bg-white/70" />
        <div className="h-72 animate-pulse rounded-2xl bg-white/70" />
      </div>
    </div>
  )
}
