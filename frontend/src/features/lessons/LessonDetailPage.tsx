import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { lessonsApi, type Conflict } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import { EmptyState, OrnamentDivider } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Modal, Notice, ROW_MAIN, SecondaryButton, SURFACE } from '../../components/ui'
import { formatDate, formatNumber, formatTime } from '../../lib/format'
import AddStudentDialog from './AddStudentDialog'
import ChangeLocationDialog from './ChangeLocationDialog'
import LessonFormDialog from './LessonFormDialog'
import { GENDER_TONE } from './LessonsHomePage'
import LinkedAlbums from '../gallery/LinkedAlbums'
import { MobileLessonDetail, MobileLessonPending } from './MobileLessons'

export default function LessonDetailPage() {
  const { id } = useParams()
  const lessonId = Number(id)
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['lesson', lessonId, locale], queryFn: () => lessonsApi.show(lessonId) })
  const conflicts = useQuery({ queryKey: ['lesson-conflicts', lessonId], queryFn: () => lessonsApi.conflicts(lessonId), enabled: can('lessons.manage') })
  const [edit, setEdit] = useState(false)
  const [change, setChange] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [adding, setAdding] = useState(false)

  const lesson = q.data
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['lesson', lessonId] }); void qc.invalidateQueries({ queryKey: ['lessons'] }) }
  const unenroll = useMutation({ mutationFn: (sid: number) => lessonsApi.unenroll(lessonId, sid), onSuccess: refresh })

  if (q.isLoading) return <><MobileLessonPending /><LoadingState /></>
  if (q.isError || !lesson) return <><MobileLessonPending /><ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} /></>
  const n = (v: number) => formatNumber(v, locale)
  const full = lesson.student_count >= lesson.capacity
  const manage = can('lessons.manage')

  const noticeEl = notice && <Notice tone={notice.tone}>{notice.text}</Notice>
  const conflictsEl = manage && conflicts.data && conflicts.data.length > 0 ? <ConflictsNotice conflicts={conflicts.data} locale={locale} /> : null

  return (
    <>
    <MobileLessonDetail lesson={lesson} manage={manage} canMessage={can('messages.send')} notice={noticeEl} conflicts={conflictsEl} pending={manage && conflicts.isLoading}
      onEdit={() => setEdit(true)} onChangeHall={() => setChange(true)} onAdd={() => setAdding(true)}
      onUnenroll={(sid, name) => window.confirm(t('detail.unenroll_confirm', { name })) && unenroll.mutate(sid)} />
    <div className="hidden space-y-5 lg:block">
      <Link to="/lessons" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('detail.back')}</Link>

      <header className={`${SURFACE} p-4 sm:p-5`}>
        <div className="flex flex-wrap items-start gap-3">
          <div className={ROW_MAIN}>
            <div className="flex flex-wrap items-center gap-2">
              <h1 dir="auto" className="font-display text-3xl text-ink">{lesson.name}</h1>
              {lesson.gender && <Badge tone={GENDER_TONE[lesson.gender]}>{t(`gender.${lesson.gender}`)}</Badge>}
              {lesson.status !== 'active' && <Badge>{t(`status.${lesson.status}`)}</Badge>}
            </div>
            <p dir="auto" className="mt-1 text-sm text-ink/60">{lesson.package?.name} · {lesson.teacher?.name}</p>
          </div>
          {manage && (
            <div className="flex flex-wrap gap-2">
              <SecondaryButton onClick={() => setEdit(true)}><Icon name="edit" className="size-4" />{t('detail.edit')}</SecondaryButton>
              <SecondaryButton onClick={() => setChange(true)}><Icon name="pin" className="size-4" />{t('detail.change_location')}</SecondaryButton>
            </div>
          )}
        </div>
        <OrnamentDivider className="my-3 text-gold-500/70" />
        <dl className="grid gap-3 text-sm sm:grid-cols-4">
          <div><dt className="text-xs text-ink/50">{t('form.days')}</dt><dd className="text-ink">{lesson.days.map((d) => t(`days.${d}`)).join(locale === 'ar' ? '، ' : ', ')}</dd></div>
          <div><dt className="text-xs text-ink/50">{t('form.start_time')}</dt><dd className="tabular-nums text-ink">
            {lesson.schedule && new Set(lesson.schedule.nights.map((x) => x.start + x.end)).size > 1
              ? lesson.schedule.nights.map((x) => <span key={x.weekday} className="block">{t(`days.${x.weekday}`)}: {formatTime(x.start, locale)}–{formatTime(x.end, locale)}</span>)
              : <>{formatTime(lesson.start_time, locale)}–{formatTime(lesson.end_time, locale)}</>}
            {lesson.schedule?.source === 'timetable' && <Link to="/term-setup?tab=timetable" className="mt-0.5 block text-xs text-brand-700 hover:underline">{t('detail.timetable_link')}</Link>}
          </dd></div>
          <div><dt className="text-xs text-ink/50">{t('form.hall')}</dt><dd dir="auto" className="text-ink">{lesson.location?.name ?? t('no_hall')}</dd></div>
          <div><dt className="text-xs text-ink/50">{t('form.capacity')}</dt><dd className="tabular-nums text-ink">{t('students_of', { n: n(lesson.student_count), c: n(lesson.capacity) })}</dd></div>
        </dl>
      </header>

      {noticeEl}
      {conflictsEl}

      <div className="grid gap-5 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <CardTitle actions={lesson.can_add_students && (
            <SecondaryButton onClick={() => setAdding(true)} disabled={full} title={full ? t('detail.full') : undefined}><Icon name="enroll" className="size-4" />{t('detail.add_student')}</SecondaryButton>
          )}>{t('detail.roster')} <span className="text-ink/45">({n(lesson.student_count)})</span></CardTitle>
          {(lesson.students ?? []).length === 0 ? <EmptyState size="sm" icon="students" title={t('detail.empty_roster')} /> : (
            <ul className="divide-y divide-ink/6">
              {lesson.students!.map((ls) => (
                <li key={ls.id} className="flex items-center gap-3 py-2.5">
                  <Avatar name={ls.student.full_name} initial={ls.student.initial} src={ls.student.photo_url} gender={ls.student.gender} size="sm" />
                  <Link to={`/students/${ls.student.id}`} dir="auto" className="min-w-0 flex-1 truncate text-sm font-medium text-ink hover:text-brand-700">{ls.student.full_name}</Link>
                  {manage && <button type="button" className="text-xs text-danger hover:underline" onClick={() => window.confirm(t('detail.unenroll_confirm', { name: ls.student.full_name })) && unenroll.mutate(ls.student.id)}>{t('detail.unenroll')}</button>}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className="lg:col-span-2">
          <CardTitle>{t('detail.upcoming')}</CardTitle>
          {(lesson.next_sessions ?? []).length === 0 ? <EmptyState size="sm" icon="attendance" title={t('detail.no_upcoming')} /> : (
            <ul className="divide-y divide-ink/6 text-sm">
              {lesson.next_sessions!.map((s) => (
                <li key={s.id} className="flex flex-wrap items-center gap-2 py-2">
                  <span className="w-32 text-ink/75">{formatDate(s.session_date, locale, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                  <span dir="auto" className="flex-1 text-ink/60">{s.location?.name ?? t('no_hall')}</span>
                  {s.location_id !== lesson.location_id && <Badge tone="info">{t('detail.hall_changed_on')}</Badge>}
                  {s.status === 'cancelled' && <Badge>{t('status.ended')}</Badge>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

    </div>
    <LinkedAlbums type="lesson" id={lesson.id} className="mt-5" />
      {edit && <LessonFormDialog lesson={lesson} onClose={() => setEdit(false)} onSaved={(_l, c) => { setEdit(false); refresh(); void conflicts.refetch(); setNotice(c.length ? { tone: 'error', text: t('form.conflicts_body') } : { tone: 'success', text: t('form.saved') }) }} />}
      {adding && <AddStudentDialog lesson={lesson} canQuickEnroll={can('enrollment.quick')} onClose={() => setAdding(false)} onChanged={refresh} />}
      {change && <ChangeLocationDialog lesson={lesson} onClose={() => setChange(false)} onDone={(m) => { setChange(false); setNotice({ tone: 'success', text: m }) }} />}
    </>
  )
}

/** How many conflicts the notice lists before "Show all". */
const CONFLICT_PREVIEW = 3

/** Hall conflicts as a count, the first few, and the full list in a dialog (a long run-on sentence was unreadable). */
function ConflictsNotice({ conflicts, locale }: { conflicts: Conflict[]; locale: string }) {
  const { t } = useTranslation('lessons')
  const [open, setOpen] = useState(false)
  const n = (v: number) => formatNumber(v, locale)
  const when = (c: Conflict) =>
    `${c.date ? `${formatDate(c.date, locale, { day: 'numeric', month: 'short' })} · ` : ''}${formatTime(c.start_time, locale)}–${formatTime(c.end_time, locale)}`
  const more = conflicts.length - CONFLICT_PREVIEW
  const item = (c: Conflict, i: number) => (
    <li key={`${c.kind}-${c.id}-${i}`} className="flex flex-wrap gap-x-2">
      <span dir="auto" className="font-medium">{c.title}</span>
      <span className="tabular-nums opacity-80">{when(c)}</span>
    </li>
  )

  return (
    <>
      <Notice tone="error">
        <p className="font-semibold">{t('detail.conflicts')} <span className="tabular-nums">({n(conflicts.length)})</span></p>
        <ul className="mt-1 space-y-0.5">{conflicts.slice(0, CONFLICT_PREVIEW).map(item)}</ul>
        {more > 0 && (
          <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>{t('detail.conflicts_more', { n: n(more) })}</span>
            <button type="button" aria-haspopup="dialog" onClick={() => setOpen(true)} className="font-semibold underline underline-offset-2 hover:no-underline">
              {t('detail.conflicts_show_all', { n: n(conflicts.length) })}
            </button>
          </p>
        )}
      </Notice>
      {open && (
        <Modal title={`${t('detail.conflicts')} (${n(conflicts.length)})`} onClose={() => setOpen(false)} wide>
          <ul className="max-h-[60vh] divide-y divide-ink/6 overflow-y-auto text-sm text-ink">
            {conflicts.map((c, i) => (
              <li key={`${c.kind}-${c.id}-${i}`} className="flex flex-wrap justify-between gap-x-4 gap-y-0.5 py-2">
                <span dir="auto" className="font-medium">{c.title}</span>
                <span className="tabular-nums text-ink/60">{when(c)}</span>
              </li>
            ))}
          </ul>
        </Modal>
      )}
    </>
  )
}
