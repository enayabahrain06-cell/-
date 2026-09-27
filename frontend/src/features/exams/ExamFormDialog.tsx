import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { examsApi, type Exam, type ExamInput } from '../../api/exams'
import { lessonsApi, optionsApi } from '../../api/lessons'
import { parseApiError, type FieldErrors } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, Segmented, TextArea, TextInput } from '../../components/ui'

/** <input type="datetime-local"> works in local time; the API accepts ISO strings (converted server-side to UTC). */
const toLocal = (iso?: string | null) => (iso ? iso.slice(0, 16) : '')

export default function ExamFormDialog({ exam, onClose, onSaved }: { exam?: Exam; onClose: () => void; onSaved: (e: Exam) => void }) {
  const { t } = useTranslation('exams')
  const qc = useQueryClient()
  const packages = useQuery({ queryKey: ['package-options'], queryFn: optionsApi.packages, staleTime: 5 * 60_000 })
  const lessons = useQuery({ queryKey: ['lessons', 'all-options'], queryFn: () => lessonsApi.list({ per_page: 200 }), staleTime: 5 * 60_000 })
  const today = new Date().toISOString().slice(0, 10)
  const [scope, setScope] = useState<'lesson' | 'package'>(exam?.package_id && !exam.lesson_id ? 'package' : 'lesson')
  const [form, setForm] = useState<ExamInput>(() => ({
    name: exam?.name ?? '',
    package_id: exam?.package_id ?? null,
    lesson_id: exam?.lesson_id ?? null,
    type: exam?.type ?? 'online',
    exam_date: exam?.exam_date ?? today,
    opens_at: toLocal(exam?.opens_at) || `${today}T16:00`,
    closes_at: toLocal(exam?.closes_at) || `${today}T18:00`,
    duration_minutes: exam?.duration_minutes ?? 30,
    total_marks: exam?.total_marks ?? 100,
    pass_mark: exam?.pass_mark ?? 60,
    syllabus: exam?.syllabus ?? '',
    randomize: exam?.randomize ?? true,
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const set = <K extends keyof ExamInput>(k: K, v: ExamInput[K]) => setForm((f) => ({ ...f, [k]: v }))

  const save = useMutation({
    mutationFn: () => {
      // The inputs hold Asia/Bahrain wall-clock time (UTC+3 all year); send an explicit offset so the server stores the right UTC instant.
      const withTz = (v: string) => (v.length === 16 ? `${v}:00+03:00` : v)
      const d = { ...form, opens_at: withTz(form.opens_at), closes_at: withTz(form.closes_at), package_id: scope === 'package' ? form.package_id : null, lesson_id: scope === 'lesson' ? form.lesson_id : null }
      return exam ? examsApi.update(exam.id, d) : examsApi.create(d)
    },
    onSuccess: (e) => { void qc.invalidateQueries({ queryKey: ['exams'] }); void qc.invalidateQueries({ queryKey: ['exam', e.id] }); onSaved(e) },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMsg(p.message) },
  })
  const err = (k: string) => errors[k]?.[0]

  return (
    <Modal wide title={exam ? t('form.title_edit') : t('form.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {msg && Object.keys(errors).length === 0 && <Notice tone="error">{msg}</Notice>}
      <TextInput label={t('form.name')} value={form.name} onChange={(e) => set('name', e.target.value)} dir="auto" />
      {err('name') && <p className="text-sm text-danger">{err('name')}</p>}
      <div className="flex flex-wrap items-center gap-4">
        <Segmented name="exam-type" label={t('form.type')} value={form.type} onChange={(v) => set('type', v)} options={[{ value: 'online', label: t('type.online') }, { value: 'paper', label: t('type.paper') }]} />
        <Segmented name="exam-scope" label={t('form.scope')} value={scope} onChange={setScope} options={[{ value: 'lesson', label: t('form.lesson') }, { value: 'package', label: t('form.package') }]} />
      </div>
      {scope === 'lesson' ? (
        <SelectField label={t('form.lesson')} value={form.lesson_id ?? ''} onChange={(e) => set('lesson_id', e.target.value ? Number(e.target.value) : null)}
          options={[{ value: '', label: t('form.none') }, ...(lessons.data?.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))]} />
      ) : (
        <SelectField label={t('form.package')} value={form.package_id ?? ''} onChange={(e) => set('package_id', e.target.value ? Number(e.target.value) : null)}
          options={[{ value: '', label: t('form.none') }, ...(packages.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} />
      )}
      {(err('lesson_id') || err('package_id') || err('gender')) && <Notice tone="error">{err('lesson_id') ?? err('package_id') ?? err('gender')}</Notice>}
      <div className="grid gap-4 sm:grid-cols-3">
        <TextInput label={t('form.exam_date')} type="date" value={form.exam_date} onChange={(e) => set('exam_date', e.target.value)} />
        <TextInput label={t('form.opens_at')} type="datetime-local" value={form.opens_at} onChange={(e) => set('opens_at', e.target.value)} />
        <div>
          <TextInput label={t('form.closes_at')} type="datetime-local" value={form.closes_at} onChange={(e) => set('closes_at', e.target.value)} />
          {err('closes_at') && <p className="mt-1 text-sm text-danger">{err('closes_at')}</p>}
        </div>
        <TextInput label={t('form.duration')} type="number" min={1} max={600} value={form.duration_minutes} onChange={(e) => set('duration_minutes', Number(e.target.value))} />
        <TextInput label={t('form.total_marks')} type="number" min={1} value={form.total_marks} onChange={(e) => set('total_marks', Number(e.target.value))} />
        <div>
          <TextInput label={t('form.pass_mark')} type="number" min={0} value={form.pass_mark} onChange={(e) => set('pass_mark', Number(e.target.value))} />
          {err('pass_mark') && <p className="mt-1 text-sm text-danger">{err('pass_mark')}</p>}
        </div>
      </div>
      <TextArea label={t('form.syllabus')} rows={2} value={form.syllabus ?? ''} onChange={(e) => set('syllabus', e.target.value)} dir="auto" />
      {form.type === 'online' && (
        <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={form.randomize} onChange={(e) => set('randomize', e.target.checked)} />{t('form.randomize')}</label>
      )}
    </Modal>
  )
}
