import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { competitionsApi, honorApi, type CompetitionDetail, type CompetitionInput, type Criterion } from '../../api/engagement'
import { hallsApi, lessonsApi, optionsApi } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import SelectField from '../../components/SelectField'
import { Modal, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput, inputClass, IconButton } from '../../components/ui'
import { formatNumber } from '../../lib/format'

const STEPS = ['basics', 'eligibility', 'criteria', 'rounds'] as const

/** "2026-09-15T05:00:00+00:00" → local Bahrain "2026-09-15T08:00" for datetime-local inputs. */
export function toLocalInput(iso?: string | null) {
  if (!iso) return ''
  const d = new Date(new Date(iso).toLocaleString('en-US', { timeZone: 'Asia/Bahrain' }))
  const p = (v: number) => String(v).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}
/** Criterion keys must match /^[a-z_]+$/ on the server. */
const criterionKey = () => 'c_' + Array.from({ length: 6 }, () => 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)]).join('')
const withOffset = (v: string) => (v && !/[+-]\d\d:\d\d$|Z$/.test(v) ? `${v}:00+03:00` : v)

export default function CompetitionForm({ competition, onClose, onSaved }: { competition?: CompetitionDetail; onClose: () => void; onSaved: (c: CompetitionDetail) => void }) {
  const { t, i18n } = useTranslation('engagement')
  const { user } = useAuth()
  const locale = i18n.language
  const defaults = useQuery({ queryKey: ['competition-defaults'], queryFn: competitionsApi.defaults, enabled: !competition })
  const trackGender = user?.track === 'female' ? 'female' : 'male'
  const [step, setStep] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [f, setF] = useState<CompetitionInput>(() => ({
    name_ar: competition?.name_ar ?? '', name_en: competition?.name_en ?? '', description: competition?.description ?? '',
    gender: competition?.gender ?? trackGender, type: competition?.type ?? 'memorization', scope: competition?.scope ?? 'authority',
    scope_lesson_id: competition?.scope_lesson_id ?? null, scope_package_id: competition?.scope_package_id ?? null,
    min_age: competition?.min_age ?? null, max_age: competition?.max_age ?? null, max_participants: competition?.max_participants ?? null,
    registration_opens_at: toLocalInput(competition?.registration_opens_at), registration_closes_at: toLocalInput(competition?.registration_closes_at),
    starts_at: toLocalInput(competition?.starts_at), ends_at: toLocalInput(competition?.ends_at),
    tie_break: competition?.tie_break ?? 'last_round', criteria: competition?.criteria ?? [],
    rounds: competition?.rounds.map((r) => ({ id: r.id, name: r.name, round_date: r.round_date, start_time: r.start_time, location_id: r.location_id })) ?? [{ name: '', round_date: '' }],
    prizes: competition?.prizes.map((p) => ({ rank: p.rank, title: p.title, badge_id: p.badge_id, points: p.points })) ?? [{ rank: 1, title: '', points: 20 }, { rank: 2, title: '', points: 10 }, { rank: 3, title: '', points: 5 }],
  }))
  const criteria: Criterion[] = f.criteria.length ? f.criteria : defaults.data?.criteria ?? []
  const set = <K extends keyof CompetitionInput>(k: K, v: CompetitionInput[K]) => setF((x) => ({ ...x, [k]: v }))
  const packages = useQuery({ queryKey: ['package-options'], queryFn: optionsApi.packages, staleTime: 300_000 })
  const lessons = useQuery({ queryKey: ['lessons', 'all-options'], queryFn: () => lessonsApi.list({ per_page: 200 }), staleTime: 300_000 })
  const halls = useQuery({ queryKey: ['halls-all'], queryFn: () => hallsApi.list(), staleTime: 300_000 })
  const badges = useQuery({ queryKey: ['honor-badges', locale], queryFn: honorApi.badges, staleTime: 300_000 })
  const weightTotal = criteria.reduce((s, c) => s + (Number(c.weight) || 0), 0)
  const both = !user?.track || user.track === 'both'

  const save = useMutation({
    mutationFn: () => {
      const body: CompetitionInput = {
        ...f, criteria,
        registration_opens_at: withOffset(f.registration_opens_at), registration_closes_at: withOffset(f.registration_closes_at),
        starts_at: withOffset(f.starts_at), ends_at: withOffset(f.ends_at),
        prizes: f.prizes.filter((p) => p.title.trim()),
      }
      return competition ? competitionsApi.update(competition.id, body) : competitionsApi.create(body)
    },
    onSuccess: onSaved,
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })

  const canNext = [
    !!f.name_ar.trim(),
    !!(f.registration_opens_at && f.registration_closes_at && f.starts_at && f.ends_at) && (f.scope !== 'circle' || !!f.scope_lesson_id) && (f.scope !== 'package' || !!f.scope_package_id),
    weightTotal === 100 && criteria.length > 0,
    f.rounds.length > 0 && f.rounds.every((r) => r.name.trim() && r.round_date),
  ]
  const num = (v: string) => (v === '' ? null : Number(v))
  const input = inputClass('sm', 'w-full')

  return (
    <Modal wide title={competition ? t('form.edit') : t('form.new')} onClose={onClose}
      footer={
        <>
          <span className="me-auto text-xs text-ink/50">{t('form.step', { n: formatNumber(step + 1, locale), t: formatNumber(STEPS.length, locale) })} · {t(`form.steps.${STEPS[step]}`)}</span>
          {step > 0 ? <SecondaryButton onClick={() => setStep(step - 1)}>{t('form.back')}</SecondaryButton> : <SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton>}
          {step < STEPS.length - 1
            ? <PrimaryButton disabled={!canNext[step]} onClick={() => setStep(step + 1)}>{t('form.next')}</PrimaryButton>
            : <PrimaryButton disabled={!canNext.every(Boolean)} loading={save.isPending} onClick={() => save.mutate()}>{t('form.save')}</PrimaryButton>}
        </>
      }>
      <ol className="flex gap-1" aria-label={t('form.step', { n: step + 1, t: STEPS.length })}>
        {STEPS.map((s, i) => (
          <li key={s} className="flex-1">
            <button type="button" onClick={() => i <= step || canNext.slice(0, i).every(Boolean) ? setStep(i) : undefined} aria-current={i === step ? 'step' : undefined}
              className={`w-full rounded-lg border-b-2 px-2 py-1 text-xs font-medium ${i === step ? 'border-brand-600 text-brand-700' : i < step ? 'border-brand-300 text-ink/60' : 'border-ink/10 text-ink/40'}`}>{t(`form.steps.${s}`)}</button>
          </li>
        ))}
      </ol>
      {error && <Notice tone="error">{error}</Notice>}

      {step === 0 && (
        <div className="grid gap-4 sm:grid-cols-2">
          <TextInput label={t('form.name_ar')} dir="rtl" value={f.name_ar} onChange={(e) => set('name_ar', e.target.value)} />
          <TextInput label={t('form.name_en')} dir="ltr" value={f.name_en ?? ''} onChange={(e) => set('name_en', e.target.value)} />
          <SelectField label={t('form.type')} value={f.type} onChange={(e) => set('type', e.target.value as CompetitionInput['type'])}
            options={(['memorization', 'tajweed', 'recitation', 'knowledge'] as const).map((v) => ({ value: v, label: t(`competitions.type.${v}`) }))} />
          <SelectField label={t('form.gender')} value={f.gender} disabled={!both || !!competition} onChange={(e) => set('gender', e.target.value as 'male' | 'female')}
            options={[{ value: 'male', label: t('display.boys') }, { value: 'female', label: t('display.girls') }]} />
          <div className="sm:col-span-2"><TextArea label={t('form.description')} rows={3} value={f.description ?? ''} onChange={(e) => set('description', e.target.value)} dir="auto" /></div>
        </div>
      )}

      {step === 1 && (
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label={t('form.scope')} value={f.scope} onChange={(e) => set('scope', e.target.value as CompetitionInput['scope'])}
            options={(['authority', 'package', 'circle'] as const).map((v) => ({ value: v, label: t(`competitions.scope.${v}`) }))} />
          {f.scope === 'circle' && <SelectField label={t('form.lesson')} value={f.scope_lesson_id ?? ''} onChange={(e) => set('scope_lesson_id', num(e.target.value))}
            options={[{ value: '', label: '—' }, ...(lessons.data?.data ?? []).filter((l) => l.gender === f.gender || l.gender === 'mixed').map((l) => ({ value: String(l.id), label: l.name }))]} />}
          {f.scope === 'package' && <SelectField label={t('form.package')} value={f.scope_package_id ?? ''} onChange={(e) => set('scope_package_id', num(e.target.value))}
            options={[{ value: '', label: '—' }, ...(packages.data ?? []).filter((p) => p.gender === f.gender || p.gender === 'mixed').map((p) => ({ value: String(p.id), label: p.name }))]} />}
          {f.scope === 'authority' && <span className="hidden sm:block" />}
          <TextInput type="number" min={3} max={99} label={t('form.min_age')} value={f.min_age ?? ''} onChange={(e) => set('min_age', num(e.target.value))} />
          <TextInput type="number" min={3} max={99} label={t('form.max_age')} value={f.max_age ?? ''} onChange={(e) => set('max_age', num(e.target.value))} />
          <TextInput type="datetime-local" label={t('form.opens')} value={f.registration_opens_at} onChange={(e) => set('registration_opens_at', e.target.value)} />
          <TextInput type="datetime-local" label={t('form.closes')} value={f.registration_closes_at} onChange={(e) => set('registration_closes_at', e.target.value)} />
          <TextInput type="datetime-local" label={t('form.starts')} value={f.starts_at} onChange={(e) => set('starts_at', e.target.value)} />
          <TextInput type="datetime-local" label={t('form.ends')} value={f.ends_at} onChange={(e) => set('ends_at', e.target.value)} />
          <TextInput type="number" min={1} label={t('form.max_participants')} value={f.max_participants ?? ''} onChange={(e) => set('max_participants', num(e.target.value))} />
        </div>
      )}

      {step === 2 && (
        <div className="space-y-3">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead className="text-xs text-ink/60"><tr><th className="p-1 text-start">{t('form.criterion')}</th><th className="p-1 text-start">EN</th><th className="w-20 p-1 text-start">{t('competitions.weight')}</th><th className="w-20 p-1 text-start">{t('competitions.max')}</th><th className="w-8" /></tr></thead>
              <tbody>
                {criteria.map((c, i) => {
                  const upd = (patch: Partial<Criterion>) => set('criteria', criteria.map((x, j) => (j === i ? { ...x, ...patch } : x)))
                  return (
                    <tr key={i}>
                      <td className="p-1"><input aria-label={t('form.criterion')} dir="rtl" className={input} value={c.name_ar} onChange={(e) => upd({ name_ar: e.target.value, key: c.key || criterionKey() })} /></td>
                      <td className="p-1"><input aria-label="EN" dir="ltr" className={input} value={c.name_en ?? ''} onChange={(e) => upd({ name_en: e.target.value })} /></td>
                      <td className="p-1"><input aria-label={t('competitions.weight')} type="number" min={1} max={100} className={`${input} tabular-nums`} value={c.weight} onChange={(e) => upd({ weight: Number(e.target.value) })} /></td>
                      <td className="p-1"><input aria-label={t('competitions.max')} type="number" min={1} max={100} className={`${input} tabular-nums`} value={c.max} onChange={(e) => upd({ max: Number(e.target.value) })} /></td>
                      <td className="p-1"><IconButton icon="close" tone="remove" label={t('form.remove')} onClick={() => set('criteria', criteria.filter((_, j) => j !== i))} /></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <SecondaryButton onClick={() => set('criteria', [...criteria, { key: criterionKey(), name_ar: '', name_en: '', weight: 10, max: 10 }])}>+ {t('form.add_criterion')}</SecondaryButton>
            <span className={`text-sm tabular-nums ${weightTotal === 100 ? 'text-brand-700' : 'text-danger'}`}>{t('form.weights_total', { n: formatNumber(weightTotal, locale) })}</span>
          </div>
          <SelectField label={t('form.tie_break')} value={f.tie_break} onChange={(e) => set('tie_break', e.target.value as CompetitionInput['tie_break'])}
            options={(['last_round', 'criterion_order', 'age_younger', 'registration_order'] as const).map((v) => ({ value: v, label: t(`competitions.tie_break.${v}`) }))} />
        </div>
      )}

      {step === 3 && (
        <div className="space-y-5">
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium text-ink/75">{t('competitions.rounds')}</legend>
            {f.rounds.map((r, i) => {
              const upd = (patch: Partial<CompetitionInput['rounds'][number]>) => set('rounds', f.rounds.map((x, j) => (j === i ? { ...x, ...patch } : x)))
              return (
                <div key={i} className="grid gap-2 rounded-xl border border-ink/10 p-2 sm:grid-cols-[1fr_9rem_7rem_1fr_auto]">
                  <input aria-label={t('form.round_name')} placeholder={t('form.round_name')} dir="auto" className={input} value={r.name} onChange={(e) => upd({ name: e.target.value })} />
                  <input aria-label={t('form.date')} type="date" className={input} value={r.round_date} onChange={(e) => upd({ round_date: e.target.value })} />
                  <input aria-label={t('form.time')} type="time" className={input} value={r.start_time ?? ''} onChange={(e) => upd({ start_time: e.target.value || null })} />
                  <select aria-label={t('form.location')} className={input} value={r.location_id ?? ''} onChange={(e) => upd({ location_id: num(e.target.value) })}>
                    <option value="">{t('form.location')}</option>
                    {(halls.data ?? []).filter((h) => h.gender === 'shared' || h.gender === f.gender).map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
                  </select>
                  <IconButton icon="close" tone="remove" label={t('form.remove')} disabled={f.rounds.length === 1} onClick={() => set('rounds', f.rounds.filter((_, j) => j !== i))} />
                </div>
              )
            })}
            <SecondaryButton onClick={() => set('rounds', [...f.rounds, { name: '', round_date: '' }])}>+ {t('form.add_round')}</SecondaryButton>
          </fieldset>
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium text-ink/75">{t('competitions.prizes')}</legend>
            {f.prizes.map((p, i) => {
              const upd = (patch: Partial<CompetitionInput['prizes'][number]>) => set('prizes', f.prizes.map((x, j) => (j === i ? { ...x, ...patch } : x)))
              return (
                <div key={i} className="grid grid-cols-[4rem_1fr] gap-2 rounded-xl border border-ink/10 p-2 sm:grid-cols-[4rem_1fr_1fr_6rem_auto]">
                  <input aria-label={t('form.prize_rank')} type="number" min={1} max={10} className={`${input} tabular-nums`} value={p.rank} onChange={(e) => upd({ rank: Number(e.target.value) })} />
                  <input aria-label={t('form.prize_title')} placeholder={t('form.prize_title')} dir="auto" className={input} value={p.title} onChange={(e) => upd({ title: e.target.value })} />
                  <select aria-label={t('form.prize_badge')} className={input} value={p.badge_id ?? ''} onChange={(e) => upd({ badge_id: num(e.target.value) })}>
                    <option value="">{t('form.prize_badge')}: {t('form.none')}</option>
                    {(badges.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                  </select>
                  <input aria-label={t('form.prize_points')} title={t('form.prize_points')} type="number" min={0} max={1000} className={`${input} tabular-nums`} value={p.points ?? 0} onChange={(e) => upd({ points: Number(e.target.value) })} />
                  <IconButton icon="close" tone="remove" label={t('form.remove')} onClick={() => set('prizes', f.prizes.filter((_, j) => j !== i))} />
                </div>
              )
            })}
            <SecondaryButton onClick={() => set('prizes', [...f.prizes, { rank: f.prizes.length + 1, title: '', points: 0 }])}>+ {t('form.add_prize')}</SecondaryButton>
          </fieldset>
        </div>
      )}
    </Modal>
  )
}
