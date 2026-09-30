import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { activitiesApi, type Activity, type ActivityCan, type ActivityInput, type ActivityOptions, type ActivityStatus, type ActivityType } from '../../api/activities'
import { parseApiError, type FieldErrors } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, IconButton, Modal, SecondaryButton, SURFACE, TextArea, TextInput } from '../../components/ui'
import { formatMoney, formatNumber } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { ActivityWhen, STATUS_TONE } from './shared'

const STATUSES: ActivityStatus[] = ['draft', 'open', 'closed', 'done']

type ListData = { term: { id: number; name: string }; data: Activity[]; options: ActivityOptions; can: ActivityCan }

/** البرامج / الرحلات: the term's programs or trips as cards; managers create, edit, open and close them. */
export default function ActivityList({ type, data, onOpen }: { type: ActivityType; data: ListData; onOpen: (a: Activity) => void }) {
  const { t, i18n } = useTranslation('activities')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const qc = useQueryClient()
  const canManage = data.can.manage
  const [edit, setEdit] = useState<Activity | 'new' | null>(null)
  const { notice, setNotice, remove } = useRemove(activitiesApi.remove, [['activities', type]], t(`delete_confirm.${type}`))
  const status = useMutation({
    mutationFn: ({ id, s }: { id: number; s: ActivityStatus }) => activitiesApi.update(id, { status: s }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['activities', type] }) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  return (
    <div className="space-y-4">
      <Toolbar label={canManage ? t(`new.${type}`) : undefined} onAdd={canManage ? () => setEdit('new') : undefined} notice={notice} />
      {data.data.length === 0 ? <EmptyCard icon={type === 'trip' ? 'pin' : 'trophy'} title={t(`empty.${type}`)} /> : (
        <div className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {data.data.map((a) => (
            <article key={a.id} className={`${SURFACE} flex flex-col gap-3 p-4`}>
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                  <h3 dir="auto" className="font-semibold text-ink">{a.name}</h3>
                  <p className="text-sm text-ink/60"><ActivityWhen a={a} /></p>
                </div>
                {canManage && (
                  <div className="flex shrink-0">
                    <IconButton icon="edit" label={t('edit')} onClick={() => setEdit(a)} />
                    {a.registered_count + a.waitlist_count === 0 && <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(a.id)} />}
                  </div>
                )}
              </div>
              <div className="flex flex-wrap gap-1.5">
                <Badge tone={STATUS_TONE[a.status]}>{t(`status.${a.status}`)}</Badge>
                {a.gender && <Badge tone="info">{t(`gender.${a.gender}`)}</Badge>}
                {a.level && <Badge>{a.level.name}</Badge>}
                {(a.min_age !== null || a.max_age !== null) && <Badge>{t('age_badge', { min: a.min_age ?? '—', max: a.max_age ?? '—' })}</Badge>}
                {a.has_book && <Badge tone="gold">{t('has_book')}</Badge>}
              </div>
              <dl className="grid grid-cols-3 gap-2 text-sm">
                <div><dt className="text-xs text-ink/55">{t('fields.price')}</dt><dd className="tabular-nums">{a.price_fils > 0 ? formatMoney(a.price_fils, locale) : t('free')}</dd></div>
                <div><dt className="text-xs text-ink/55">{t('registered')}</dt><dd className="tabular-nums">{a.seats ? t('of_seats', { count: n(a.registered_count), seats: n(a.seats) }) : n(a.registered_count)}</dd></div>
                <div><dt className="text-xs text-ink/55">{t('waitlist')}</dt><dd className="tabular-nums">{n(a.waitlist_count)}</dd></div>
              </dl>
              {(a.location || a.place) && <p className="text-sm text-ink/65"><bdi>{a.location?.name ?? a.place}</bdi></p>}
              {a.description && <p dir="auto" className="line-clamp-3 text-sm text-ink/60">{a.description}</p>}
              <div className="mt-auto flex flex-wrap items-end gap-2 border-t border-ink/6 pt-3">
                <SecondaryButton className="py-1.5" onClick={() => onOpen(a)}>{t('open_screen')}</SecondaryButton>
                {canManage && (
                  <SelectField label={t('fields.status')} hideLabel className="ms-auto w-36" value={a.status} disabled={status.isPending}
                    onChange={(e) => status.mutate({ id: a.id, s: e.target.value as ActivityStatus })} options={STATUSES.map((s) => ({ value: s, label: t(`status.${s}`) }))} />
                )}
              </div>
            </article>
          ))}
        </div>
      )}
      {edit && <ActivityDialog type={type} row={edit === 'new' ? undefined : edit} termId={data.term.id} options={data.options}
        onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />}
    </div>
  )
}

