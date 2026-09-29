import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceApi, type AttendanceRecord, type AttendanceStatus, type ProgressEntry, type SaveResult } from '../../api/attendance'
import { parseApiError } from '../../api/client'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import QuranRangePicker from '../../components/QuranRangePicker'
import { OrnamentDivider } from '../../components/ornaments'
import { Badge, Card, ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, TextInput, type Tone, SURFACE, EmptyCard, ROW_MAIN } from '../../components/ui'
import { formatDate, formatNumber, formatTime } from '../../lib/format'

const STATUSES: { value: AttendanceStatus; tone: Tone }[] = [
  { value: 'present', tone: 'brand' },
  { value: 'late', tone: 'gold' },
  { value: 'absent', tone: 'danger' },
  { value: 'excused', tone: 'info' },
]

/** Solid fill per status tone, for the tally bar and the legend dots. */
const BAR: Record<Tone, string> = { brand: 'bg-brand-600', gold: 'bg-gold-500', danger: 'bg-danger', info: 'bg-info', muted: 'bg-ink/25' }

type Draft =Omit<AttendanceRecord, 'status'> & { status: AttendanceStatus | null; progress: ProgressEntry[] }

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
  // Details panel per row: an explicit toggle wins, otherwise the row follows "show all details".
  const [expanded, setExpanded] = useState<Record<number, boolean>>({})
  const [showAll, setShowAll] = useState(false)

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
  const all = Object.values(drafts)
  const tally = STATUSES.map((o) => ({ ...o, count: all.filter((d) => d.status === o.value).length }))
  const unmarked = roster.length - marked
  const pct = (v: number) => (roster.length ? (v / roster.length) * 100 : 0)

  return (
    <div className="space-y-5 pb-24">
      <Link to={`/attendance?date=${s.session_date}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <Icon name="chevron" className="size-4 ltr:rotate-180" />{t('back')}
      </Link>

      <header className="flex flex-wrap items-start gap-x-6 gap-y-3">
        <div className="min-w-0 flex-[1_1_20rem]">
          <p className="text-sm text-ink/55">{t('sheet_title')}</p>
          <h1 dir="auto" className="font-display text-3xl text-ink">{s.lesson?.name}</h1>
          <OrnamentDivider className="my-2 max-w-60 text-gold-500/70" />
          <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-ink/65">
            <li className="inline-flex items-center gap-1.5">
              <Icon name="attendance" className="size-4 text-ink/45" />
              {formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}
            </li>
            <li className="inline-flex items-center gap-1.5">
              <Icon name="clock" className="size-4 text-ink/45" />
              <span className="tabular-nums">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</span>
            </li>
            {s.location && (
              <li className="inline-flex items-center gap-1.5"><Icon name="pin" className="size-4 text-ink/45" /><span dir="auto">{s.location.name}</span></li>
            )}
            {s.lesson?.teacher && (
              <li className="inline-flex items-center gap-1.5"><Icon name="teachers" className="size-4 text-ink/45" /><span dir="auto">{s.lesson.teacher.name}</span></li>
            )}
          </ul>
        </div>
        <div className="flex flex-wrap items-center gap-2 sm:ms-auto">
          {s.is_location_override && <Badge tone="info"><Icon name="pin" className="size-3.5" />{t('hall_changed')}</Badge>}
          {s.attendance_taken ? <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('taken')}</Badge> : <Badge tone="gold">{t('not_taken')}</Badge>}
        </div>
      </header>

      {/* Live tally of the draft: one stacked bar plus a count per status. */}
      <Card aria-labelledby="tally-title">
        <div className="flex flex-wrap items-center gap-3">
          <h2 id="tally-title" className="text-base font-semibold tabular-nums text-ink">{t('counts', { marked: n(marked), total: n(roster.length) })}</h2>
          <SecondaryButton className="ms-auto" disabled={markAll.isPending || roster.length === 0} onClick={() => { if (window.confirm(t('mark_all_confirm'))) markAll.mutate() }}>
            <Icon name="check" className="size-4" />{t('mark_all_present')}
          </SecondaryButton>
        </div>
        <div className="mt-3 flex h-2.5 overflow-hidden rounded-full bg-ink/6" aria-hidden="true">
          {tally.map((o) => o.count > 0 && <span key={o.value} className={`${BAR[o.tone]} transition-[width]`} style={{ width: `${pct(o.count)}%` }} />)}
        </div>
        <ul className="mt-3 flex flex-wrap gap-2">
          {tally.map((o) => (
            <li key={o.value}>
              <Badge tone={o.tone}><span className={`size-2 rounded-full ${BAR[o.tone]}`} />{t(`status.${o.value}`)} <span className="tabular-nums">{n(o.count)}</span></Badge>
            </li>
          ))}
          <li><Badge><span className="size-2 rounded-full bg-ink/25" />{t('not_marked')} <span className="tabular-nums">{n(unmarked)}</span></Badge></li>
        </ul>
      </Card>

      {error && <Notice tone="error">{error}</Notice>}
      {result && (
        <Notice>
          {t('saved', { saved: n(result.saved) })}
          {result.absent > 0 && <> {t('absence_queued', { n: n(result.absent) })}</>}
          {result.repeated_absence_alerts.length > 0 && <> {t('repeated_alerts', { n: n(result.repeated_absence_alerts.length) })}</>}
        </Notice>
      )}

      {roster.length === 0 ? (
        <EmptyCard icon="students" title={t('empty_roster')} />
      ) : (
        <section aria-labelledby="roster-title" className={SURFACE}>
          <div className="flex flex-wrap items-center gap-2 border-b border-ink/6 px-4 py-3 sm:px-5">
            <h2 id="roster-title" className="text-base font-semibold text-ink">
              {t('students')} <span className="ms-1 text-sm font-normal text-ink/45">({n(roster.length)})</span>
            </h2>
            <button type="button" aria-pressed={showAll} className="ms-auto text-sm font-medium text-brand-700 hover:underline"
              onClick={() => { setShowAll((v) => !v); setExpanded({}) }}>
              {showAll ? t('hide_all_details') : t('show_all_details')}
            </button>
          </div>
          <ul className="divide-y divide-ink/6">
            {roster.map((r) => {
              const d = drafts[r.student.id]
              if (!d) return null
              const canDetail = !!d.status && d.status !== 'excused'
              const open = canDetail && (expanded[r.student.id] ?? showAll)
              const filled = [d.memorization_assignment, d.revision_assignment, d.note].filter(Boolean).length + d.progress.length
              const panel = `details-${r.student.id}`
              const toggleLabel = open ? t('hide_details') : t('show_details')
              return (
                <li key={r.student.id} className={`px-4 py-3 sm:px-5 ${d.status === 'absent' ? 'bg-danger/5 shadow-[inset_3px_0_0_var(--color-danger)] rtl:shadow-[inset_-3px_0_0_var(--color-danger)]' : ''}`}>
                  <div className="flex flex-wrap items-center gap-3">
                    <Avatar name={r.student.full_name} initial={r.student.initial} src={r.student.photo_url} gender={r.student.gender} size="sm" />
                    <div className={ROW_MAIN}>
                      <Link to={`/students/${r.student.id}`} dir="auto" className="block truncate font-semibold text-ink hover:text-brand-700">{r.student.full_name}</Link>
                      <p className="truncate text-xs text-ink/50">
                        {r.current_memorization ? t('current', { text: r.current_memorization }) : r.student.progress.surah_name ?? ''}
                        {r.attendance?.absence_notified_at && (
                          <span className="ms-2 inline-flex items-center gap-1 text-info-700"><Icon name="messages" className="size-3.5" />{t('absence_sent')}</span>
                        )}
                      </p>
                    </div>
                    <div className="flex w-full items-center gap-2 sm:w-auto">
                      <Segmented fill name={`status-${r.student.id}`} label={r.student.full_name} value={d.status}
                        options={STATUSES.map((o) => ({ value: o.value, label: t(`status.${o.value}`), tone: o.tone }))}
                        onChange={(v) => update(r.student.id, { status: v })} />
                      <button type="button" disabled={!canDetail} aria-expanded={open} aria-controls={panel} aria-label={toggleLabel} title={toggleLabel}
                        onClick={() => setExpanded((e) => ({ ...e, [r.student.id]: !open }))}
                        className="relative inline-grid size-10 shrink-0 place-items-center rounded-lg text-ink/55 transition hover:bg-ink/5 hover:text-ink disabled:cursor-not-allowed disabled:opacity-30">
                        <Icon name="edit" className="size-4" />
                        {filled > 0 && <span className="absolute end-1.5 top-1.5 size-2 rounded-full bg-brand-600" />}
                      </button>
                    </div>
                  </div>

                  {open && (
                    <div id={panel} className="mt-3 grid gap-3 rounded-xl bg-page/70 p-3 sm:grid-cols-3">
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
                </li>
              )
            })}
          </ul>
        </section>
      )}

      {/* Sticky save bar */}
      <div className="fixed inset-x-0 bottom-0 z-20 border-t border-ink/10 bg-white/95 px-4 py-3 shadow-lg backdrop-blur sm:px-6 lg:start-[17rem] lg:px-8">
        <div className="mx-auto flex w-full max-w-page flex-wrap items-center gap-x-4 gap-y-1">
          <span className="text-sm tabular-nums text-ink/60">{t('counts', { marked: n(marked), total: n(roster.length) })}</span>
          {dirty && <span className="inline-flex items-center gap-1.5 text-sm text-gold-700"><span className="size-2 rounded-full bg-gold-500" />{t('unsaved')}</span>}
          <PrimaryButton className="ms-auto min-w-40" loading={save.isPending} disabled={marked === 0} onClick={() => save.mutate()}>
            {save.isPending ? t('saving') : t('save')}
          </PrimaryButton>
        </div>
      </div>
    </div>
  )
}
