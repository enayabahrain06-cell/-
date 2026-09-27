import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { bookingsApi, hallsApi, type Hall } from '../../api/lessons'
import { parseApiError, type FieldErrors } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput } from '../../components/ui'

export function HallFormDialog({ hall, onClose }: { hall?: Hall; onClose: () => void }) {
  const { t } = useTranslation('lessons')
  const qc = useQueryClient()
  const [form, setForm] = useState<Partial<Hall>>(() => hall ?? { name: '', gender: 'shared', capacity: 20, is_active: true })
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => (hall ? hallsApi.update(hall.id, form) : hallsApi.create(form)),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['halls'] }); void qc.invalidateQueries({ queryKey: ['hall-options'] }); onClose() },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  const err = (k: string) => errors[k]?.[0]

  return (
    <Modal title={hall ? t('halls.title_edit') : t('halls.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('halls.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('halls.save')}</PrimaryButton></>}>
      <TextInput label={t('halls.name')} value={form.name ?? ''} onChange={(e) => setForm({ ...form, name: e.target.value })} dir="auto" />
      {err('name') && <p className="text-sm text-danger">{err('name')}</p>}
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('halls.gender')} value={form.gender ?? 'shared'} onChange={(e) => setForm({ ...form, gender: e.target.value as Hall['gender'] })}
          options={(['male', 'female', 'shared'] as const).map((g) => ({ value: g, label: t(`gender.${g}`) }))} />
        <TextInput label={t('halls.capacity')} type="number" min={0} value={form.capacity ?? 0} onChange={(e) => setForm({ ...form, capacity: Number(e.target.value) })} />
        <TextInput label={t('halls.code')} value={form.code ?? ''} onChange={(e) => setForm({ ...form, code: e.target.value || null })} dir="ltr" />
        <TextInput label={t('halls.map_link')} type="url" value={form.map_link ?? ''} onChange={(e) => setForm({ ...form, map_link: e.target.value || null })} dir="ltr" />
      </div>
      <p className="text-xs text-ink/55">{t('halls.shared_hint')}</p>
      {err('gender') && <Notice tone="error">{err('gender')}</Notice>}
      {err('code') && <p className="text-sm text-danger">{err('code')}</p>}
      <TextArea label={t('halls.address')} rows={2} value={form.address ?? ''} onChange={(e) => setForm({ ...form, address: e.target.value || null })} dir="auto" />
      <TextArea label={t('halls.notes')} rows={2} value={form.notes ?? ''} onChange={(e) => setForm({ ...form, notes: e.target.value || null })} dir="auto" />
    </Modal>
  )
}

export function BookingDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation('lessons')
  const { user, hasRole } = useAuth()
  const qc = useQueryClient()
  const halls = useQuery({ queryKey: ['hall-options'], queryFn: () => hallsApi.list({ active: true }) })
  const scoped = !hasRole('super_admin') && user?.track && user.track !== 'both' ? user.track : null
  const [form, setForm] = useState<{ location_id: number; title: string; booking_date: string; start_time: string; end_time: string; gender: string }>({ location_id: 0, title: '', booking_date: new Date().toISOString().slice(0, 10), start_time: '18:00', end_time: '19:00', gender: scoped ?? 'male' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const save = useMutation({
    mutationFn: () => bookingsApi.create(form),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['bookings'] }); onClose() },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMsg(p.message) },
  })
  const err = (k: string) => errors[k]?.[0]
  const allowed = (g: string) => g === 'shared' || g === form.gender

  return (
    <Modal title={t('bookings.form_title')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('bookings.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('bookings.save')}</PrimaryButton></>}>
      {msg && Object.keys(errors).length === 0 && <Notice tone="error">{msg}</Notice>}
      <TextInput label={t('bookings.title')} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} dir="auto" />
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('bookings.gender')} value={form.gender} disabled={!!scoped} onChange={(e) => setForm({ ...form, gender: e.target.value, location_id: 0 })}
          options={(['male', 'female'] as const).map((g) => ({ value: g, label: t(`gender.${g}`) }))} />
        <div>
          <SelectField label={t('bookings.hall')} value={form.location_id || ''} onChange={(e) => setForm({ ...form, location_id: Number(e.target.value) })}
            options={[{ value: '', label: '—' }, ...(halls.data ?? []).filter((h) => allowed(h.gender)).map((h) => ({ value: String(h.id), label: h.name }))]} />
          {err('location_id') && <p className="mt-1 text-sm text-danger">{err('location_id')}</p>}
        </div>
        <TextInput label={t('bookings.date')} type="date" value={form.booking_date} onChange={(e) => setForm({ ...form, booking_date: e.target.value })} />
        <div className="grid grid-cols-2 gap-2">
          <TextInput label={t('bookings.from')} type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} />
          <TextInput label={t('bookings.to')} type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
        </div>
      </div>
      {(err('start_time') || err('end_time') || err('booking_date')) && <Notice tone="error">{err('start_time') ?? err('end_time') ?? err('booking_date')}</Notice>}
    </Modal>
  )
}
