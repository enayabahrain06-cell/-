import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError, type FieldErrors } from '../../api/client'
import { STAFF_ROLES, usersApi, type AdminUser, type Gender, type Track, type UserPayload } from '../../api/users'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextInput } from '../../components/ui'
import { toLatinDigits } from '../../lib/phone'

function FieldError({ errors, name }: { errors: FieldErrors; name: string }) {
  const msg = errors[name]?.[0]
  return msg ? <p className="mt-1 text-xs text-danger">{msg}</p> : null
}

/**
 * Create or edit a staff user. Students and guardians are listed too, but their accounts come from
 * registration: for them only name, phone, email, gender, language and password can change here.
 */
export default function UserDialog({ user, onClose, onSaved }: { user: AdminUser | null; onClose: () => void; onSaved: (msg: string) => void }) {
  const { t } = useTranslation('users')
  const qc = useQueryClient()
  const isNew = user === null
  const staffAccount = isNew || user.roles.some((r) => (STAFF_ROLES as readonly string[]).includes(r))
  const [form, setForm] = useState({
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    email: user?.email ?? '',
    password: '',
    gender: (user?.gender ?? '') as Gender | '',
    locale: (user?.locale ?? 'ar') as 'ar' | 'en',
    roles: user?.roles.filter((r) => (STAFF_ROLES as readonly string[]).includes(r)) ?? ['teacher'],
    track: (user?.track ?? 'both') as Track,
    teacherGender: (user?.teacher?.gender ?? '') as Gender | '',
    specialization: user?.teacher?.specialization ?? '',
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const isTeacher = form.roles.includes('teacher')
  const hasTrack = form.roles.includes('supervisor') || form.roles.includes('super_admin')

  const save = useMutation({
    mutationFn: () => {
      const p: UserPayload = {
        name: form.name.trim(),
        phone: toLatinDigits(form.phone.trim()),
        email: form.email.trim() || null,
        gender: form.gender || null,
        locale: form.locale,
        roles: form.roles,
      }
      if (form.password) p.password = form.password
      // A teacher-only account takes its track from the teacher's gender (server side); others choose one.
      if (hasTrack) p.track = form.track
      if (isTeacher) p.teacher = { gender: form.teacherGender || form.gender || null, specialization: form.specialization.trim() || null }
      if (!staffAccount) {
        const { roles: _roles, ...rest } = p
        return usersApi.update(user!.id, rest)
      }
      return isNew ? usersApi.create(p) : usersApi.update(user.id, p)
    },
    onSuccess: (u) => {
      void qc.invalidateQueries({ queryKey: ['admin-users'] })
      onSaved(t(isNew ? 'form.created' : 'form.updated', { name: u.name }))
    },
    onError: (e) => {
      const p = parseApiError(e)
      setErrors(p.fields)
      setMessage(Object.keys(p.fields).length ? t('form.fix_fields') : p.message)
    },
  })

  const toggleRole = (r: string) =>
    setForm((f) => ({ ...f, roles: f.roles.includes(r) ? f.roles.filter((x) => x !== r) : [...f.roles, r] }))
  const genders = [{ value: '', label: t('form.not_set') }, { value: 'male', label: t('genders.male') }, { value: 'female', label: t('genders.female') }]
  const canSave = form.name.trim() && form.phone.trim() && (!isNew || form.password.length >= 8) && (!staffAccount || form.roles.length > 0)

  return (
    <Modal wide title={isNew ? t('form.new_title') : t('form.edit_title', { name: user.name })} onClose={onClose}
      footer={<>
        <SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton>
        <PrimaryButton disabled={!canSave} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton>
      </>}>
      {message && <Notice tone="error">{message}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <TextInput label={t('form.name')} dir="auto" autoComplete="off" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} aria-invalid={!!errors.name} />
          <FieldError errors={errors} name="name" />
        </div>
        <div>
          <TextInput label={t('form.phone')} dir="ltr" inputMode="tel" placeholder="3xxxxxxx" autoComplete="off" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} aria-invalid={!!errors.phone} />
          <FieldError errors={errors} name="phone" />
        </div>
        <div>
          <TextInput label={t('form.email')} dir="ltr" type="email" autoComplete="off" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} aria-invalid={!!errors.email} />
          <FieldError errors={errors} name="email" />
        </div>
        <div>
          <TextInput label={isNew ? t('form.password') : t('form.new_password')} type="password" autoComplete="new-password" dir="ltr"
            placeholder={isNew ? t('form.password_hint') : t('form.new_password_hint')} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} aria-invalid={!!errors.password} />
          <FieldError errors={errors} name="password" />
        </div>
        <div>
          <SelectField label={t('form.gender')} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value as Gender | '' })} options={genders} />
          <FieldError errors={errors} name="gender" />
        </div>
        <SelectField label={t('form.locale')} value={form.locale} onChange={(e) => setForm({ ...form, locale: e.target.value as 'ar' | 'en' })}
          options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
      </div>

      {staffAccount ? (
        <fieldset>
          <legend className="mb-1.5 text-sm font-medium text-ink/75">{t('form.roles')}</legend>
          <div className="flex flex-wrap gap-2">
            {STAFF_ROLES.map((r) => (
              <label key={r} className={`flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm ${form.roles.includes(r) ? 'border-brand-600/40 bg-brand-50 text-brand-800' : 'border-ink/12 text-ink/75 hover:bg-ink/5'}`}>
                <input type="checkbox" className="size-4 accent-brand-600" checked={form.roles.includes(r)} onChange={() => toggleRole(r)} />
                {t(`roles.${r}`)}
              </label>
            ))}
          </div>
          <FieldError errors={errors} name="roles" />
        </fieldset>
      ) : (
        <Notice tone="info">{t('form.family_account', { roles: user!.roles.map((r) => t(`roles.${r}`, { defaultValue: r })).join('، ') })}</Notice>
      )}

      {staffAccount && hasTrack && (
        <div>
          <SelectField label={t('form.track')} value={form.track} onChange={(e) => setForm({ ...form, track: e.target.value as Track })}
            options={(['both', 'male', 'female'] as const).map((v) => ({ value: v, label: t(`tracks.${v}`) }))} />
          <p className="mt-1 text-xs text-ink/50">{t('form.track_hint')}</p>
          <FieldError errors={errors} name="track" />
        </div>
      )}

      {staffAccount && isTeacher && (
        <div className="grid gap-4 rounded-xl bg-ink/[0.03] p-3 sm:grid-cols-2">
          <div>
            <SelectField label={t('form.teacher_gender')} value={form.teacherGender} onChange={(e) => setForm({ ...form, teacherGender: e.target.value as Gender | '' })}
              options={[{ value: '', label: t('form.same_as_gender') }, ...genders.slice(1)]} />
            <FieldError errors={errors} name="teacher.gender" />
          </div>
          <div>
            <TextInput label={t('form.specialization')} dir="auto" value={form.specialization} onChange={(e) => setForm({ ...form, specialization: e.target.value })} />
            <FieldError errors={errors} name="teacher.specialization" />
          </div>
          <p className="text-xs text-ink/50 sm:col-span-2">{t('form.teacher_hint')}</p>
        </div>
      )}
    </Modal>
  )
}
