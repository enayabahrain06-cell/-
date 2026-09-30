import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceFollowupApi, type MonitorRow, type MonitorTeacher } from '../../api/attendanceFollowup'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, FilterBar, IconButton, LoadingState, Notice, SURFACE, TextInput } from '../../components/ui'
import { formatDate, formatTime } from '../../lib/format'
import { QueryError, Stat, todayIso } from './shared'

/**
 * مراقبة تسجيل الحضور: sessions of the term in a date range whose attendance is not taken yet, with the night's
 * teachers. A reminder goes to a teacher by WhatsApp through the messaging log; the teacher's own WhatsApp link is
 * there too for a personal message.
 */
export default function AttendanceMonitorPage() {
  const { t } = useTranslation('attendanceFollowup')
  const [from, setFrom] = useState(todayIso())
  const [to, setTo] = useState(todayIso())
  const [levelId, setLevelId] = useState('')
  const [teacherId, setTeacherId] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const q = useQuery({
    queryKey: ['attendance-monitor', from, to, levelId, teacherId],
    queryFn: () => attendanceFollowupApi.monitor({ from, to: to < from ? from : to, level_id: levelId ? Number(levelId) : undefined, teacher_id: teacherId ? Number(teacherId) : undefined }),
    placeholderData: keepPreviousData,
  })
  const remind = useMutation({
    mutationFn: ({ session, teacher }: { session: number; teacher: number }) => attendanceFollowupApi.remind(session, teacher),
    onSuccess: (r) => setNotice({ tone: 'success', text: r.message }),
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.attendance_monitor')} subtitle={t('monitor.subtitle')} />
      </div>
      <FilterBar layout="grid" className="sm:grid-cols-2 xl:grid-cols-4" label={t('monitor.filters')}>
        <TextInput label={t('from')} type="date" value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} />
        <TextInput label={t('to')} type="date" value={to} min={from} onChange={(e) => e.target.value && setTo(e.target.value)} />
        <SelectField label={t('level')} value={levelId} onChange={(e) => setLevelId(e.target.value)}
          options={[{ value: '', label: t('all') }, ...(q.data?.levels ?? []).map((l) => ({ value: String(l.id), label: l.name }))]} />
        <SelectField label={t('teacher')} value={teacherId} onChange={(e) => setTeacherId(e.target.value)}
          options={[{ value: '', label: t('all') }, ...(q.data?.teachers ?? []).map((u) => ({ value: String(u.id), label: u.name }))]} />
      </FilterBar>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {q.isLoading ? <LoadingState /> : q.isError ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : q.data && (
        <>
          <div className="grid grid-cols-1 gap-3 *:min-w-0 sm:grid-cols-3">
            <Stat label={t('monitor.sessions')} value={q.data.counts.sessions} />
            <Stat label={t('monitor.taken')} value={q.data.counts.taken} tone="brand" />
            <Stat label={t('monitor.not_taken')} value={q.data.counts.not_taken} tone={q.data.counts.not_taken ? 'danger' : 'ink'} />
          </div>
          {q.data.data.length === 0 ? <EmptyCard icon="check" title={t('monitor.all_taken')} /> : (
            <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
              {q.data.data.map((s) => (
                <SessionCard key={s.id} s={s} busy={remind.isPending && remind.variables?.session === s.id}
                  onRemind={(u) => { setNotice(null); remind.mutate({ session: s.id, teacher: u.id }) }} />
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}

function SessionCard({ s, onRemind, busy }: { s: MonitorRow; onRemind: (u: MonitorTeacher) => void; busy: boolean }) {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  return (
    <li className={`${SURFACE} space-y-3 p-4 ${s.upcoming ? '' : 'border-danger/25'}`}>
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <Link to={`/attendance/${s.id}`} className="block truncate font-semibold text-ink hover:text-brand-700"><bdi>{s.lesson.name}</bdi></Link>
          <p className="text-sm text-ink/60">{s.level ? <bdi>{s.level.name}</bdi> : '—'}</p>
        </div>
        {s.upcoming ? <Badge tone="info">{t('monitor.upcoming')}</Badge> : <Badge tone="danger">{t('monitor.not_recorded')}</Badge>}
      </div>
      <ul className="space-y-1 text-sm text-ink/70">
        <li className="flex items-center gap-1.5"><Icon name="attendance" className="size-4 shrink-0 text-ink/45" />{formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</li>
        <li className="flex items-center gap-1.5"><Icon name="clock" className="size-4 shrink-0 text-ink/45" /><span className="tabular-nums">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</span></li>
        <li className="flex items-center gap-1.5"><Icon name="pin" className="size-4 shrink-0 text-ink/45" />{s.location ? <bdi>{s.location.name}</bdi> : '—'}</li>
      </ul>
      <div className="space-y-2 border-t border-ink/6 pt-3">
        <p className="text-xs font-medium text-ink/55">{t('monitor.teachers')}</p>
        {s.teachers.length === 0 ? <p className="text-sm text-ink/55">—</p> : s.teachers.map((u) => (
          <div key={u.id} className="flex items-center gap-2">
            <bdi className="min-w-0 flex-1 truncate text-sm text-ink">{u.name}</bdi>
            {u.whatsapp && (
              <a href={u.whatsapp} target="_blank" rel="noreferrer" aria-label={t('monitor.whatsapp', { name: u.name })} title={t('monitor.whatsapp', { name: u.name })}
                className="inline-grid size-9 place-items-center rounded-lg text-ink/55 transition hover:bg-ink/5 hover:text-ink">
                <Icon name="phone" className="size-4" />
              </a>
            )}
            <IconButton icon="bell" label={t('monitor.remind', { name: u.name })} disabled={!u.phone || busy} onClick={() => onRemind(u)} />
          </div>
        ))}
      </div>
    </li>
  )
}
