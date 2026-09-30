import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { gradesApi } from '../../api/grades'
import SelectField from '../../components/SelectField'
import { Notice } from '../../components/ui'

export interface ExamLinks { active: boolean; subject_id: number | null; grade_component_id: number | null; required_lesson_ids: number[] }

/**
 * U10 fields of the exam form (exams of a class or package in the term): the subject, the grade component the exam
 * counts for (توزيع الدرجات) and the required lessons (دروس المواد of its subject). Placement tests never show them.
 */
export default function ExamLinksFields({ lessonId, packageId, examId, value, onChange, errors }: {
  lessonId: number | null; packageId: number | null; examId?: number
  value: ExamLinks; onChange: (v: ExamLinks) => void; errors: Record<string, string[] | undefined>
}) {
  const { t } = useTranslation('grades')
  const enabled = !!(lessonId || packageId)
  const q = useQuery({
    queryKey: ['exam-link-options', lessonId, packageId],
    queryFn: () => gradesApi.examOptions({ lesson_id: lessonId, package_id: lessonId ? null : packageId }),
    enabled, staleTime: 60_000,
  })
  const d = q.data
  const inTerm = enabled && !!d?.in_term
  // Link fields are sent only once the class (or package) is known to be in a term.
  useEffect(() => {
    const first = d?.subjects[0]?.id ?? null
    if (inTerm !== value.active || (inTerm && value.subject_id === null && first !== null)) onChange({ ...value, active: inTerm, subject_id: value.subject_id ?? (inTerm ? first : null) })
  }, [inTerm, value, onChange, d])
  if (!enabled || !d) return null
  if (!d.in_term) return <Notice tone="info">{t('links.not_in_term')}</Notice>

  const components = d.components.filter((c) => c.exam_id === null || c.exam_id === examId)
  const lessons = d.lessons.filter((l) => value.subject_id === null || l.subject_id === value.subject_id)
  const setSubject = (id: number | null) => {
    const comp = d.components.find((c) => c.id === value.grade_component_id)
    onChange({
      ...value, subject_id: id,
      grade_component_id: comp && comp.subject.id !== id ? null : value.grade_component_id,
      required_lesson_ids: value.required_lesson_ids.filter((lid) => d.lessons.find((l) => l.id === lid)?.subject_id === id),
    })
  }
  const setComponent = (id: number | null) => {
    const comp = d.components.find((c) => c.id === id)
    if (comp && comp.subject.id !== value.subject_id) {
      onChange({ ...value, grade_component_id: id, subject_id: comp.subject.id, required_lesson_ids: value.required_lesson_ids.filter((lid) => d.lessons.find((l) => l.id === lid)?.subject_id === comp.subject.id) })
    } else onChange({ ...value, grade_component_id: id })
  }
  const toggle = (id: number) => onChange({ ...value, required_lesson_ids: value.required_lesson_ids.includes(id) ? value.required_lesson_ids.filter((x) => x !== id) : [...value.required_lesson_ids, id] })
  const err = (k: string) => errors[k]?.[0] ?? Object.entries(errors).find(([key]) => key.startsWith(`${k}.`))?.[1]?.[0]

  return (
    <fieldset className="min-w-0 space-y-3 rounded-xl border border-ink/10 p-4">
      <legend className="px-1 text-sm font-semibold text-ink">{t('links.title')}</legend>
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('common.subject')} value={value.subject_id ?? ''} error={err('subject_id')} onChange={(e) => setSubject(e.target.value ? Number(e.target.value) : null)}
          options={d.subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
        <SelectField label={t('links.component')} value={value.grade_component_id ?? ''} error={err('grade_component_id')} onChange={(e) => setComponent(e.target.value ? Number(e.target.value) : null)}
          options={[{ value: '', label: t('links.no_component') }, ...components.map((c) => ({ value: String(c.id), label: t('links.component_option', { name: c.name, subject: c.subject.name, level: c.level.name }) }))]} />
      </div>
      <div>
        <p className="mb-1.5 text-sm font-medium text-ink/75">{t('nav:menu.required_lessons')}</p>
        {lessons.length === 0 ? <p className="text-sm text-ink/55">{t('links.no_lessons')}</p> : (
          <ul className="grid max-h-48 gap-1.5 overflow-y-auto sm:grid-cols-2">
            {lessons.map((l) => (
              <li key={l.id}>
                <label className="flex items-start gap-2 text-sm text-ink/80">
                  <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-brand-600" checked={value.required_lesson_ids.includes(l.id)} onChange={() => toggle(l.id)} />
                  <span dir="auto" className="min-w-0">{l.title}{l.level && <span className="text-xs text-ink/50"> ({l.level.name})</span>}</span>
                </label>
              </li>
            ))}
          </ul>
        )}
        {err('required_lesson_ids') && <p className="mt-1 text-sm text-danger">{err('required_lesson_ids')}</p>}
      </div>
    </fieldset>
  )
}
