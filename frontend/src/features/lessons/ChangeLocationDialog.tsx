import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { hallsApi, lessonsApi, type Lesson } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import { Modal, Notice, PrimaryButton, SecondaryButton, Segmented, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'

/**
 * One day (override) or all upcoming sessions. Only halls free at the circle's time and serving its
 * gender are offered; the server re-checks conflicts and gender before saving.
 */
export default function ChangeLocationDialog({ lesson, onClose, onDone }: { lesson: Lesson; onClose: () => void; onDone: (msg: string) => void }) {
  const { t, i18n } = useTranslation('lessons')
  const qc = useQueryClient()
  const [mode, setMode] = useState<'one_day' | 'all_upcoming'>('one_day')
  const [date, setDate] = useState(lesson.next_sessions?.[0]?.session_date ?? new Date().toISOString().slice(0, 10))
  const [locationId, setLocationId] = useState<number | null>(null)
  const [notify, setNotify] = useState(true)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const free = useQuery({
    queryKey: ['free-halls', lesson.id, mode, date],
    queryFn: () => hallsApi.free({ date, start_time: lesson.start_time, end_time: lesson.end_time, ignore_lesson_id: lesson.id, gender: lesson.gender === 'mixed' ? 'female' : lesson.gender ?? undefined }),
  })
  const options = (free.data ?? []).filter((h) => h.id !== lesson.location_id)

  const save = useMutation({
    mutationFn: () => lessonsApi.changeLocation(lesson.id, { mode, date: mode === 'one_day' ? date : null, location_id: locationId as number, notify, reason: reason || null }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['lesson', lesson.id] })
      void qc.invalidateQueries({ queryKey: ['dashboard'] })
      onDone(`${t('change.done')}${notify ? ` ${t('change.notified', { n: formatNumber(r.notified, i18n.language) })}` : ''}`)
    },
    onError: (e) => setError(parseApiError(e).message),
  })

  return (
    <Modal title={t('change.title')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!locationId} loading={save.isPending} onClick={() => save.mutate()}>{t('change.save')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <Segmented name="change-mode" label={t('change.mode')} value={mode}
        options={[{ value: 'one_day', label: t('change.one_day') }, { value: 'all_upcoming', label: t('change.all_upcoming') }]}
        onChange={(v) => { setMode(v); setLocationId(null) }} />
      <TextInput label={mode === 'one_day' ? t('change.date') : `${t('change.date')} (${t('change.all_upcoming')})`} type="date" value={date}
        min={new Date().toISOString().slice(0, 10)} onChange={(e) => { setDate(e.target.value); setLocationId(null) }} />
      <fieldset>
        <legend className="mb-1.5 text-sm font-medium text-ink/75">{t('change.free_halls')}</legend>
        {free.isLoading ? <p className="text-sm text-ink/55">{t('change.loading_free')}</p> : options.length === 0 ? <p className="text-sm text-ink/55">{t('change.none_free')}</p> : (
          <div className="grid gap-2 sm:grid-cols-2">
            {options.map((h) => (
              <label key={h.id} className={`flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${locationId === h.id ? 'border-brand-600 bg-brand-50' : 'border-ink/12 hover:bg-ink/5'}`}>
                <input type="radio" name="free-hall" className="mt-1 accent-brand-600" checked={locationId === h.id} onChange={() => setLocationId(h.id)} />
                <span>
                  <span dir="auto" className="block font-medium text-ink">{h.name}</span>
                  <span className="block text-xs text-ink/55">{t(`gender.${h.gender}`)} · {t('halls.capacity')}: {formatNumber(h.capacity, i18n.language)}</span>
                </span>
              </label>
            ))}
          </div>
        )}
      </fieldset>
      <label className="flex items-start gap-2 text-sm text-ink/80">
        <input type="checkbox" className="mt-0.5 size-4 accent-brand-600" checked={notify} onChange={(e) => setNotify(e.target.checked)} />
        {t('change.notify')}
      </label>
      <TextInput label={t('change.reason')} value={reason} onChange={(e) => setReason(e.target.value)} dir="auto" />
    </Modal>
  )
}
