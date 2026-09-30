import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { gradesApi, type BookData, type BookRow } from '../../api/grades'
import { parseApiError } from '../../api/client'
import { saveBlob } from '../../api/payments'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, SURFACE, TABLE_HEAD, TableWrap, inputClass } from '../../components/ui'
import { formatNumber, formatPercent } from '../../lib/format'
import { useEmbed, useOwnParam } from '../../app/embed'
import { useTermScope } from '../../app/term'

type Tab = 'record' | 'view' | 'download'
const MENU: Record<Tab, string> = { record: 'grades', view: 'view_grades', download: 'download_grades' }

/** الدرجات / عرض الدرجات / تنزيل الدرجات: a class's gradebook per subject of the term. */
export default function GradesPage() {
  const { t } = useTranslation('grades')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tabs = ([['record', 'grades.record'], ['view', 'grades.view'], ['download', 'grades.view']] as const).filter(([, p]) => can(p)).map(([k]) => k)
  const asked = ownTab as Tab | null
  const tab: Tab = asked && (tabs as string[]).includes(asked) ? asked : tabs[0] ?? 'view'
  const [lessonId, setLessonId] = useState<number | ''>(() => Number(params.get('lesson')) || '')
  const [subjectId, setSubjectId] = useState<number | undefined>(undefined)

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t(`nav:menu.${MENU[tab]}`)} subtitle={t(`book.subtitle_${tab}`)} /></div>
      {!host && tabs.length > 1 && (
        <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
          <Segmented name="grades-tab" label={t('nav:menu.grades')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
            options={tabs.map((k) => ({ value: k, label: t(`nav:menu.${MENU[k]}`) }))} />
        </div>
      )}
      <Book key={tab} tab={tab} lessonId={lessonId} onLesson={(v) => { setLessonId(v); setSubjectId(undefined) }} subjectId={subjectId} onSubject={setSubjectId} />
    </div>
  )
}

function Book({ tab, lessonId, onLesson, subjectId, onSubject }: { tab: Tab; lessonId: number | ''; onLesson: (v: number | '') => void; subjectId?: number; onSubject: (v: number | undefined) => void }) {
  const { t } = useTranslation('grades')
  const ts = useTermScope()
  const record = tab === 'record'
  const q = useQuery({
    queryKey: ['gradebook', tab, lessonId, tab === 'download' ? null : subjectId ?? null],
    queryFn: () => gradesApi.book({ lesson_id: lessonId === '' ? undefined : lessonId, subject_id: tab === 'download' ? undefined : subjectId, mode: record ? 'record' : 'view' }),
    placeholderData: (prev) => prev,
  })
  const d = q.data
  if (q.isLoading && !d) return <LoadingState />
  if (q.isError) return isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  if (!d) return null
  if (d.classes.length === 0) return <EmptyCard icon="lessons" title={t('book.no_classes', { scope: ts.scope() })} />
  const ready = lessonId !== '' && d.lesson?.id === lessonId

  return (
    <div className="space-y-4">
      <FilterBar label={t('common.filters')}>
        <SelectField label={t('common.class')} className="sm:w-64" value={String(lessonId)} onChange={(e) => onLesson(e.target.value ? Number(e.target.value) : '')}
          options={[{ value: '', label: '—' }, ...d.classes.map((c) => ({ value: String(c.id), label: c.level ? t('common.class_option', { name: c.name, level: c.level }) : c.name }))]} />
        {tab !== 'download' && ready && (d.subjects ?? []).length > 0 && (
          <SelectField label={t('common.subject')} className="sm:w-56" value={String(d.level_subject?.subject.id ?? '')} onChange={(e) => onSubject(Number(e.target.value))}
            options={(d.subjects ?? []).map((s) => ({ value: String(s.id), label: s.name }))} />
        )}
      </FilterBar>
      {lessonId === '' ? <EmptyCard icon="lessons" title={t('book.pick_class')} />
        : !ready ? <LoadingState />
        : (d.subjects ?? []).length === 0 ? <EmptyCard icon="evaluation" title={record ? t('book.no_subjects_record') : t('book.no_subjects', { scope: ts.scope() })} />
        : tab === 'download' ? <DownloadPanel data={d} />
        : (d.components ?? []).length === 0 ? <EmptyCard icon="chart" title={t('book.no_components')} body={t('book.no_components_body')} />
        : record ? <RecordPanel data={d} /> : <ViewPanel data={d} />}
    </div>
  )
}

