import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceApi, type AttendanceStatus, type RosterRow, type SessionInfo } from '../../api/attendance'
import { tokenStore } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { Badge, ErrorState, LoadingState, Modal, Notice, Segmented, buttonClass, type Tone } from '../../components/ui'
import { formatNumber, formatTime } from '../../lib/format'

/**
 * Today's circles for a teacher, shown once per sign-in. The flag holds the id part of the Sanctum
 * token ("<id>|<secret>"), so a refresh or page change keeps the same token and stays quiet, and the
 * next sign-in issues a new token and shows the dialog again.
 */
const SEEN_KEY = 'ahl.teacher_today_seen'
const tokenId = () => tokenStore.get()?.split('|')[0] ?? null

function alreadySeen(): boolean {
  try {
    return localStorage.getItem(SEEN_KEY) === tokenId()
  } catch {
    return false
  }
}

function markSeen() {
  const id = tokenId()
  try {
    if (id) localStorage.setItem(SEEN_KEY, id)
  } catch {
    /* storage unavailable: the dialog simply shows again next time */
  }
}

const bahrain = (opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain', hourCycle: 'h23', ...opts }).format(new Date())
const todayIso = () => bahrain({})
const nowHhmm = () => bahrain({ hour: '2-digit', minute: '2-digit' })

/** The circle running now, else the next one today, else the last one that finished. */
function pickSession(sessions: SessionInfo[]): SessionInfo | undefined {
  const now = nowHhmm()
  return sessions.find((s) => s.start_time <= now && now < s.end_time) ?? sessions.find((s) => s.start_time > now) ?? sessions[sessions.length - 1]
}

const STATUS_TONE: Record<AttendanceStatus, Tone> = { present: 'brand', late: 'gold', absent: 'danger', excused: 'info' }
const STATUS_ICON: Partial<Record<AttendanceStatus, string>> = { present: 'check', late: 'clock', absent: 'close' }

/** Mounted in the staff shell; renders only for users whose staff role is teacher. */
export default function TeacherTodayDialog() {
  const { user, hasRole } = useAuth()
  const isTeacherOnly = hasRole('teacher') && !hasRole('super_admin', 'supervisor')
  const [open, setOpen] = useState(() => isTeacherOnly && !alreadySeen())

  useEffect(() => {
    if (open) markSeen()
  }, [open])

  if (!open || !user) return null
  return <TodayBody name={user.name} onClose={() => setOpen(false)} />
}

function TodayBody({ name, onClose }: { name: string; onClose: () => void }) {
  const { t, i18n } = useTranslation('attendance')
  const { can } = useAuth()
  const locale = i18n.language
  const date = todayIso()
  // Same query key as the attendance day page, so the two share one cache entry.
  const sessionsQ = useQuery({ queryKey: ['sessions-on', date], queryFn: () => attendanceApi.sessionsOn(date) })
  const sessions = (sessionsQ.data ?? []).filter((s) => s.status !== 'cancelled')
  const [picked, setPicked] = useState<number | null>(null)
  const current = sessions.find((s) => s.id === picked) ?? pickSession(sessions)

  const greeting = Number(bahrain({ hour: '2-digit' })) < 12 ? t('briefing.morning', { name }) : t('briefing.evening', { name })

  const footer = (
    <>
      {current && can('attendance.record', 'attendance.view') && (
        <Link to={`/attendance/${current.id}`} onClick={onClose} className={buttonClass('primary')}>
          <Icon name="attendance" className="size-4" />{current.attendance_taken ? t('open_sheet') : t('briefing.take')}
        </Link>
      )}
      {current && can('lessons.view') && (
        <Link to={`/lessons/${current.lesson_id}`} onClick={onClose} className={buttonClass('secondary')}>{t('briefing.open_circle')}</Link>
      )}
      <button type="button" onClick={onClose} className={buttonClass('secondary')}>{t('common:close')}</button>
    </>
  )

  return (
    <Modal title={greeting} onClose={onClose} footer={footer} wide>
      <p className="text-sm text-ink/60">{t('briefing.subtitle')}</p>
      {sessionsQ.isLoading ? <LoadingState /> : sessionsQ.isError ? <ErrorState onRetry={() => void sessionsQ.refetch()} /> : !current ? (
        <Notice tone="info">{t('briefing.no_circles')}</Notice>
      ) : (
        <>
          {sessions.length > 1 && (
            <div className="overflow-x-auto">
              <Segmented name="today-circle" label={t('briefing.switch')} size="sm" value={String(current.id)} onChange={(v) => setPicked(Number(v))}
                options={sessions.map((s) => ({ value: String(s.id), label: `${s.lesson?.name ?? ''} · ${formatTime(s.start_time, locale)}` }))} />
            </div>
          )}
          <SessionDetails key={current.id} session={current} />
        </>
      )}
    </Modal>
  )
}

function SessionDetails({ session }: { session: SessionInfo }) {
  const { t, i18n } = useTranslation('attendance')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const rosterQ = useQuery({ queryKey: ['attendance-sheet', session.id], queryFn: () => attendanceApi.roster(session.id) })
  const roster = rosterQ.data?.roster ?? []
  const count = (s: AttendanceStatus) => roster.filter((r) => r.attendance?.status === s).length
  const notes = rosterQ.data?.session.notes ?? session.notes

  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <Fact label={t('briefing.circle')} value={session.lesson?.name} />
        <Fact label={t('briefing.subject')} value={session.lesson?.package?.name} />
        <Fact label={t('briefing.period')} value={`${formatTime(session.start_time, locale)}–${formatTime(session.end_time, locale)}`} />
        <Fact label={t('briefing.hall')} value={session.location?.name} />
      </dl>

      <section aria-labelledby="today-att" className="space-y-3 border-t border-ink/8 pt-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 id="today-att" className="text-base font-semibold text-ink">{t('briefing.attendance')}</h3>
          {session.attendance_taken ? <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('taken')}</Badge> : <Badge tone="gold">{t('not_taken')}</Badge>}
        </div>
        {rosterQ.isLoading ? <LoadingState /> : rosterQ.isError ? <ErrorState onRetry={() => void rosterQ.refetch()} /> : roster.length === 0 ? (
          <Notice tone="info">{t('empty_roster')}</Notice>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <Stat label={t('briefing.total')} value={n(roster.length)} />
              <Stat label={t('status.present')} value={n(count('present'))} tone="text-brand-700" />
              <Stat label={t('status.absent')} value={n(count('absent'))} tone="text-danger" />
              <Stat label={t('status.late')} value={n(count('late'))} tone="text-gold-700" />
            </div>
            {!session.attendance_taken && <Notice tone="info">{t('briefing.not_taken_hint')}</Notice>}
            <ul className="max-h-64 divide-y divide-ink/6 overflow-y-auto rounded-xl border border-ink/8">
              {roster.map((r) => <RosterLine key={r.student.id} row={r} />)}
            </ul>
          </>
        )}
      </section>

      <section aria-labelledby="today-lesson" className="space-y-3 border-t border-ink/8 pt-4">
        <h3 id="today-lesson" className="text-base font-semibold text-ink">{t('briefing.lesson')}</h3>
        {notes ? <p dir="auto" className="whitespace-pre-line text-sm text-ink/75">{notes}</p> : <p className="text-sm text-ink/55">{t('briefing.no_notes')}</p>}
        <Assignments roster={roster} />
      </section>
    </div>
  )
}

