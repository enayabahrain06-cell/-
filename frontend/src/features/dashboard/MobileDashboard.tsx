import { useEffect, useState } from 'react'
import { useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router-dom'
import { dashboardApi, type DashboardAlert, type DashboardData, type TodaySession } from '../../api/dashboard'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { Khatam } from '../../components/ornaments'
import { MCard, MEmpty, MList, MRow, MSection, Pill, Skeleton, M_BTN_PRIMARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatHijri, formatMoney, formatNumber, formatPercent, formatTime, formatWeekday } from '../../lib/format'
import { RecordPaymentDialog } from '../payments/PaymentDialogs'

/**
 * Dashboard below lg (mobile-redesign-spec.md §6.4, artboard Main.dc.html). Same query as the desktop page
 * (passed in), same links and permissions; only the layout differs.
 */
export default function MobileDashboard({ query }: { query: UseQueryResult<DashboardData> }) {
  const { t, i18n } = useTranslation('dashboard')
  const { user } = useAuth()
  const locale = i18n.language
  const data = query.data
  const today = data?.date ?? new Date().toISOString().slice(0, 10)

  return (
    <div className="space-y-6 lg:hidden">
      <section className="relative overflow-hidden rounded-card bg-deep px-4 py-4 text-white">
        <Khatam className="pointer-events-none absolute -end-6 -top-6 size-28 text-gold-400 opacity-10" />
        <h1 className="relative font-display text-2xl leading-[34px] text-gold-300">{t('greeting', { name: user?.name })}</h1>
        <p className="relative mt-1 truncate text-[13px] text-white/85">
          {formatWeekday(today, locale)}{locale === 'ar' ? '، ' : ', '}{formatDate(today, locale)} · {formatHijri(today, locale)}
        </p>
        <div className="relative mt-3 flex items-center gap-2">
          {data && (
            <span className="inline-flex h-6 shrink-0 items-center rounded-full bg-white/10 px-2.5 text-xs font-semibold text-gold-300 ring-1 ring-gold-300/25">
              {data.scope.own_circles_only ? t('own_circles') : t(`track.${data.scope.track}`)}
            </span>
          )}
          {data?.generated_at && (
            <span className="min-w-0 flex-1 truncate text-xs tabular-nums text-white/75">
              {t('updated_at', { time: new Date(data.generated_at).toLocaleTimeString(locale === 'ar' ? 'ar-BH' : 'en-BH', { hour: '2-digit', minute: '2-digit' }) })}
            </span>
          )}
          <button type="button" onClick={() => void query.refetch()} aria-label={t('mobile.refresh')} title={t('mobile.refresh')}
            className="ms-auto inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-white/20 bg-white/10 text-white">
            <Icon name="refresh" className={`size-5 ${query.isFetching ? 'motion-safe:animate-spin' : ''}`} />
          </button>
        </div>
      </section>

      {query.isLoading ? <DashboardSkeleton /> : data ? <Content data={data} locale={locale} /> : (
        <MEmpty icon="alert" text={t('error')} action={<button type="button" onClick={() => void query.refetch()} className="text-[15px] font-semibold text-info">{t('common:retry')}</button>} />
      )}
    </div>
  )
}

function Content({ data, locale }: { data: DashboardData; locale: string }) {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const k = data.kpis
  const n = (v: number) => formatNumber(v, locale)

  return (
    <>
      <QuickActions />

      <MSection title={t('today.title')} action={can('attendance.view', 'attendance.record') ? { label: t('mobile.all'), to: '/attendance' } : undefined}>
        {data.today.length === 0 ? (
          <MCard><MEmpty icon="lessons" text={t('today.empty')} /></MCard>
        ) : (
          <ul className="space-y-3">
            {data.today.map((s) => <TodayCard key={s.id} s={s} locale={locale} />)}
          </ul>
        )}
      </MSection>

      <MSection title={t('mobile.quick_look')}>
        <div className="grid grid-cols-2 gap-3 *:min-w-0">
          <Kpi label={t('kpi.active_students')} value={n(k.active_students)} icon="students" to={can('students.view') ? '/students' : undefined} />
          <Kpi label={t('kpi.active_circles')} value={n(k.active_circles)} icon="lessons" to={can('lessons.view') ? '/lessons' : undefined} />
          <Kpi label={t('kpi.attendance_rate_7d')} value={formatPercent(k.attendance_rate_7d, locale)} icon="check" progress={k.attendance_rate_7d ?? undefined} />
          {k.pending_registrations !== undefined ? (
            <Kpi label={t('kpi.pending_registrations')} value={n(k.pending_registrations)} icon="packages" gold={k.pending_registrations > 0} to="/packages?tab=requests&status=pending" />
          ) : (
            <Kpi label={t('kpi.sessions_today')} value={n(k.sessions_today)} icon="attendance"
              hint={t('kpi.attendance_taken', { taken: n(k.attendance_taken_today), total: n(k.sessions_today) })}
              to={can('attendance.view', 'attendance.record') ? '/attendance' : undefined} />
          )}
          {k.collected_this_month_fils !== undefined && (
            <Kpi wide label={t('kpi.collected_this_month')} value={formatMoney(k.collected_this_month_fils, locale)} icon="payments"
              to={k.students_due && can('students.view') ? '/students?due=1' : '/payments'}
              pill={k.students_due ? t('mobile.due_pill', { n: n(k.students_due) }) : undefined} />
          )}
        </div>
      </MSection>

      {can('dashboard.view') && <AlertsList locale={locale} />}
    </>
  )
}

/** One primary CTA (take attendance) and up to four icon tiles for the other shortcuts. */
function QuickActions() {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const qc = useQueryClient()
  const [paying, setPaying] = useState(false)

  const tiles: { key: string; icon: string; label: string; to?: string; onClick?: () => void; show: boolean }[] = [
    { key: 'enroll', icon: 'enroll', label: t('actions.enroll'), to: '/enrollment', show: can('enrollment.quick') },
    { key: 'requests', icon: 'packages', label: t('actions.requests'), to: '/packages?tab=requests&status=pending', show: can('registrations.view') },
    { key: 'halls', icon: 'lessons', label: t('actions.halls'), to: '/lessons', show: can('lessons.view', 'locations.view') },
    { key: 'payment', icon: 'payments', label: t('actions.payment'), onClick: () => setPaying(true), show: can('payments.record') },
  ].filter((x) => x.show)
  const tile = 'flex h-[84px] min-w-0 flex-col items-center justify-center gap-1.5 rounded-card border border-ink/10 bg-white px-1 text-center text-xs font-semibold leading-4 text-ink shadow-card'
  const circle = (icon: string) => <span className="inline-grid size-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700"><Icon name={icon} className="size-5" /></span>

  return (
    <section aria-label={t('actions.title')} className="space-y-3">
      {can('attendance.record') && (
        <Link to="/attendance" className={`${M_BTN_PRIMARY} w-full`}>
          <Icon name="attendance" className="size-5" />
          {t('actions.attendance')}
        </Link>
      )}
      {tiles.length > 0 && (
        <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${Math.max(tiles.length, 3)}, minmax(0, 1fr))` }}>
          {tiles.map((x) => x.to ? (
            <Link key={x.key} to={x.to} className={tile}>{circle(x.icon)}<span className="line-clamp-2">{x.label}</span></Link>
          ) : (
            <button key={x.key} type="button" onClick={x.onClick} className={tile}>{circle(x.icon)}<span className="line-clamp-2">{x.label}</span></button>
          ))}
        </div>
      )}
      {paying && (
        <RecordPaymentDialog onClose={() => setPaying(false)} onDone={() => { setPaying(false); void qc.invalidateQueries({ queryKey: ['dashboard'] }) }} />
      )}
    </section>
  )
}

function TodayCard({ s, locale }: { s: TodaySession; locale: string }) {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const hallProblem = s.location_status === 'conflict' || s.location_status === 'no_hall'
  const cancelled = s.location_status === 'cancelled'
  const pct = s.enrolled > 0 ? Math.round((s.present / s.enrolled) * 100) : 0
  const pill: { tone: PillTone; text: string } = cancelled ? { tone: 'neutral', text: t('today.status.cancelled') }
    : hallProblem ? { tone: 'err', text: t(`today.status.${s.location_status}`) }
      : s.attendance_taken ? { tone: 'ok', text: t('mobile.taken', { present: formatNumber(s.present, locale), enrolled: formatNumber(s.enrolled, locale) }) }
        : { tone: 'warn', text: t('mobile.not_taken') }
  const to = can('lessons.view') ? `/lessons/${s.lesson_id}` : can('attendance.view', 'attendance.record') ? `/attendance/${s.id}` : undefined

  const body = (
    <>
      <div className="flex items-start justify-between gap-3">
        <p dir="auto" className="min-w-0 truncate text-[15px] font-semibold text-ink">{s.lesson}</p>
        <Pill tone={pill.tone}>{pill.text}</Pill>
      </div>
      <p className="mt-1 truncate text-[13px] text-ink/65">
        <span className="tabular-nums">{formatTime(s.start_time, locale)}</span>
        {s.location && <> · <span dir="auto">{s.location}</span></>}
        {' · '}{t('mobile.students', { n: formatNumber(s.enrolled, locale) })}
      </p>
      <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-ink/5" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={t('kpi.attendance_rate_7d')}>
        <div className="h-full rounded-full bg-chart-present" style={{ width: `${s.attendance_taken ? pct : 0}%` }} />
      </div>
    </>
  )
  return <li>{to ? <Link to={to} className={`${M_CARD} block p-4`}>{body}</Link> : <div className={`${M_CARD} p-4`}>{body}</div>}</li>
}

function Kpi({ label, value, icon, to, hint, progress, gold, wide, pill }: { label: string; value: string; icon: string; to?: string; hint?: string; progress?: number; gold?: boolean; wide?: boolean; pill?: string }) {
  const body = (
    <>
      <div className="flex items-center gap-2">
        <span className={`inline-grid size-7 shrink-0 place-items-center rounded-lg ${gold ? 'bg-gold-500/12 text-gold-700' : 'bg-brand-50 text-brand-700'}`}><Icon name={icon} className="size-4" /></span>
        <span className="min-w-0 truncate text-xs font-semibold text-ink/65">{label}</span>
      </div>
      <div className="mt-2 flex items-end justify-between gap-2">
        <p className="min-w-0 truncate text-[28px] font-semibold leading-9 tabular-nums text-ink">{value}</p>
        {pill && <Pill tone="warn" className="mb-1.5">{pill}</Pill>}
      </div>
      {progress !== undefined && (
        <div className="mt-2 h-1 overflow-hidden rounded-full bg-ink/5"><div className="h-full rounded-full bg-chart-present" style={{ width: `${Math.min(100, Math.max(0, progress))}%` }} /></div>
      )}
      {hint && <p className="mt-1 truncate text-xs text-ink/65">{hint}</p>}
    </>
  )
  const cls = `${M_CARD} block p-4 ${wide ? 'col-span-2' : ''}`
  return to ? <Link to={to} className={cls}>{body}</Link> : <div className={cls}>{body}</div>
}

const SEVERITY_TONE: Record<DashboardAlert['severity'], PillTone> = { danger: 'err', warning: 'warn', info: 'neutral' }

/** Where an alert row opens on mobile: the same destination as the desktop card's main link for that type. */
function alertLink(a: DashboardAlert, can: (...p: string[]) => boolean): string | undefined {
  const s = a.subject
  switch (a.type) {
    case 'location_conflict': case 'lesson_no_teacher': return s && can('lessons.view') ? `/lessons/${s.id}` : undefined
    case 'repeated_absence': return s && can('students.view') ? `/students/${s.id}` : undefined
    case 'registration_request': return can('registrations.view') ? `/packages?tab=requests&status=pending${s ? `&package_id=${s.id}` : ''}` : undefined
    case 'invoice_overdue': return s?.student_id && can('students.view') ? `/students/${s.student_id}?tab=wallet` : can('wallets.view') ? '/payments?tab=invoices' : undefined
    case 'lottery_pending': return s && can('lottery.view') ? `/lottery/${s.id}` : undefined
    case 'exam_upcoming': return s && can('exams.view') ? `/exams/${s.id}` : undefined
    default: return undefined
  }
}

/** The alerts the bell points to (#alerts): the five most urgent, each opening its subject. */
function AlertsList({ locale }: { locale: string }) {
  const { t } = useTranslation('dashboard')
  const { can } = useAuth()
  const location = useLocation()
  const q = useQuery({ queryKey: ['dashboard', 'alerts', 'mobile', locale], queryFn: () => dashboardApi.alerts({ per_page: 5 }), refetchInterval: 60_000 })

  useEffect(() => {
    if (location.hash === '#alerts' && q.data) document.getElementById('alerts')?.scrollIntoView({ block: 'start' })
  }, [location.hash, q.data])

  const total = q.data?.meta.all_total ?? 0
  return (
    <section id="alerts" className="scroll-mt-20 space-y-3">
      <h2 className="text-lg font-semibold text-ink">{t('alerts.title')} {total > 0 && <span className="text-ink/65 tabular-nums">({formatNumber(total, locale)})</span>}</h2>
      {q.isLoading ? (
        <div className="space-y-2">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-16 rounded-card" />)}</div>
      ) : (q.data?.data.length ?? 0) === 0 ? (
        <MCard><MEmpty icon="check" text={t('alerts.empty')} /></MCard>
      ) : (
        <MList label={t('alerts.title')}>
          {q.data!.data.map((a, i) => (
            <MRow key={a.id ?? `c${i}`} to={alertLink(a, can)} title={a.title} caption={a.body ?? undefined}
              trailing={<Pill tone={SEVERITY_TONE[a.severity]}>{t(`alerts.priority.${a.severity}`)}</Pill>} />
          ))}
        </MList>
      )}
    </section>
  )
}

function DashboardSkeleton() {
  return (
    <div aria-busy="true" className="space-y-6">
      <Skeleton className="h-12 rounded-ctl" />
      <div className="grid grid-cols-4 gap-2">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-[84px] rounded-card" />)}</div>
      <Skeleton className="h-24 rounded-card" />
      <div className="grid grid-cols-2 gap-3">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-24 rounded-card" />)}</div>
    </div>
  )
}