function RecordPanel({ data }: { data: BookData }) {
  const { t, i18n } = useTranslation('grades')
  const locale = i18n.language
  const qc = useQueryClient()
  const entryComponents = (data.components ?? []).filter((c) => c.kind !== 'exam')
  const examComponents = (data.components ?? []).filter((c) => c.kind === 'exam')
  const [componentId, setComponentId] = useState<number | undefined>(entryComponents[0]?.id)
  const component = entryComponents.find((c) => c.id === componentId) ?? entryComponents[0]
  const [scores, setScores] = useState<Record<number, string>>({})
  const [notes, setNotes] = useState<Record<number, string>>({})
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  // Start from the saved scores whenever the component or the book (re)loads.
  const [seed, setSeed] = useState<{ c?: number; rows?: BookRow[] }>({})
  if (component && (seed.c !== component.id || seed.rows !== data.students)) {
    const rows = data.students ?? []
    setSeed({ c: component.id, rows: data.students })
    setScores(Object.fromEntries(rows.map((r) => [r.student.id, r.cells[component.id]?.score == null ? '' : String(r.cells[component.id].score)])))
    setNotes(Object.fromEntries(rows.map((r) => [r.student.id, r.cells[component.id]?.notes ?? ''])))
  }
  const save = useMutation({
    mutationFn: () => gradesApi.saveBook({
      lesson_id: data.lesson!.id, grade_component_id: component!.id,
      entries: (data.students ?? []).map((r) => ({ student_id: r.student.id, score: scores[r.student.id] === '' || scores[r.student.id] === undefined ? null : Number(scores[r.student.id]), notes: notes[r.student.id] || null })),
    }),
    onSuccess: (r) => { setMsg({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['gradebook'] }) },
    onError: (e) => { const p = parseApiError(e); setMsg({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  const n = (v: number) => formatNumber(v, locale, { maximumFractionDigits: 2 })
  const over = component ? Object.values(scores).some((v) => v !== '' && (Number(v) > component.max_marks || Number(v) < 0 || Number.isNaN(Number(v)))) : false

  return (
    <div className="space-y-4">
      {examComponents.length > 0 && <Notice tone="info">{t('book.exam_read_only', { names: examComponents.map((c) => c.name).join(t('common.sep')) })}</Notice>}
      {!component ? <EmptyCard icon="exams" title={t('book.only_exams')} /> : (
        <>
          <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
            <Segmented name="grade-component" label={t('book.component')} value={String(component.id)} onChange={(v) => { setComponentId(Number(v)); setMsg(null) }}
              options={entryComponents.map((c) => ({ value: String(c.id), label: c.name }))} />
          </div>
          <p className="text-sm text-ink/65">{t('book.component_line', { kind: t(`kind.${component.kind}`), max: n(component.max_marks), weight: n(component.weight) })}</p>
          {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
          {(data.students ?? []).length === 0 ? <EmptyCard icon="students" title={t('book.no_students')} /> : (
            <ul className={`${SURFACE} divide-y divide-ink/6`}>
              {(data.students ?? []).map((r) => {
                const sid = r.student.id
                const v = scores[sid] ?? ''
                const bad = v !== '' && (Number(v) > component.max_marks || Number(v) < 0 || Number.isNaN(Number(v)))
                return (
                  <li key={sid} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5">
                    <span className="min-w-0 flex-[1_1_12rem]"><span dir="auto" className="block font-medium text-ink">{r.student.full_name}</span><span className="block text-xs tabular-nums text-ink/50"><bdi dir="ltr">{r.student.student_no}</bdi></span></span>
                    <span className="flex shrink-0 items-center gap-2">
                    <label className="sr-only" htmlFor={`g-${sid}`}>{t('common.score')}</label>
                    <input id={`g-${sid}`} type="number" min={0} max={component.max_marks} step="0.25" inputMode="decimal" value={v} aria-invalid={bad}
                      onChange={(e) => setScores({ ...scores, [sid]: e.target.value })} className={inputClass('sm', 'w-24 text-center tabular-nums', bad)} />
                    <span className="w-12 text-xs text-ink/50">/ {n(component.max_marks)}</span>
                    </span>
                    <label className="sr-only" htmlFor={`gn-${sid}`}>{t('book.notes')}</label>
                    <input id={`gn-${sid}`} dir="auto" placeholder={t('book.notes')} value={notes[sid] ?? ''} maxLength={500}
                      onChange={(e) => setNotes({ ...notes, [sid]: e.target.value })} className={inputClass('sm', 'min-w-0 basis-full sm:basis-auto sm:flex-[1_1_10rem]')} />
                  </li>
                )
              })}
            </ul>
          )}
          <div className="flex flex-wrap items-center justify-end gap-3">
            {over && <p className="text-sm text-danger">{t('book.over_max', { max: n(component.max_marks) })}</p>}
            <PrimaryButton loading={save.isPending} disabled={over || (data.students ?? []).length === 0} onClick={() => save.mutate()}><Icon name="check" className="size-4" />{t('common.save')}</PrimaryButton>
          </div>
        </>
      )}
    </div>
  )
}

function ViewPanel({ data }: { data: BookData }) {
  const { t, i18n } = useTranslation('grades')
  const locale = i18n.language
  const [student, setStudent] = useState<BookRow['student'] | null>(null)
  const comps = data.components ?? []
  const rows = data.students ?? []
  const n = (v: number) => formatNumber(v, locale, { maximumFractionDigits: 2 })
  const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatPercent(v, locale))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 text-sm text-ink/65">
        <span>{t('book.weight_total', { total: n(data.weight_total ?? 0) })}</span>
        {Math.abs((data.weight_total ?? 0) - 100) >= 0.001 && <Badge tone="gold">{t('book.weights_incomplete')}</Badge>}
        <SecondaryButton className="ms-auto" onClick={async () => saveBlob(await gradesApi.bookXlsx({ lesson_id: data.lesson!.id, subject_id: data.level_subject!.subject.id }), `grades-${data.lesson!.id}-${data.level_subject!.subject.id}.xlsx`)}>
          <Icon name="download" className="size-4" />{t('nav:menu.download_grades')}
        </SecondaryButton>
      </div>
      {rows.length === 0 ? <EmptyCard icon="students" title={t('book.no_students')} /> : (
        <TableWrap surface>
          <table className="w-full min-w-[36rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th className="px-4 py-3 text-start font-medium">{t('common.student')}</th>
                {comps.map((c) => (
                  <th key={c.id} className="px-3 py-3 text-center font-medium">
                    <span dir="auto" className="block">{c.name}</span>
                    <span className="block text-[11px] font-normal text-ink/50">{t('book.col_meta', { max: n(c.max_marks), weight: n(c.weight) })}</span>
                  </th>
                ))}
                <th className="px-4 py-3 text-end font-medium">{t('book.total')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {rows.map((r) => (
                <tr key={r.student.id} className="hover:bg-brand-50/40">
                  <td className="px-4 py-3">
                    <button type="button" onClick={() => setStudent(r.student)} dir="auto" className="text-start font-medium text-brand-700 hover:underline">{r.student.full_name}</button>
                    <span className="block text-xs tabular-nums text-ink/50"><bdi dir="ltr">{r.student.student_no}</bdi></span>
                  </td>
                  {comps.map((c) => <td key={c.id} className="px-3 py-3 text-center tabular-nums"><CellValue score={r.cells[c.id]?.score ?? null} /></td>)}
                  <td className="px-4 py-3 text-end">
                    <span className="font-semibold tabular-nums text-ink">{pct(r.total)}</span>
                    {!r.complete && <span className="block text-[11px] text-gold-700">{t('book.incomplete')}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t border-ink/10 bg-page/60 text-ink/70">
              <tr>
                <th scope="row" className="px-4 py-3 text-start font-medium">{t('book.class_average')}</th>
                {comps.map((c) => { const a = data.averages?.components[c.id]; return <td key={c.id} className="px-3 py-3 text-center tabular-nums">{a === null || a === undefined ? '—' : n(a)}</td> })}
                <td className="px-4 py-3 text-end font-semibold tabular-nums">{pct(data.averages?.total)}</td>
              </tr>
            </tfoot>
          </table>
        </TableWrap>
      )}
      {student && <StudentDialog student={student} lessonId={data.lesson!.id} onClose={() => setStudent(null)} />}
    </div>
  )
}

function CellValue({ score }: { score: number | null }) {
  const { t, i18n } = useTranslation('grades')
  if (score === null) return <span className="text-ink/35" title={t('book.missing')}>—</span>
  return <span className="text-ink">{formatNumber(score, i18n.language, { maximumFractionDigits: 2 })}</span>
}

function StudentDialog({ student, lessonId, onClose }: { student: BookRow['student']; lessonId: number; onClose: () => void }) {
  const { t, i18n } = useTranslation('grades')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['student-grades', student.id, lessonId], queryFn: () => gradesApi.student(student.id, lessonId) })
  const n = (v: number) => formatNumber(v, locale, { maximumFractionDigits: 2 })
  return (
    <Modal wide title={student.full_name} onClose={onClose}>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState message={parseApiError(q.error).message} /> : (
        <div className="space-y-4">
          <p className="text-sm text-ink/65">{t('book.student_line', { class: q.data.lesson.name, term: q.data.term.name })}</p>
          {q.data.subjects.map((s) => (
            <section key={s.subject.id} className="rounded-xl border border-ink/10 p-3">
              <div className="mb-2 flex flex-wrap items-baseline gap-2">
                <h3 className="text-base font-semibold text-ink">{s.subject.name}</h3>
                <span className="ms-auto font-semibold tabular-nums text-brand-700">{s.total === null ? '—' : formatPercent(s.total, locale)}</span>
              </div>
              {s.components.length === 0 ? <p className="text-sm text-ink/55">{t('book.no_components')}</p> : (
                <ul className="divide-y divide-ink/6 text-sm">
                  {s.components.map((c) => (
                    <li key={c.id} className="flex flex-wrap items-center gap-2 py-1.5">
                      <span dir="auto" className="min-w-0 flex-1 text-ink/80">{c.name}</span>
                      <span className="text-xs text-ink/50">{t('book.col_meta', { max: n(c.max_marks), weight: n(c.weight) })}</span>
                      <span className="w-16 text-end tabular-nums text-ink">{c.cell?.score === null || c.cell?.score === undefined ? '—' : n(c.cell.score)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))}
          <p className="text-end text-sm font-semibold text-ink">{t('book.average_all', { value: q.data.average === null ? '—' : formatPercent(q.data.average, locale) })}</p>
        </div>
      )}
    </Modal>
  )
}

function DownloadPanel({ data }: { data: BookData }) {
  const { t } = useTranslation('grades')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const lessonId = data.lesson!.id
  const get = async (key: string, subjectId?: number) => {
    setBusy(key); setError(null)
    try { saveBlob(await gradesApi.bookXlsx({ lesson_id: lessonId, subject_id: subjectId }), `grades-${lessonId}${subjectId ? `-${subjectId}` : ''}.xlsx`) } catch (e) { setError(parseApiError(e).message) } finally { setBusy(null) }
  }
  return (
    <div className="space-y-4">
      {error && <Notice tone="error">{error}</Notice>}
      <div className={`${SURFACE} flex flex-wrap items-center gap-3 p-4`}>
        <div className="min-w-0 flex-[1_1_14rem]">
          <p className="font-semibold text-ink">{t('download.whole_class')}</p>
          <p className="text-sm text-ink/60">{t('download.whole_class_hint')}</p>
        </div>
        <PrimaryButton loading={busy === 'all'} onClick={() => void get('all')}><Icon name="download" className="size-4" />{t('download.download')}</PrimaryButton>
      </div>
      <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
        {(data.subjects ?? []).map((s) => (
          <li key={s.id} className={`${SURFACE} flex items-center gap-3 p-4`}>
            <span className="min-w-0 flex-1 font-medium text-ink">{s.name}</span>
            <SecondaryButton disabled={busy !== null} onClick={() => void get(String(s.id), s.id)}><Icon name="download" className="size-4" />{t('download.download')}</SecondaryButton>
          </li>
        ))}
      </ul>
    </div>
  )
}
