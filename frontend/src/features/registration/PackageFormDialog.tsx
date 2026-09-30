import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { packagesApi, type Package, type PackageInput } from '../../api/registration'
import { parseApiError, type FieldErrors } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import { useTerm } from '../../app/term'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput } from '../../components/ui'
import { WEEK_DAYS } from '../lessons/LessonFormDialog'

export default function PackageFormDialog({ pkg, onClose }: { pkg?: Package; onClose: () => void }) {
  const { t } = useTranslation('registration')
  const { t: tl } = useTranslation('lessons')
  const { user, hasRole } = useAuth()
  const qc = useQueryClient()
  const { terms, selected, current } = useTerm()
  const scoped = !hasRole('super_admin') && user?.track && user.track !== 'both' ? user.track : null
  const [form, setForm] = useState<PackageInput>(() => ({
    name: pkg?.name_ar ?? '',
    name_ar: pkg?.name_ar ?? '',
    name_en: pkg?.name_en ?? '',
    description: pkg?.description ?? '',
    min_age: pkg?.min_age ?? 7,
    max_age: pkg?.max_age ?? 12,
    gender: pkg?.gender ?? (scoped as PackageInput['gender']) ?? 'male',
    seats: pkg?.seats ?? 30,
    price: ((pkg?.price_fils ?? 0) / 1000).toFixed(3),
    days: pkg?.days ?? ['sat', 'mon', 'wed'],
    start_time: pkg?.start_time ?? '16:00',
    end_time: pkg?.end_time ?? '17:30',
    start_date: pkg?.start_date ?? new Date().toISOString().slice(0, 10),
    end_date: pkg?.end_date ?? null,
    term: pkg?.term ?? '',
    // A new package goes to the term being viewed (or the current one); the server defaults to the current term too.
    academic_term_id: pkg ? pkg.academic_term_id ?? null : selected?.id ?? current?.id ?? null,
    plan_ayahs: pkg?.plan_ayahs ?? 0,
    memorization_direction: pkg?.memorization_direction ?? 'backward',
    status: pkg?.status ?? 'draft',
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => {
      const d = { ...form, name: form.name_ar || form.name_en || form.name }
      return pkg ? packagesApi.update(pkg.id, d) : packagesApi.create(d)
    },
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['packages'] }); void qc.invalidateQueries({ queryKey: ['package-options'] }); onClose() },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMsg(p.message) },
  })
  const set = <K extends keyof PackageInput>(k: K, v: PackageInput[K]) => setForm((f) => ({ ...f, [k]: v }))
  const e = (k: string) => errors[k]?.[0]
  const genders = (['male', 'female', 'mixed'] as const).filter((g) => !scoped || g === scoped || g === 'mixed')

  return (
    <Modal wide title={pkg ? t('form.title_edit') : t('form.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton></>}>
      {msg && Object.keys(errors).length === 0 && <Notice tone="error">{msg}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput label={t('form.name_ar')} dir="rtl" value={form.name_ar ?? ''} onChange={(ev) => set('name_ar', ev.target.value)} />
        <TextInput label={t('form.name_en')} dir="ltr" value={form.name_en ?? ''} onChange={(ev) => set('name_en', ev.target.value)} />
        {e('name') && <p className="text-sm text-danger sm:col-span-2">{e('name')}</p>}
        <TextArea className="sm:col-span-2" rows={2} label={t('form.description')} value={form.description ?? ''} onChange={(ev) => set('description', ev.target.value)} dir="auto" />
        <div>
          <SelectField label={t('form.gender')} value={form.gender} onChange={(ev) => set('gender', ev.target.value as PackageInput['gender'])} options={genders.map((g) => ({ value: g, label: t(`gender.${g}`) }))} />
          {form.gender === 'mixed' && <p className="mt-1 text-xs text-ink/55">{t('form.mixed_hint')}</p>}
          {e('gender') && <p className="mt-1 text-sm text-danger">{e('gender')}</p>}
        </div>
        <div className="grid grid-cols-2 gap-2">
          <TextInput label={t('form.min_age')} type="number" min={3} max={99} value={form.min_age} onChange={(ev) => set('min_age', Number(ev.target.value))} />
          <TextInput label={t('form.max_age')} type="number" min={3} max={99} value={form.max_age} onChange={(ev) => set('max_age', Number(ev.target.value))} />
          {e('max_age') && <p className="col-span-2 text-sm text-danger">{e('max_age')}</p>}
        </div>
        <TextInput label={t('form.seats')} type="number" min={1} value={form.seats} onChange={(ev) => set('seats', Number(ev.target.value))} />
        <TextInput label={t('form.price')} inputMode="decimal" dir="ltr" value={form.price} onChange={(ev) => set('price', ev.target.value)} />
        <fieldset className="sm:col-span-2">
          <legend className="mb-1.5 text-sm font-medium text-ink/75">{t('form.days')}</legend>
          <div className="flex flex-wrap gap-2">
            {WEEK_DAYS.map((d) => {
              const on = form.days.includes(d)
              return (
                <label key={d} className={`cursor-pointer rounded-xl border px-3 py-1.5 text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${on ? 'border-brand-600 bg-brand-50 font-medium text-brand-800' : 'border-ink/15 text-ink/70 hover:bg-ink/5'}`}>
                  <input type="checkbox" className="sr-only" checked={on} onChange={() => set('days', on ? form.days.filter((x) => x !== d) : [...form.days, d])} />
                  {tl(`days.${d}`)}
                </label>
              )
            })}
          </div>
        </fieldset>
        <TextInput label={t('form.start_time')} type="time" value={form.start_time} onChange={(ev) => set('start_time', ev.target.value)} />
        <div>
          <TextInput label={t('form.end_time')} type="time" value={form.end_time} onChange={(ev) => set('end_time', ev.target.value)} />
          {e('end_time') && <p className="mt-1 text-sm text-danger">{e('end_time')}</p>}
        </div>
        <TextInput label={t('form.start_date')} type="date" value={form.start_date} onChange={(ev) => set('start_date', ev.target.value)} />
        <TextInput label={t('form.end_date')} type="date" value={form.end_date ?? ''} onChange={(ev) => set('end_date', ev.target.value || null)} />
        {terms.length > 0 ? (
          <div>
            <SelectField label={t('form.term')} value={form.academic_term_id ?? ''} onChange={(ev) => set('academic_term_id', ev.target.value ? Number(ev.target.value) : null)}
              options={[{ value: '', label: t('form.no_term') }, ...terms.map((x) => ({ value: String(x.id), label: x.name }))]} />
            {e('academic_term_id') && <p className="mt-1 text-sm text-danger">{e('academic_term_id')}</p>}
          </div>
        ) : (
          <TextInput label={t('form.term')} value={form.term ?? ''} onChange={(ev) => set('term', ev.target.value)} dir="auto" />
        )}
        <TextInput label={t('form.plan_ayahs')} type="number" min={0} value={form.plan_ayahs ?? 0} onChange={(ev) => set('plan_ayahs', Number(ev.target.value))} />
        <SelectField label={t('form.direction')} value={form.memorization_direction} onChange={(ev) => set('memorization_direction', ev.target.value as PackageInput['memorization_direction'])}
          options={[{ value: 'backward', label: t('form.backward') }, { value: 'forward', label: t('form.forward') }]} />
        <SelectField label={t('form.status')} value={form.status} onChange={(ev) => set('status', ev.target.value)}
          options={['draft', 'open', 'closed'].map((s) => ({ value: s, label: t(`form.statuses.${s}`) }))} />
      </div>
    </Modal>
  )
}
