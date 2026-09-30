import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { hallsApi, lessonsApi, optionsApi, type Conflict, type Lesson, type LessonInput } from '../../api/lessons'
import { levelsApi } from '../../api/masterData'
import { parseApiError, type FieldErrors } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Link } from 'react-router-dom'
import { buttonClass, Modal, Notice, PrimaryButton, SecondaryButton, TextInput } from '../../components/ui'

export const WEEK_DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'] as const

/** Create or edit a circle. Teacher and hall lists follow the package gender; the server enforces it again. */
export default function LessonFormDialog({ lesson, onClose, onSaved }: { lesson?: Lesson; onClose: () => void; onSaved: (l: Lesson, conflicts: Conflict[]) => void }) {
  const { t } = useTranslation('lessons')
  const qc = useQueryClient()
  const packages = useQuery({ queryKey: ['package-options'], queryFn: optionsApi.packages, staleTime: 5 * 60_000 })
  const [form, setForm] = useState<LessonInput>(() => ({
    name: lesson?.name ?? '',
    package_id: lesson?.package_id ?? 0,
    teacher_id: lesson?.teacher_id ?? 0,
    location_id: lesson?.location_id ?? null,
    days: lesson?.days ?? [],
    start_time: lesson?.start_time ?? '16:00',
    end_time: lesson?.end_time ?? '17:30',
    capacity: lesson?.capacity ?? 15,
    start_date: lesson?.start_date ?? new Date().toISOString().slice(0, 10),
    end_date: lesson?.end_date ?? null,
    status: lesson?.status ?? 'active',
    level_id: lesson?.level_id ?? null,
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)

  const pkg = packages.data?.find((p) => p.id === form.package_id)
  const gender = pkg?.gender
  const teachers = useQuery({ queryKey: ['teacher-options', gender], queryFn: () => optionsApi.teachers(gender), enabled: !!gender })
  const levels = useQuery({ queryKey: ['level-options'], queryFn: () => levelsApi.list({ active: true }), staleTime: 5 * 60_000 })
  const halls = useQuery({ queryKey: ['hall-options'], queryFn: () => hallsApi.list({ active: true }), staleTime: 60_000 })
  const hallOk = (g: string) => g === 'shared' || g === gender || (gender === 'mixed' && g === 'female')

  const save = useMutation({
    mutationFn: () => {
      if (!lesson) return lessonsApi.create(form)
      // A detailed timetable is not overwritten from here: leave days and times out.
      const { days, start_time, end_time, ...rest } = form
      return lessonsApi.update(lesson.id, fixedSchedule ? rest : { ...rest, days, start_time, end_time })
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['lessons'] }); void qc.invalidateQueries({ queryKey: ['lesson', r.data.id] }); onSaved(r.data, r.conflicts) },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMessage(p.message) },
  })

  const set = <K extends keyof LessonInput>(k: K, v: LessonInput[K]) => setForm((f) => ({ ...f, [k]: v }))
  /** The schedule lives in الجدول الدراسي; the form only edits a simple one (one Quran period a night). */
  const fixedSchedule = !!lesson?.schedule && !lesson.schedule.editable
  const err = (k: string) => errors[k]?.[0]

  return (
    <Modal wide title={lesson ? t('form.title_edit') : t('form.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {message && Object.keys(errors).length === 0 && <Notice tone="error">{message}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <TextInput label={t('form.name')} value={form.name} onChange={(e) => set('name', e.target.value)} dir="auto" required />
          {err('name') && <p className="mt-1 text-sm text-danger">{err('name')}</p>}
        </div>
        <div>
          <SelectField label={t('form.package')} value={form.package_id || ''}
            onChange={(e) => {
              const p = packages.data?.find((x) => x.id === Number(e.target.value))
              setForm((f) => ({ ...f, package_id: Number(e.target.value), teacher_id: 0, ...(p && !lesson ? { days: p.days, start_time: p.start_time, end_time: p.end_time, start_date: p.start_date, end_date: p.end_date } : {}) }))
            }}
            options={[{ value: '', label: t('form.choose_package') }, ...(packages.data ?? []).map((p) => ({ value: String(p.id), label: `${p.name} · ${t(`gender.${p.gender}`)}` }))]} />
          {err('package_id') && <p className="mt-1 text-sm text-danger">{err('package_id')}</p>}
        </div>
        <div>
          <SelectField label={t('form.teacher')} value={form.teacher_id || ''} disabled={!gender} onChange={(e) => set('teacher_id', Number(e.target.value))}
            options={[{ value: '', label: t('form.choose_teacher') }, ...(teachers.data ?? []).map((x) => ({ value: String(x.id), label: x.name }))]} />
          <p className="mt-1 text-xs text-ink/50">{t('form.teacher_hint')}</p>
          {err('teacher_id') && <p className="mt-1 text-sm text-danger">{err('teacher_id')}</p>}
        </div>
        <div>
          <SelectField label={t('form.hall')} value={form.location_id ?? ''} onChange={(e) => set('location_id', e.target.value ? Number(e.target.value) : null)}
            options={[{ value: '', label: t('no_hall') }, ...(halls.data ?? []).filter((h) => !gender || hallOk(h.gender)).map((h) => ({ value: String(h.id), label: `${h.name} · ${t(`gender.${h.gender}`)}` }))]} />
          {err('location_id') && <p className="mt-1 text-sm text-danger">{err('location_id')}</p>}
        </div>
        <div>
          <SelectField label={t('form.level')} value={form.level_id ?? ''} onChange={(e) => set('level_id', e.target.value ? Number(e.target.value) : null)}
            options={[{ value: '', label: t('form.no_level') }, ...(levels.data ?? []).map((l) => ({ value: String(l.id), label: l.name })),
              // Keep an inactive level the circle already has selectable, so saving does not clear it.
              ...(lesson?.level && !(levels.data ?? []).some((l) => l.id === lesson.level?.id) ? [{ value: String(lesson.level.id), label: lesson.level.name }] : [])]} />
          {err('level_id') && <p className="mt-1 text-sm text-danger">{err('level_id')}</p>}
        </div>
        <TextInput label={t('form.capacity')} type="number" min={1} max={500} value={form.capacity} onChange={(e) => set('capacity', Number(e.target.value))} />
        {fixedSchedule ? (
          <div className="sm:col-span-2">
            <Notice tone="info">{t('form.schedule_in_timetable')}</Notice>
            <Link to="/term-setup?tab=timetable" className={buttonClass('secondary', 'mt-2')}>{t('form.open_timetable')}</Link>
          </div>
        ) : (<>
        <fieldset className="sm:col-span-2">
          <legend className="mb-1.5 text-sm font-medium text-ink/75">{t('form.days')}</legend>
          <div className="flex flex-wrap gap-2">
            {WEEK_DAYS.map((d) => {
              const on = form.days.includes(d)
              return (
                <label key={d} className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${on ? 'border-brand-600 bg-brand-50 font-medium text-brand-800' : 'border-ink/15 text-ink/70 hover:bg-ink/5'}`}>
                  <input type="checkbox" className="sr-only" checked={on} onChange={() => set('days', on ? form.days.filter((x) => x !== d) : [...form.days, d])} />
                  {t(`days.${d}`)}
                </label>
              )
            })}
          </div>
          {err('days') && <p className="mt-1 text-sm text-danger">{err('days')}</p>}
        </fieldset>
        <TextInput label={t('form.start_time')} type="time" value={form.start_time} onChange={(e) => set('start_time', e.target.value)} />
        <div>
          <TextInput label={t('form.end_time')} type="time" value={form.end_time} onChange={(e) => set('end_time', e.target.value)} />
          {err('end_time') && <p className="mt-1 text-sm text-danger">{err('end_time')}</p>}
        </div>
        </>)}
        <TextInput label={t('form.start_date')} type="date" value={form.start_date} onChange={(e) => set('start_date', e.target.value)} />
        <TextInput label={t('form.end_date')} type="date" value={form.end_date ?? ''} onChange={(e) => set('end_date', e.target.value || null)} />
        <SelectField label={t('form.status')} value={form.status} onChange={(e) => set('status', e.target.value)}
          options={['active', 'paused', 'ended'].map((s) => ({ value: s, label: t(`status.${s}`) }))} />
      </div>
      {err('gender') && <Notice tone="error">{err('gender')}</Notice>}
    </Modal>
  )
}