const money = (fils: number | undefined) => (fils ? (fils / 1000).toFixed(3) : '')
const toFils = (v: string) => { const x = Number(toLatinDigits(v || '0')); return Number.isFinite(x) ? Math.round(x * 1000) : -1 }
const toInt = (v: string) => (v.trim() === '' ? null : Number(toLatinDigits(v)))

function ActivityDialog({ type, row, termId, options, onClose, onSaved }: { type: ActivityType; row?: Activity; termId: number; options: ActivityOptions; onClose: () => void; onSaved: (m: string) => void }) {
  const { t } = useTranslation('activities')
  const qc = useQueryClient()
  const [f, setF] = useState({
    name_ar: row?.name_ar ?? '', name_en: row?.name_en ?? '', description: row?.description ?? '',
    starts_on: row?.starts_on ?? '', ends_on: row?.ends_on ?? '', start_time: row?.start_time ?? '', end_time: row?.end_time ?? '',
    location_id: row?.location?.id ? String(row.location.id) : '', place: row?.place ?? '',
    seats: row?.seats ? String(row.seats) : '', price: money(row?.price_fils), gender: row?.gender ?? '',
    min_age: row?.min_age !== null && row?.min_age !== undefined ? String(row.min_age) : '', max_age: row?.max_age !== null && row?.max_age !== undefined ? String(row.max_age) : '',
    level_id: row?.level?.id ? String(row.level.id) : '', has_book: row?.has_book ?? false, book_title: row?.book_title ?? '', book_price: money(row?.book_price_fils),
    status: row?.status ?? 'draft' as ActivityStatus,
  })
  const set = (patch: Partial<typeof f>) => setF({ ...f, ...patch })
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d: ActivityInput = {
        name_ar: f.name_ar, name_en: f.name_en || null, description: f.description || null,
        starts_on: f.starts_on, ends_on: f.ends_on || null, start_time: f.start_time || null, end_time: f.end_time || null,
        location_id: f.location_id ? Number(f.location_id) : null, place: f.place || null, seats: toInt(f.seats), price_fils: toFils(f.price),
        gender: f.gender || null, min_age: toInt(f.min_age), max_age: toInt(f.max_age), level_id: f.level_id ? Number(f.level_id) : null,
        has_book: type === 'program' && f.has_book, book_title: f.has_book ? f.book_title || null : null, book_price_fils: f.has_book ? toFils(f.book_price) : 0, status: f.status,
      }
      return row ? activitiesApi.update(row.id, d) : activitiesApi.create({ ...d, type, academic_term_id: termId })
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['activities', type] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  const e = (k: string) => errors[k]?.[0]

  return (
    <Modal wide title={row ? t(`title_edit.${type}`) : t(`title_new.${type}`)} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={e('name_ar')}><TextInput label={t('fields.name_ar')} dir="rtl" value={f.name_ar} onChange={(x) => set({ name_ar: x.target.value })} /></Field>
        <Field error={e('name_en')}><TextInput label={t('fields.name_en')} dir="ltr" value={f.name_en} onChange={(x) => set({ name_en: x.target.value })} /></Field>
        <Field error={e('starts_on')}><TextInput label={t('fields.starts_on')} type="date" value={f.starts_on} onChange={(x) => set({ starts_on: x.target.value })} /></Field>
        <Field error={e('ends_on')}><TextInput label={t('fields.ends_on')} type="date" value={f.ends_on} onChange={(x) => set({ ends_on: x.target.value })} /></Field>
        <Field error={e('start_time')}><TextInput label={t('fields.start_time')} type="time" value={f.start_time} onChange={(x) => set({ start_time: x.target.value })} /></Field>
        <Field error={e('end_time')}><TextInput label={t('fields.end_time')} type="time" value={f.end_time} onChange={(x) => set({ end_time: x.target.value })} /></Field>
        {type === 'program' ? (
          <SelectField label={t('fields.room')} error={e('location_id')} value={f.location_id} onChange={(x) => set({ location_id: x.target.value })}
            options={[{ value: '', label: t('no_room') }, ...options.rooms.map((r) => ({ value: String(r.id), label: r.name }))]} />
        ) : (
          <Field error={e('place')}><TextInput label={t('fields.place')} dir="auto" value={f.place} onChange={(x) => set({ place: x.target.value })} /></Field>
        )}
        <Field error={e('seats')}><TextInput label={t('fields.seats')} inputMode="numeric" dir="ltr" placeholder={t('unlimited')} value={f.seats} onChange={(x) => set({ seats: x.target.value })} /></Field>
        <Field error={e('price_fils')}><TextInput label={t('fields.price')} inputMode="decimal" dir="ltr" placeholder="0.000" value={f.price} onChange={(x) => set({ price: x.target.value })} /></Field>
        <SelectField label={t('fields.gender')} value={f.gender} onChange={(x) => set({ gender: x.target.value })}
          options={[{ value: '', label: t('gender.any') }, ...(['male', 'female', 'mixed'] as const).map((g) => ({ value: g, label: t(`gender.${g}`) }))]} />
        <Field error={e('min_age')}><TextInput label={t('fields.min_age')} inputMode="numeric" dir="ltr" value={f.min_age} onChange={(x) => set({ min_age: x.target.value })} /></Field>
        <Field error={e('max_age')}><TextInput label={t('fields.max_age')} inputMode="numeric" dir="ltr" value={f.max_age} onChange={(x) => set({ max_age: x.target.value })} /></Field>
        <SelectField label={t('fields.level')} value={f.level_id} onChange={(x) => set({ level_id: x.target.value })}
          options={[{ value: '', label: t('all_levels') }, ...options.levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
        <SelectField label={t('fields.status')} value={f.status} onChange={(x) => set({ status: x.target.value as ActivityStatus })} options={STATUSES.map((s) => ({ value: s, label: t(`status.${s}`) }))} />
      </div>
      <TextArea label={t('fields.description')} rows={2} dir="auto" value={f.description} onChange={(x) => set({ description: x.target.value })} />
      {type === 'program' && (
        <>
          <label className="flex items-center gap-2 text-sm text-ink/80">
            <input type="checkbox" className="size-4 accent-brand-700" checked={f.has_book} onChange={(x) => set({ has_book: x.target.checked })} />
            {t('fields.has_book')}
          </label>
          {f.has_book && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field error={e('book_title')}><TextInput label={t('fields.book_title')} dir="auto" value={f.book_title} onChange={(x) => set({ book_title: x.target.value })} /></Field>
              <Field error={e('book_price_fils')}><TextInput label={t('fields.book_price')} inputMode="decimal" dir="ltr" placeholder="0.000" value={f.book_price} onChange={(x) => set({ book_price: x.target.value })} /></Field>
            </div>
          )}
        </>
      )}
      <p className="text-xs text-ink/50">{t('fee_hint')}</p>
    </Modal>
  )
}
