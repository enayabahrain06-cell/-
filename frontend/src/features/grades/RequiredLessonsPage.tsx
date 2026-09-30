import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { examsApi } from '../../api/exams'
import { gradesApi } from '../../api/grades'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Notice, PrimaryButton, SURFACE } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'

/** الدروس المطلوبة: pick an exam, then the subject lessons (دروس المواد) it covers. The syllabus text stays as a note. */
export default function RequiredLessonsPage() {
  const { t, i18n } = useTranslation('grades')
  const locale = i18n.language
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const [examId, setExamId] = useState<number | ''>(() => Number(params.get('exam')) || '')
  const exams = useQuery({ queryKey: ['exams', 'grades-picker'], queryFn: () => examsApi.list({ per_page: 200 }) })
  const list = (exams.data?.data ?? []).filter((e) => e.type !== 'placement')
  const q = useQuery({ queryKey: ['required-lessons', examId], queryFn: () => gradesApi.requiredLessons(Number(examId)), enabled: examId !== '' })
  const [picked, setPicked] = useState<number[]>([])
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  // Start from the saved choice whenever it (re)loads.
  const [seed, setSeed] = useState(q.data)
  if (q.data !== seed) { setSeed(q.data); if (q.data) setPicked(q.data.selected) }
  const save = useMutation({
    mutationFn: () => gradesApi.saveRequiredLessons(Number(examId), picked),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['required-lessons'] }); void qc.invalidateQueries({ queryKey: ['exam'] }) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  const d = q.data
  const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
  const groups = new Map<string, NonNullable<typeof d>['available']>()
  for (const l of d?.available ?? []) {
    const k = l.level?.name ?? t('required.all_levels')
    groups.set(k, [...(groups.get(k) ?? []), l])
  }

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t('nav:menu.required_lessons')} subtitle={t('required.subtitle')} /></div>
      {exams.isLoading ? <LoadingState /> : exams.isError ? <ErrorState message={parseApiError(exams.error).message} onRetry={() => void exams.refetch()} /> : list.length === 0 ? (
        <EmptyCard icon="exams" title={t('required.no_exams')} />
      ) : (
        <>
          <FilterBar label={t('common.exam')}>
            <SelectField label={t('common.exam')} className="sm:w-96" value={String(examId)} onChange={(e) => { setExamId(e.target.value ? Number(e.target.value) : ''); setNotice(null) }}
              options={[{ value: '', label: '—' }, ...list.map((e) => ({ value: String(e.id), label: t('common.exam_option', { name: e.name, where: e.lesson_name ?? e.package_name ?? '', date: formatDate(e.exam_date, locale, { day: 'numeric', month: 'short' }) }) }))]} />
          </FilterBar>
          {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
          {examId === '' ? <EmptyCard icon="lessons" title={t('required.pick')} /> : q.isLoading ? <LoadingState /> : q.isError || !d ? <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} /> : (
            <div className="space-y-4">
              <div className={`${SURFACE} space-y-2 p-4`}>
                <div className="flex flex-wrap items-center gap-2">
                  <Link to={`/exams/${d.exam.id}`} dir="auto" className="font-semibold text-brand-700 hover:underline">{d.exam.name}</Link>
                  {d.exam.subject && <Badge tone="info">{d.exam.subject.name}</Badge>}
                  {d.exam.where && <span dir="auto" className="text-sm text-ink/60">{d.exam.where}</span>}
                  <span className="ms-auto text-sm text-ink/60">{t('required.count', { n: formatNumber(picked.length, locale) })}</span>
                </div>
                {d.exam.syllabus && <p dir="auto" className="whitespace-pre-line text-sm text-ink/70"><span className="font-medium text-ink/80">{t('required.syllabus_note')}: </span>{d.exam.syllabus}</p>}
              </div>
              {d.available.length === 0 ? <EmptyCard icon="lessons" title={t('required.no_lessons')} body={t('required.no_lessons_body')} /> : (
                <>
                  {[...groups.entries()].map(([level, items]) => (
                    <fieldset key={level} className={`${SURFACE} min-w-0 p-4`}>
                      <legend className="sr-only">{level}</legend>
                      <p aria-hidden className="mb-2 text-base font-semibold text-ink">{level}</p>
                      <ul className="grid gap-2 md:grid-cols-2">
                        {items.map((l) => (
                          <li key={l.id}>
                            <label className={`flex min-h-10 cursor-pointer items-start gap-3 rounded-xl border px-3 py-2 ${picked.includes(l.id) ? 'border-brand-500/40 bg-brand-50' : 'border-ink/10 hover:bg-ink/5'} ${d.can_manage ? '' : 'cursor-default'}`}>
                              <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-brand-600" checked={picked.includes(l.id)} disabled={!d.can_manage} onChange={() => toggle(l.id)} />
                              <span className="min-w-0">
                                <span dir="auto" className="block text-sm font-medium text-ink">{l.title}</span>
                                {l.description && <span dir="auto" className="block text-xs text-ink/55">{l.description}</span>}
                              </span>
                            </label>
                          </li>
                        ))}
                      </ul>
                    </fieldset>
                  ))}
                  {d.can_manage && (
                    <div className="flex justify-end"><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}><Icon name="check" className="size-4" />{t('common.save')}</PrimaryButton></div>
                  )}
                </>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}
