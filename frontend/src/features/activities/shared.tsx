import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import type { Activity, ActivityType, MoneyStatus, RegistrationStatus } from '../../api/activities'
import type { StudentSummary } from '../../api/students'
import { Badge, SURFACE, type Tone } from '../../components/ui'
import { formatDate, formatMoney, formatTime } from '../../lib/format'

/** A tab of the programs / trips page: its query value, the exact menu entry name, and who may open it. */
export type Tab = 'list' | 'register' | 'students' | 'fee_payment' | 'fee_followup' | 'book_delivery' | 'book_followup'
  | 'attendance' | 'attendance_followup' | 'evaluation' | 'evaluation_view'

// eslint-disable-next-line react-refresh/only-export-components
export const TABS: Record<ActivityType, { tab: Tab; menu: string; perms: string[] }[]> = {
  program: [
    { tab: 'list', menu: 'programs', perms: ['activities.view', 'activities.manage'] },
    { tab: 'register', menu: 'program_registration', perms: ['activities.register'] },
    { tab: 'students', menu: 'program_students', perms: ['activities.view'] },
    { tab: 'fee_payment', menu: 'program_fee_payment', perms: ['payments.record'] },
    { tab: 'fee_followup', menu: 'program_fee_followup', perms: ['wallets.view'] },
    { tab: 'book_delivery', menu: 'program_book_delivery', perms: ['activities.manage'] },
    { tab: 'book_followup', menu: 'program_book_followup', perms: ['activities.view'] },
    { tab: 'attendance', menu: 'program_attendance', perms: ['activities.attendance'] },
    { tab: 'attendance_followup', menu: 'program_attendance_followup', perms: ['activities.view'] },
    { tab: 'evaluation', menu: 'program_evaluation', perms: ['activities.evaluate'] },
    { tab: 'evaluation_view', menu: 'view_program_evaluation', perms: ['activities.view'] },
  ],
  trip: [
    { tab: 'list', menu: 'trips', perms: ['activities.view', 'activities.manage'] },
    { tab: 'register', menu: 'trip_registration', perms: ['activities.register'] },
    { tab: 'attendance', menu: 'trip_attendance', perms: ['activities.attendance'] },
    { tab: 'attendance_followup', menu: 'trip_attendance_followup', perms: ['activities.view'] },
    { tab: 'fee_payment', menu: 'trip_fee_payment', perms: ['payments.record'] },
    { tab: 'fee_followup', menu: 'trip_fee_followup', perms: ['wallets.view'] },
  ],
}

// eslint-disable-next-line react-refresh/only-export-components
export const STATUS_TONE: Record<Activity['status'], Tone> = { draft: 'muted', open: 'brand', closed: 'gold', done: 'info' }
// eslint-disable-next-line react-refresh/only-export-components
export const REG_TONE: Record<RegistrationStatus, Tone> = { registered: 'brand', waitlist: 'gold', cancelled: 'muted' }
// eslint-disable-next-line react-refresh/only-export-components
export const MONEY_TONE: Record<MoneyStatus, Tone> = { paid: 'brand', partial: 'gold', unpaid: 'danger', none: 'muted' }

/** BHD text of an amount in fils, for prefilling the payment dialog. */
// eslint-disable-next-line react-refresh/only-export-components
export const bhd = (fils: number) => (fils / 1000).toFixed(3)

/** Dates and time of an activity on one line. */
export function ActivityWhen({ a }: { a: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  const d = (s: string) => formatDate(s, l, { day: 'numeric', month: 'short', year: 'numeric' })
  return (
    <span className="tabular-nums">
      {a.ends_on && a.ends_on !== a.starts_on ? t('range', { from: d(a.starts_on), to: d(a.ends_on) }) : d(a.starts_on)}
      {a.start_time && a.end_time && <>{t('sep')}{formatTime(a.start_time, l)}–{formatTime(a.end_time, l)}</>}
    </span>
  )
}

/** Name and number of a student, linked to the profile. */
export function StudentCell({ s }: { s: StudentSummary }) {
  return (
    <>
      <Link to={`/students/${s.id}`} className="block font-medium text-ink hover:text-brand-700"><bdi>{s.full_name}</bdi></Link>
      <span className="block text-xs tabular-nums text-ink/50">{s.student_no}</span>
    </>
  )
}

export function Stat({ label, value, danger = false }: { label: string; value: string; danger?: boolean }) {
  return (
    <div className={`${SURFACE} p-4`}>
      <p className="text-sm text-ink/60">{label}</p>
      <p className={`mt-1 text-xl font-semibold tabular-nums ${danger ? 'text-danger' : 'text-ink'}`}>{value}</p>
    </div>
  )
}

/** Paid / partial / unpaid badge of an invoice (or of a student's activity invoices). */
export function MoneyBadge({ status }: { status: MoneyStatus }) {
  const { t } = useTranslation('activities')
  return <Badge tone={MONEY_TONE[status]}>{t(`money.${status}`)}</Badge>
}

/** Amount with the remaining part under it. */
export function Amount({ total, remaining }: { total: number; remaining: number }) {
  const { t, i18n } = useTranslation('activities')
  return (
    <>
      <span className="block tabular-nums">{formatMoney(total, i18n.language)}</span>
      {remaining > 0 && <span className="block text-xs tabular-nums text-danger">{t('money.remaining_of', { amount: formatMoney(remaining, i18n.language) })}</span>}
    </>
  )
}
