import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceApi, type AttendanceStatus, type ProgressEntry, type RosterRow, type SessionInfo } from '../../api/attendance'
import Icon from '../../components/Icon'
import QuranRangePicker from '../../components/QuranRangePicker'
import { TextInput } from '../../components/ui'
import BottomSheet from '../../components/mobile/BottomSheet'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import { MobilePage } from '../../components/mobile/MobileChrome'
import { MAvatar, MCard, MEmpty, MListSkeleton, Pill, M_BTN_PRIMARY, M_BTN_SECONDARY, M_CARD } from '../../components/mobile/atoms'
import { formatDate, formatHijri, formatNumber, formatTime } from '../../lib/format'

/** Attendance below lg (mobile-redesign-spec.md §6.11). State and mutations stay in the page components. */

const STATE_STYLE: Record<AttendanceStatus, string> = {
  present: 'bg-brand-50 text-chart-present ring-chart-present/40',
  late: 'bg-gold-500/12 text-gold-700 ring-chart-late/40',
  absent: 'bg-danger/10 text-chart-absent ring-chart-absent/40',
  excused: 'bg-info/10 text-chart-excused ring-chart-excused/40',
}
const DOT: Record<AttendanceStatus | 'none', string> = { present: 'bg-chart-present', late: 'bg-chart-late', absent: 'bg-chart-absent', excused: 'bg-chart-excused', none: 'bg-ink/25' }
const ORDER: AttendanceStatus[] = ['present', 'late', 'absent', 'excused']

type Draft = { student_id: number; status: AttendanceStatus | null; memorization_assignment?: string | null; revision_assignment?: string | null; note?: string | null; progress: ProgressEntry[] }

