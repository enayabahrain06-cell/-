import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceApi, type AttendanceRecord, type AttendanceStatus, type ProgressEntry, type SaveResult } from '../../api/attendance'
import { parseApiError } from '../../api/client'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import QuranRangePicker from '../../components/QuranRangePicker'
import { EmptyState, OrnamentDivider } from '../../components/ornaments'
import { Badge, ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, TextInput, type Tone, SURFACE } from '../../components/ui'
import { formatDate, formatNumber, formatTime } from '../../lib/format'

const STATUSES: { value: AttendanceStatus; tone: Tone }[] = [
  { value: 'present', tone: 'brand' },
  { value: 'late', tone: 'gold' },
  { value: 'absent', tone: 'danger' },
  { value: 'excused', tone: 'info' },
]

type Draft = Omit<AttendanceRecord, 'status'> & { status: AttendanceStatus | null; progress: ProgressEntry[] }

export default function AttendanceSheetPage() {
  const { sessionId } = useParams()
  const id = Number(sessionId)
  const { t, i18n } = useTranslation('attendance')
  const locale = i18n.language
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['attendance-sheet', id], queryFn: () => attendanceApi.roster(id) })

  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [dirty, setDirty] = useState(false)
  const [result, setResult] = useState<SaveResult | null>(null)
  const [error, setError] = useState<string | null>(null)

  // Seed drafts from the server roster.
  useEffect(() => {
    if (!q.data) return
    const next: Record<number, Draft> = {}
    for (const r of q.data.roster) {
      next[r.student.id] = {
        student_id: r.student.id,
        status: r.attendance?.status ?? null,
        memorization_assignment: r.attendance?.memorization_assignment ?? '',
        revision_assignment: r.attendance?.revision_assignment ?? '',
        note: r.attendance?.note ?? '',
        progress: [],
      }
    }
    setDrafts(next)
    setDirty(false)
  }, [q.data])

  // Warn before leaving with unsaved changes.
  useEffect(() => {
    if (!dirty) return
    const h = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = '' }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [dirty])

  const update = (studentId: number, patch: Partial<Draft>) => {
    setDrafts((d) => ({ ...d, [studentId]: { ...d[studentId], ...patch } }))
    setDirty(true)
    setResult(null)
  }

  const onDone = (r: SaveResult) => {
    setResult(r)
    setError(null)
    void qc.invalidateQueries({ queryKey: ['attendance-sheet', id] })
    void qc.invalidateQueries({ queryKey: ['sessions-on'] })
    void qc.invalidateQueries({ queryKey: ['dashboard'] })
  }

  const save = useMutation({
    mutationFn: () => attendanceApi.save(id, Object.values(drafts).filter((d) => d.status).map((d) => ({
      student_id: d.student_id,
      status: d.status as AttendanceStatus,
      memorization_assignment: d.memorization_assignment || null,
      revision_assignment: d.revision_assignment || null,
      note: d.note || null,
      ...(d.progress.length ? { progress: d.progress } : {}),
    }))),
    onSuccess: onDone,
    onError: (e) => setError(parseApiError(e).message),
  })
  const markAll = useMutation({ mutationFn: () => attendanceApi.markAllPresent(id), onSuccess: onDone, onError: (e) => setError(parseApiError(e).message) })

  const marked = useMemo(() => Object.values(drafts).filter((d) => d.status).length, [drafts])

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />

  const s = q.data.session
  const roster = q.data.roster
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-5 pb-24">
      <Link to={`/attendance?date=${s.session_date}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <Icon name="chevron" className="size-4 ltr:rotate-180" />{t('back')}
      </Link>

      <header className={`${SURFACE} p-4 sm:p-5`}>
        <p className="text-sm text-ink/55">{t('sheet_title')}</p>
        <h1 dir="auto" className="font-display text-3xl text-ink">{s.lesson?.name}</h1>
        <OrnamentDivider className="my-2 text-gold-500/70" />
        <p className="text-sm text-ink/65">
          {formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · {formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}
          {s.location && <> · <span dir="auto">{s.location.name}</span></>}
          {s.lesson?.teacher && <> · <span dir="auto">{s.lesson.teacher.name}</span></>}
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {s.is_location_override && <Badge tone="info"><Icon name="pin" className="size-3.5" />{t('hall_changed')}</Badge>}
          {s.attendance_taken ? <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('taken')}</Badge> : <Badge tone="gold">{t('not_taken')}</Badge>}
          <span className="text-sm text-ink/55">{t('counts', { marked: n(marked), total: n(roster.length) })}</span>
          <SecondaryButton className="ms-auto" disabled={markAll.isPending || roster.length === 0} onClick={() => { if (window.confirm(t('mark_all_confirm'))) markAll.mutate() }}>
            <Icon name="check" className="size-4" />{t('mark_all_present')}
          </SecondaryButton>
        </div>
      </header>

      {error && <Notice tone="error">{error}</Notice>}
      {result && (
        <Notice>
          {t('saved', { saved: n(result.saved) })}
          {result.absent > 0 && <> {t('absence_queued', { n: n(result.absent) })}</>}
          {result.repeated_absence_alerts.length > 0 && <> {t('repeated_alerts', { n: n(result.repeated_absence_alerts.length) })}</>}
        </Notice>
      )}

      {roster.length === 0 ? (
        <div className={SURFACE}><EmptyState icon="students" title={t('empty_roster')} /></div>
      ) : (
        <ul className="space-y-3">
          {roster.map((r) => {
            const d = drafts[r.student.id]
            if (!d) return null
            return (
              <li key={r.student.id} className={`rounded-2xl border bg-white p-4 shadow-sm ${d.status === 'absent' ? 'border-danger/30' : 'border-ink/8'}`}>
                <div className="flex flex-wrap items-center gap-3">
                  <Avatar name={r.student.full_name} initial={r.student.initial} src={r.student.photo_url} gender={r.student.gender} size="sm" />
                  <div className="min-w-0 flex-1">
                    <Link to={`/students/${r.student.id}`} dir="auto" className="block truncate font-semibold text-ink hover:text-brand-700">{r.student.full_name}</Link>
                    <p className="truncate text-xs text-ink/50">
                      {r.current_memorization ? t('current', { text: r.current_memorization }) : r.student.progress.surah_name ?? ''}
                    </p>
                  </div>
                  <Segmented name={`status-${r.student.id}`} label={r.student.full_name} value={d.status}
                    options={STATUSES.map((o) => ({ value: o.value, label: t(`status.${o.value}`), tone: o.tone }))}
                    onChange={(v) => update(r.student.id, { status: v })} />
                </div>

                {d.status && d.status !== 'excused' && (
                  <div className="mt-3 grid gap-3 border-t border-ink/6 pt-3 sm:grid-cols-3">
                    <TextInput label={t('memorization')} value={d.memorization_assignment ?? ''} onChange={(e) => update(r.student.id, { memorization_assignment: e.target.value })} placeholder={r.current_memorization ?? ''} dir="auto" />
                    <TextInput label={t('revision')} value={d.revision_assignment ?? ''} onChange={(e) => update(r.student.id, { revision_assignment: e.target.value })} placeholder={r.current_revision ?? ''} dir="auto" />
                    <TextInput label={t('note')} value={d.note ?? ''} onChange={(e) => update(r.student.id, { note: e.target.value })} dir="auto" />
                    {(d.status === 'present' || d.status === 'late') && (
                      <div className="space-y-2 sm:col-span-3">
                        <p className="text-sm font-medium text-ink/75">{t('ledger')}</p>
                        {d.progress.map((p, i) => (
                          <QuranRangePicker key={i} idPrefix={`p-${r.student.id}-${i}`} value={p}
                            onChange={(v) => update(r.student.id, { progress: d.progress.map((x, j) => (j === i ? v : x)) })}
                            onRemove={() => update(r.student.id, { progress: d.progress.filter((_, j) => j !== i) })} />
                        ))}
                        {d.progress.length < 4 && (
                          <button type="button" className="text-sm font-medium text-brand-700 hover:underline"
                            onClick={() => update(r.student.id, { progress: [...d.progress, { type: 'memorized', surah_number: r.student.progress.surah ?? 114, from_ayah: 1, to_ayah: 1 }] })}>
                            + {t('add_range')}
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                )}
                {r.attendance?.absence_notified_at && <p className="mt-2 text-xs text-ink/50"><Icon name="messages" className="me-1 inline size-3.5" />{t('absence_sent')}</p>}
              </li>
            )
          })}
        </ul>
      )}

      {/* Sticky save bar */}
      <div className="fixed inset-x-0 bottom-0 z-20 border-t border-ink/8 bg-white/95 px-4 py-3 backdrop-blur sm:px-6 lg:start-[17rem] lg:px-8">
        <div className="flex items-center gap-3">
          {dirty && <span className="text-sm text-gold-700">{t('unsaved')}</span>}
          <PrimaryButton className="ms-auto min-w-40" loading={save.isPending} disabled={marked === 0} onClick={() => save.mutate()}>
            {save.isPending ? t('saving') : t('save')}
          </PrimaryButton>
        </div>
      </div>
    </div>
  )
}