function Fact({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-ink/50">{label}</dt>
      <dd dir="auto" className="truncate font-medium text-ink">{value || '—'}</dd>
    </div>
  )
}

function Stat({ label, value, tone = 'text-ink' }: { label: string; value: string; tone?: string }) {
  return (
    <div className="rounded-xl bg-page/60 px-3 py-2">
      <p className="text-xs text-ink/55">{label}</p>
      <p className={`text-2xl font-semibold tabular-nums ${tone}`}>{value}</p>
    </div>
  )
}

function RosterLine({ row }: { row: RosterRow }) {
  const { t } = useTranslation('attendance')
  const status = row.attendance?.status
  return (
    <li className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
      <span dir="auto" className="min-w-0 truncate text-ink">{row.student.full_name}</span>
      {status ? (
        <Badge tone={STATUS_TONE[status]} className="shrink-0">{STATUS_ICON[status] && <Icon name={STATUS_ICON[status]} className="size-3.5" />}{t(`status.${status}`)}</Badge>
      ) : <Badge className="shrink-0">{t('not_marked')}</Badge>}
    </li>
  )
}

/** Each student's memorization and revision for this session (recorded today, else their current assignment). */
function Assignments({ roster }: { roster: RosterRow[] }) {
  const { t } = useTranslation('attendance')
  const rows = roster
    .map((r) => ({ r, mem: r.attendance?.memorization_assignment ?? r.current_memorization, rev: r.attendance?.revision_assignment ?? r.current_revision }))
    .filter((x) => x.mem || x.rev)
  if (rows.length === 0) return null
  return (
    <div className="space-y-2">
      <h4 className="text-sm font-semibold text-ink/75">{t('briefing.assignments')}</h4>
      <ul className="max-h-64 divide-y divide-ink/6 overflow-y-auto rounded-xl border border-ink/8">
        {rows.map(({ r, mem, rev }) => (
          <li key={r.student.id} className="space-y-0.5 px-3 py-2 text-sm">
            <p dir="auto" className="font-medium text-ink">{r.student.full_name}</p>
            {mem && <p dir="auto" className="text-ink/65">{t('memorization')}: {mem}</p>}
            {rev && <p dir="auto" className="text-ink/65">{t('revision')}: {rev}</p>}
          </li>
        ))}
      </ul>
    </div>
  )
}