/** /attendance (root tab) and the evaluation "daily" tab: date stepper + the day's sessions. */
export function MobileDayView({ date, sessions, loading, basePath, embedded, onShift, onDate, isToday, onToday }: {
  date: string; sessions: SessionInfo[] | undefined; loading: boolean; basePath: string; embedded: boolean
  onShift: (days: number) => void; onDate: (v: string) => void; isToday: boolean; onToday: () => void
}) {
  const { t, i18n } = useTranslation('attendance')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const step = 'inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700'

  return (
    <div className="space-y-4 lg:hidden">
      {!embedded && <h1 className="font-display text-[22px] leading-7 text-brand-900">{t('title')}</h1>}
      <div className="flex items-center gap-2">
        <button type="button" onClick={() => onShift(-1)} aria-label={t('mobile.prev_day')} className={step}><Icon name="chevron" className="size-5 ltr:rotate-180" /></button>
        <label className="min-w-0 flex-1">
          <span className="sr-only">{t('date')}</span>
          <input type="date" value={date} onChange={(e) => e.target.value && onDate(e.target.value)}
            className="h-11 w-full rounded-ctl border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
        </label>
        <button type="button" onClick={() => onShift(1)} aria-label={t('mobile.next_day')} className={step}><Icon name="chevron" className="size-5 rtl:rotate-180" /></button>
      </div>
      <div className="flex items-center justify-between gap-3">
        <p className="min-w-0 truncate text-[13px] text-ink/65">{formatDate(date, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · {formatHijri(date, locale)}</p>
        {!isToday && <button type="button" onClick={onToday} className="-me-2 min-h-11 shrink-0 px-2 text-[13px] font-semibold text-info">{t('today')}</button>}
      </div>

      {loading ? <MListSkeleton rows={3} /> : !sessions || sessions.length === 0 ? (
        <MCard><MEmpty icon="attendance" text={t('no_sessions')} /></MCard>
      ) : (
        <ul className="space-y-3">
          {sessions.map((s) => (
            <li key={s.id}>
              <Link to={`${basePath}/${s.id}`} className={`${M_CARD} block p-4`}>
                <div className="flex items-start justify-between gap-3">
                  <p className="min-w-0 truncate text-[15px] font-semibold text-ink"><bdi>{s.lesson?.name}</bdi></p>
                  {s.status === 'cancelled' ? <Pill>{t('cancelled')}</Pill> : s.attendance_taken ? <Pill tone="ok">{t('taken')}</Pill> : <Pill tone="warn">{t('not_taken')}</Pill>}
                </div>
                <p className="mt-1 truncate text-[13px] text-ink/65">
                  <span className="tabular-nums">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</span>
                  {s.location && <> · <span><bdi>{s.location.name}</bdi></span></>}
                  {s.lesson?.teacher && <> · <span><bdi>{s.lesson.teacher.name}</bdi></span></>}
                </p>
                {(s.is_location_override || (s.attendance_summary && s.attendance_taken)) && (
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    {s.is_location_override && <Pill tone="info">{t('hall_changed')}</Pill>}
                    {s.attendance_summary && s.attendance_taken && <span className="text-[13px] tabular-nums text-ink/65">{t('present_absent', { present: n(s.attendance_summary.present), absent: n(s.attendance_summary.absent) })}</span>}
                  </div>
                )}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** /attendance/:id: circle | date selectors, live tiles, one row per student with four state buttons, sticky save. */
export function MobileSheetView({ session, roster, drafts, marked, dirty, saving, markingAll, onStatus, onPatch, onSave, onMarkAll }: {
  session: SessionInfo; roster: RosterRow[]; drafts: Record<number, Draft>; marked: number; dirty: boolean; saving: boolean; markingAll: boolean
  onStatus: (studentId: number, s: AttendanceStatus) => void; onPatch: (studentId: number, patch: Partial<Draft>) => void; onSave: () => void; onMarkAll: () => void
}) {
  const { t, i18n } = useTranslation('attendance')
  const locale = i18n.language
  const navigate = useNavigate()
  const n = (v: number) => formatNumber(v, locale)
  const [editing, setEditing] = useState<RosterRow | null>(null)
  const date = session.session_date
  const sameDay = useQuery({ queryKey: ['sessions-on', date], queryFn: () => attendanceApi.sessionsOn(date) })

  const all = Object.values(drafts)
  const counts = { ...Object.fromEntries(ORDER.map((s) => [s, all.filter((d) => d.status === s).length])), none: roster.length - marked } as Record<AttendanceStatus | 'none', number>

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage
        title={session.lesson?.name ?? t('sheet_title')}
        back={`/attendance?date=${date}`}
        breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: `/attendance?date=${date}` }, { label: session.lesson?.name ?? t('sheet_title') }]}
      />
      <p className="text-[13px] text-ink/65">
        <span className="tabular-nums">{formatTime(session.start_time, locale)}–{formatTime(session.end_time, locale)}</span>
        {session.location && <> · <span><bdi>{session.location.name}</bdi></span></>}
        {session.lesson?.teacher && <> · <span><bdi>{session.lesson.teacher.name}</bdi></span></>}
      </p>

      <div className="grid grid-cols-2 gap-2.5">
        <label className="min-w-0">
          <span className="mb-1.5 block text-xs font-semibold text-ink/65">{t('mobile.circle')}</span>
          <select value={session.id} onChange={(e) => navigate(`/attendance/${e.target.value}`)}
            className="h-12 w-full truncate rounded-md border border-ink/10 bg-white px-3 text-[15px] text-ink">
            {(sameDay.data ?? [session]).map((s) => <option key={s.id} value={s.id}>{s.lesson?.name}</option>)}
          </select>
        </label>
        <label className="min-w-0">
          <span className="mb-1.5 block text-xs font-semibold text-ink/65">{t('date')}</span>
          <input type="date" value={date} onChange={(e) => e.target.value && navigate(`/attendance?date=${e.target.value}`)}
            className="h-12 w-full rounded-md border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
        </label>
      </div>

      <ul aria-live="polite" className="grid grid-cols-5 gap-1.5">
        {([...ORDER, 'none'] as const).map((s) => (
          <li key={s} className="flex min-w-0 flex-col items-center gap-0.5 rounded-card border border-ink/10 bg-white px-0.5 py-2 text-center">
            <span className="flex items-center gap-1 text-lg font-semibold leading-6 tabular-nums text-ink">
              <span aria-hidden className={`size-1.5 shrink-0 rounded-full ${DOT[s]}`} />
              {n(counts[s])}
            </span>
            <span className="line-clamp-2 max-w-full text-xs leading-4 text-ink/65">{s === 'none' ? t('not_marked') : t(`status.${s}`)}</span>
          </li>
        ))}
      </ul>

      <div className="flex items-center justify-between gap-3">
        <p className="min-w-0 text-[13px] text-ink/65">{t('mobile.helper')}</p>
        <button type="button" disabled={markingAll || roster.length === 0} onClick={() => { if (window.confirm(t('mark_all_confirm'))) onMarkAll() }}
          className="relative inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border border-ink/10 bg-white px-3 text-[13px] font-semibold text-brand-700 before:absolute before:-inset-y-1.5 before:inset-x-0 disabled:opacity-60">
          <Icon name="check" className="size-4" />{t('mobile.all_present')}
        </button>
      </div>

      {roster.length === 0 ? (
        <MCard><MEmpty icon="students" text={t('empty_roster')} /></MCard>
      ) : (
        <ul aria-label={t('students')} className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
          {roster.map((r) => {
            const d = drafts[r.student.id]
            if (!d) return null
            return <SheetRow key={r.student.id} r={r} d={d} onStatus={(s) => onStatus(r.student.id, s)} onDetails={() => setEditing(r)} />
          })}
        </ul>
      )}
      <p className="text-center text-xs text-ink/65">{t('mobile.hold_hint')}</p>

      <StickyActionBar>
        <button type="button" onClick={onSave} disabled={marked === 0 || saving} className={`${M_BTN_PRIMARY} flex-1`}>
          {saving ? t('saving') : t('mobile.save_n', { marked: n(marked), total: n(roster.length) })}
          {dirty && !saving && <span aria-label={t('unsaved')} className="size-2 rounded-full bg-gold-400" />}
        </button>
      </StickyActionBar>

      {editing && drafts[editing.student.id] && (
        <DetailsSheet row={editing} d={drafts[editing.student.id]} onPatch={(p) => onPatch(editing.student.id, p)} onClose={() => setEditing(null)} />
      )}
    </div>
  )
}

function SheetRow({ r, d, onStatus, onDetails }: { r: RosterRow; d: Draft; onStatus: (s: AttendanceStatus) => void; onDetails: () => void }) {
  const { t } = useTranslation('attendance')
  const hold = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const start = () => { hold.current = setTimeout(onDetails, 550) }
  const cancel = () => clearTimeout(hold.current)
  const filled = [d.memorization_assignment, d.revision_assignment, d.note].filter(Boolean).length + d.progress.length

  return (
    <li className="px-4 py-3" onPointerDown={start} onPointerUp={cancel} onPointerLeave={cancel} onPointerCancel={cancel} onContextMenu={(e) => { e.preventDefault(); onDetails() }}>
      <div className="flex items-center gap-3">
        <MAvatar name={r.student.full_name} src={r.student.photo_url} size={36} />
        <div className="min-w-0 flex-1">
          <Link to={`/students/${r.student.id}`} className="block truncate text-[15px] font-semibold text-ink"><bdi>{r.student.full_name}</bdi></Link>
          <p className="truncate text-[13px] text-ink/65">
            {r.current_memorization ? t('current', { text: r.current_memorization }) : r.student.progress.surah_name ?? ''}
            {r.attendance?.absence_notified_at && <span className="ms-2 text-info">{t('absence_sent')}</span>}
          </p>
        </div>
        <button type="button" onClick={onDetails} aria-label={t('mobile.details')} title={t('mobile.details')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl text-ink/65">
          <Icon name="edit" className="size-5" />
          {filled > 0 && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-brand-700" />}
        </button>
      </div>
      <div role="group" aria-label={r.student.full_name} className="mt-2 grid grid-cols-4 gap-1.5">
        {ORDER.map((s) => {
          const on = d.status === s
          return (
            <button key={s} type="button" aria-pressed={on} onClick={() => onStatus(s)}
              className={`h-10 min-w-11 rounded-ctl text-[13px] font-semibold transition ${on ? `${STATE_STYLE[s]} ring-1` : 'bg-ink/5 text-ink/65'}`}>
              {t(`status.${s}`)}
            </button>
          )
        })}
      </div>
    </li>
  )
}

/** Tap-and-hold (or the edit button): assignments, note and today's memorized ranges in a bottom sheet. */
function DetailsSheet({ row, d, onPatch, onClose }: { row: RosterRow; d: Draft; onPatch: (p: Partial<Draft>) => void; onClose: () => void }) {
  const { t } = useTranslation('attendance')
  const id = row.student.id
  return (
    <BottomSheet open title={t('mobile.details_title', { name: row.student.full_name })} onClose={onClose}
      footer={<button type="button" onClick={onClose} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>}>
      <div className="space-y-4">
        <TextInput label={t('memorization')} value={d.memorization_assignment ?? ''} onChange={(e) => onPatch({ memorization_assignment: e.target.value })} placeholder={row.current_memorization ?? ''} dir="auto" />
        <TextInput label={t('revision')} value={d.revision_assignment ?? ''} onChange={(e) => onPatch({ revision_assignment: e.target.value })} placeholder={row.current_revision ?? ''} dir="auto" />
        <TextInput label={t('note')} value={d.note ?? ''} onChange={(e) => onPatch({ note: e.target.value })} dir="auto" />
        {(d.status === 'present' || d.status === 'late') && (
          <div className="space-y-2">
            <p className="text-[13px] font-medium text-ink/75">{t('ledger')}</p>
            {d.progress.map((p, i) => (
              <QuranRangePicker key={i} idPrefix={`mp-${id}-${i}`} value={p}
                onChange={(v) => onPatch({ progress: d.progress.map((x, j) => (j === i ? v : x)) })}
                onRemove={() => onPatch({ progress: d.progress.filter((_, j) => j !== i) })} />
            ))}
            {d.progress.length < 4 && (
              <button type="button" className="min-h-11 text-[15px] font-semibold text-info"
                onClick={() => onPatch({ progress: [...d.progress, { type: 'memorized', surah_number: row.student.progress.surah ?? 114, from_ayah: 1, to_ayah: 1 }] })}>
                + {t('add_range')}
              </button>
            )}
          </div>
        )}
      </div>
    </BottomSheet>
  )
}
