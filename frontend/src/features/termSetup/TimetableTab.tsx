import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError, type FieldErrors } from '../../api/client'
import { termSetupApi, type SlotInput, type TimetableSlot } from '../../api/termSetup'
import SelectField from '../../components/SelectField'
import Icon from '../../components/Icon'
import { EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, Notice, SURFACE, TextArea, TextInput } from '../../components/ui'
import { formatTime } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove, type CrudNotice } from '../common/crud'
import { useCanManage, useNights, useSetupOptions } from './shared'

/** الجدول الدراسي: one card per night with its periods, filtered by level or teacher. */
export default function TimetableTab() {
  const { t, i18n } = useTranslation('termSetup')
  const canManage = useCanManage()
  const options = useSetupOptions()
  const [levelId, setLevelId] = useState<number | ''>('')
  const [teacherId, setTeacherId] = useState<number | ''>('')
  const q = useQuery({
    queryKey: ['term-setup-timetable', levelId, teacherId],
    queryFn: () => termSetupApi.timetable({ level_id: levelId || undefined, teacher_id: teacherId || undefined }),
    placeholderData: keepPreviousData,
  })
  const [edit, setEdit] = useState<{ weekday: string; slot?: TimetableSlot } | null>(null)
  const { notice, setNotice, remove } = useRemove(termSetupApi.removeSlot, [['term-setup-timetable']], t('timetable.delete_confirm'))
  const [warnings, setWarnings] = useState<string[]>([])
  const time = (v: string) => formatTime(v, i18n.language)
  const nights = useNights((q.data?.data ?? []).map((s) => s.weekday))
  const opts = options.data

  return (
    <div className="space-y-4">
      <FilterBar>
        <SelectField label={t('fields.level')} hideLabel className="sm:w-56" value={String(levelId)} onChange={(e) => setLevelId(e.target.value ? Number(e.target.value) : '')}
          options={[{ value: '', label: t('timetable.all_levels') }, ...(opts?.levels ?? []).map((l) => ({ value: String(l.id), label: l.name }))]} />
        <SelectField label={t('fields.teacher')} hideLabel className="sm:w-56" value={String(teacherId)} onChange={(e) => setTeacherId(e.target.value ? Number(e.target.value) : '')}
          options={[{ value: '', label: t('timetable.all_teachers') }, ...(opts?.teachers ?? []).map((x) => ({ value: String(x.id), label: x.name }))]} />
      </FilterBar>
      <Toolbar notice={notice} />
      {warnings.length > 0 && <Notice tone="error"><b>{t('timetable.warnings')}</b> {warnings.join(' ')}</Notice>}
      {(opts?.levels.length ?? 0) === 0 && (opts?.circles.length ?? 0) === 0 ? <EmptyCard icon="lessons" title={t('no_levels')} /> : q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {nights.map((d) => {
            const rows = (q.data?.data ?? []).filter((s) => s.weekday === d)
            if (rows.length === 0 && !canManage) return null
            return (
              <li key={d} className={`${SURFACE} p-4`}>
                <p className="font-semibold text-ink">{t(`lessons:days.${d}`)}</p>
                {rows.length === 0 ? <p className="mt-2 text-sm text-ink/50">{t('timetable.none')}</p> : (
                  <ul className="mt-2 divide-y divide-ink/6">
                    {rows.map((s) => (
                      <li key={s.id} className="flex items-start gap-2 py-2 text-sm">
                        <div className="min-w-0 flex-1">
                          <p className="text-xs tabular-nums text-ink/60"><bdi>{time(s.start_time)}–{time(s.end_time)}</bdi></p>
                          <p dir="auto" className="font-medium text-ink">{s.subject.name}</p>
                          <p className="text-xs text-ink/55">
                            <bdi>{s.lesson ? (s.level ? `${s.level.name} · ${s.lesson.name}` : s.lesson.name) : s.level?.name}</bdi>
                            {s.teacher && <> · <bdi>{s.teacher.name}</bdi></>}
                            {s.location && <> · <bdi>{s.location.name}</bdi></>}
                          </p>
                        </div>
                        {canManage && (
                          <div className="flex shrink-0">
                            <IconButton icon="edit" label={t('edit')} onClick={() => setEdit({ weekday: d, slot: s })} />
                            <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(s.id)} />
                          </div>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {canManage && (
                  <button type="button" onClick={() => setEdit({ weekday: d })}
                    className="mt-3 inline-flex min-h-9 items-center gap-1 rounded-xl border border-dashed border-ink/20 px-3 text-sm text-brand-700 hover:bg-brand-50">
                    <Icon name="plus" className="size-4" />{t('timetable.add')}
                  </button>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {edit && opts && (
        <SlotDialog weekday={edit.weekday} slot={edit.slot} levelId={levelId || opts.levels[0]?.id || 0}
          onClose={() => setEdit(null)}
          onSaved={(n: CrudNotice, w: string[]) => { setEdit(null); setNotice(n); setWarnings(w) }} />
      )}
    </div>
  )
}

function SlotDialog({ weekday, slot, levelId, onClose, onSaved }: { weekday: string; slot?: TimetableSlot; levelId: number; onClose: () => void; onSaved: (n: CrudNotice, warnings: string[]) => void }) {
  const { t } = useTranslation('termSetup')
  const qc = useQueryClient()
  const opts = useSetupOptions().data!
  const [form, setForm] = useState<SlotInput>(() => ({
    academic_term_id: opts.term.id, level_id: slot ? slot.level?.id ?? null : levelId || null, lesson_id: slot?.lesson?.id ?? null, weekday: slot?.weekday ?? weekday,
    start_time: slot?.start_time ?? '16:00', end_time: slot?.end_time ?? '17:00', subject_id: slot?.subject.id ?? opts.subjects[0]?.id ?? 0,
    teacher_id: slot?.teacher?.id ?? null, location_id: slot?.location?.id ?? null, notes: slot?.notes ?? '',
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = { ...form, notes: form.notes || null }
      return slot ? termSetupApi.updateSlot(slot.id, d) : termSetupApi.createSlot(d)
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['term-setup-timetable'] }); onSaved({ tone: 'success', text: r.message }, r.warnings) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  const set = <K extends keyof SlotInput>(k: K, v: SlotInput[K]) => setForm((f) => ({ ...f, [k]: v }))
  const nights = useNights([form.weekday])
  // A class can take a period of its own with or without a level; choosing it sets the level to the class's.
  const circles = form.level_id ? opts.circles.filter((c) => c.level_id === form.level_id) : opts.circles
  const mine = new Set(opts.level_rooms.filter((r) => r.level_id === form.level_id).map((r) => r.location_id))
  const halls = [...opts.halls.filter((h) => mine.has(h.id)), ...opts.halls.filter((h) => !mine.has(h.id))]

  return (
    <Modal wide title={slot ? t('timetable.title_edit') : t('timetable.title_new')} onClose={onClose}
      footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('fields.level')} value={String(form.level_id ?? '')} onChange={(e) => setForm({ ...form, level_id: e.target.value ? Number(e.target.value) : null, lesson_id: null })}
          options={[{ value: '', label: t('timetable.no_level') }, ...opts.levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
        <Field error={errors.lesson_id?.[0]}>
          <SelectField label={t('fields.circle')} value={String(form.lesson_id ?? '')}
            onChange={(e) => { const c = opts.circles.find((x) => x.id === Number(e.target.value)); setForm({ ...form, lesson_id: c?.id ?? null, level_id: c ? c.level_id : form.level_id }) }}
            options={[...(form.level_id ? [{ value: '', label: t('timetable.whole_level') }] : [{ value: '', label: t('timetable.choose_class') }]), ...circles.map((c) => ({ value: String(c.id), label: c.name }))]} />
        </Field>
        <SelectField label={t('fields.weekday')} value={form.weekday} onChange={(e) => set('weekday', e.target.value)}
          options={nights.map((d) => ({ value: d, label: t(`lessons:days.${d}`) }))} />
        <div className="grid grid-cols-2 gap-2">
          <TextInput label={t('fields.start_time')} type="time" value={form.start_time} onChange={(e) => set('start_time', e.target.value)} />
          <TextInput label={t('fields.end_time')} type="time" value={form.end_time} onChange={(e) => set('end_time', e.target.value)} />
        </div>
        <SelectField label={t('fields.subject')} value={String(form.subject_id)} onChange={(e) => set('subject_id', Number(e.target.value))}
          options={opts.subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
        <Field error={errors.teacher_id?.[0]}>
          <SelectField label={t('fields.teacher')} value={String(form.teacher_id ?? '')} onChange={(e) => set('teacher_id', e.target.value ? Number(e.target.value) : null)}
            options={[{ value: '', label: t('timetable.default_teacher') }, ...opts.teachers.map((x) => ({ value: String(x.id), label: x.name }))]} />
        </Field>
        <SelectField label={t('fields.room')} value={String(form.location_id ?? '')} onChange={(e) => set('location_id', e.target.value ? Number(e.target.value) : null)}
          options={[{ value: '', label: t('timetable.no_room') }, ...halls.map((h) => ({ value: String(h.id), label: mine.has(h.id) ? t('timetable.level_room', { name: h.name }) : h.name }))]} />
      </div>
      {(errors.start_time?.[0] || errors.end_time?.[0]) && <Notice tone="error">{errors.start_time?.[0] ?? errors.end_time?.[0]}</Notice>}
      <TextArea label={t('fields.notes')} rows={2} dir="auto" value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />
    </Modal>
  )
}
