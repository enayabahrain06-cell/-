import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { subjectProgressApi, type PlanStatus, type SubjectProgressRow } from '../../api/education'
import { parseApiError, type FieldErrors } from '../../api/client'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Modal, Notice, SecondaryButton, SURFACE, TextArea, TextInput, type Tone } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { DialogFooter, Field } from '../common/crud'

const STATUS_TONE: Record<PlanStatus, Tone> = { on_time: 'brand', late: 'gold', overdue: 'danger', upcoming: 'muted' }
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** تحديث دروس المواد: per class and subject of the term, the plan by week against what was actually taught. */
export default function SubjectProgressPage() {
  const { t, i18n } = useTranslation('education')
  const locale = i18n.language
  const qc = useQueryClient()
  const [lessonId, setLessonId] = useState<number | ''>('')
  const [subjectId, setSubjectId] = useState<number | undefined>(undefined)
  const [marking, setMarking] = useState<SubjectProgressRow | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const q = useQuery({
    queryKey: ['subject-progress', lessonId, subjectId ?? null],
    queryFn: () => subjectProgressApi.get({ lesson_id: lessonId === '' ? undefined : lessonId, subject_id: subjectId }),
  })
  const unmark = useMutation({
    mutationFn: (id: number) => subjectProgressApi.unmark(id),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['subject-progress'] }) },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const d = q.data
  const short = (s: string) => formatDate(s, locale, { day: 'numeric', month: 'short' })
  const weeks = new Map<number, SubjectProgressRow[]>()
  for (const r of d?.items ?? []) weeks.set(r.week_no, [...(weeks.get(r.week_no) ?? []), r])

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.update_subject_lessons')} subtitle={d ? t('progress.subtitle', { term: d.term.name }) : undefined} />
      </div>
      {q.isLoading && !d ? <LoadingState /> : q.isError ? (
        isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
      ) : d && (d.classes.length === 0 ? <EmptyCard icon="lessons" title={t('progress.no_classes')} /> : (
        <>
          <FilterBar label={t('progress.class')}>
            <SelectField label={t('progress.class')} className="sm:w-64" value={String(lessonId)} onChange={(e) => { setLessonId(e.target.value ? Number(e.target.value) : ''); setSubjectId(undefined) }}
              options={[{ value: '', label: '—' }, ...d.classes.map((c) => ({ value: String(c.id), label: c.name }))]} />
            {d.subjects && d.subjects.length > 0 && (
              <SelectField label={t('progress.subject')} className="sm:w-56" value={String(d.level_subject?.subject.id ?? '')} onChange={(e) => setSubjectId(Number(e.target.value))}
                options={d.subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
            )}
          </FilterBar>
          {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
          {lessonId === '' ? <EmptyCard icon="lessons" title={t('progress.pick_class')} />
            : (d.subjects ?? []).length === 0 ? <EmptyCard icon="lessons" title={t('progress.no_subjects')} />
            : (d.items ?? []).length === 0 ? <EmptyCard icon="edit" title={t('progress.no_plan')} /> : (
              <>
                {d.summary && (
                  <p className="text-sm text-ink/65">{t('progress.summary', {
                    taught: formatNumber(d.summary.taught, locale), total: formatNumber(d.summary.total, locale),
                    on_time: formatNumber(d.summary.on_time, locale), late: formatNumber(d.summary.late, locale), overdue: formatNumber(d.summary.overdue, locale),
                  })}</p>
                )}
                <div className="space-y-4">
                  {[...weeks.entries()].map(([week, rows]) => (
                    <section key={week} className={`${SURFACE} p-4`} aria-labelledby={`week-${week}`}>
                      <div className="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 id={`week-${week}`} className="text-base font-semibold text-ink">{t('progress.week', { n: formatNumber(week, locale) })}</h2>
                        {rows[0].week_starts_on && rows[0].week_ends_on && (
                          <span className="text-xs text-ink/55">{t('progress.week_dates', { from: short(rows[0].week_starts_on), to: short(rows[0].week_ends_on) })}</span>
                        )}
                      </div>
                      <ul className="divide-y divide-ink/6">
                        {rows.map((r) => (
                          <li key={r.plan_item_id} className="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                            <div className="min-w-0 flex-[1_1_14rem]">
                              <p dir="auto" className="font-medium text-ink">{r.title}</p>
                              {r.progress && (
                                <p className="text-xs text-ink/55">
                                  {r.progress.taught_on && formatDate(r.progress.taught_on, locale, { day: 'numeric', month: 'short', year: 'numeric' })}
                                  {r.progress.taught_by && <>، <bdi>{t('progress.taught_by', { name: r.progress.taught_by })}</bdi></>}
                                  {r.progress.notes && <>، <bdi>{r.progress.notes}</bdi></>}
                                </p>
                              )}
                            </div>
                            <Badge tone={STATUS_TONE[r.status]}>{t(`progress.status.${r.status}`)}</Badge>
                            <div className="flex shrink-0 gap-2">
                              <SecondaryButton onClick={() => setMarking(r)}>{r.progress ? t('progress.edit') : t('progress.mark')}</SecondaryButton>
                              {r.progress && (
                                <SecondaryButton className="text-danger" disabled={unmark.isPending} onClick={() => { if (window.confirm(t('progress.unmark_confirm'))) unmark.mutate(r.progress!.id) }}>
                                  {t('progress.unmark')}
                                </SecondaryButton>
                              )}
                            </div>
                          </li>
                        ))}
                      </ul>
                    </section>
                  ))}
                </div>
              </>
            )}
        </>
      ))}
      {marking && d?.lesson && (
        <MarkDialog row={marking} lessonId={d.lesson.id} sessions={d.sessions ?? []} onClose={() => setMarking(null)}
          onSaved={(m) => { setMarking(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function MarkDialog({ row, lessonId, sessions, onClose, onSaved }: { row: SubjectProgressRow; lessonId: number; sessions: { id: number; date: string }[]; onClose: () => void; onSaved: (m: string) => void }) {
  const { t, i18n } = useTranslation('education')
  const locale = i18n.language
  const qc = useQueryClient()
  const [date, setDate] = useState(row.progress?.taught_on ?? ymd(new Date()))
  const [sessionId, setSessionId] = useState(row.progress?.lesson_session_id ? String(row.progress.lesson_session_id) : '')
  const [notes, setNotes] = useState(row.progress?.notes ?? '')
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => subjectProgressApi.mark({ lesson_id: lessonId, plan_item_id: row.plan_item_id, taught_on: date, lesson_session_id: sessionId ? Number(sessionId) : null, notes: notes || null }),
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['subject-progress'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  return (
    <Modal title={row.title} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={errors.taught_on?.[0]}><TextInput label={t('progress.taught_on')} type="date" max={ymd(new Date())} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
        <SelectField label={t('progress.session')} value={sessionId} error={errors.lesson_session_id?.[0]}
          onChange={(e) => { setSessionId(e.target.value); const s = sessions.find((x) => String(x.id) === e.target.value); if (s) setDate(s.date) }}
          options={[{ value: '', label: t('progress.no_session') }, ...sessions.map((s) => ({ value: String(s.id), label: formatDate(s.date, locale, { weekday: 'long', day: 'numeric', month: 'short' }) }))]} />
      </div>
      <Field error={errors.plan_item_id?.[0]}><TextArea label={t('progress.notes')} rows={2} dir="auto" value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
    </Modal>
  )
}
