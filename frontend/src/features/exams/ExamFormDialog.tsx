import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { examsApi, type Exam, type ExamInput } from '../../api/exams'
import { lessonsApi, optionsApi } from '../../api/lessons'
import { publicApi } from '../../api/registration'
import { parseApiError, type FieldErrors } from '../../api/client'
import SelectField from '../../components/SelectField'
import { IconButton, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, TextArea, TextInput, inputClass } from '../../components/ui'
import { formatNumber } from '../../lib/format'

/** <input type="datetime-local"> works in local time; the API accepts ISO strings (converted server-side to UTC). */
const toLocal = (iso?: string | null) => (iso ? iso.slice(0, 16) : '')

type Band = { min: number; level: string }

/** Starting bands for a new placement test; staff adjust them. */
const DEFAULT_BANDS: Band[] = [
  { min: 0, level: 'none' },
  { min: 40, level: 'juz_amma' },
  { min: 70, level: 'juz_tabarak' },
  { min: 90, level: 'five_ajza' },
]

export default function ExamFormDialog({ exam, onClose, onSaved }: { exam?: Exam; onClose: () => void; onSaved: (e: Exam) => void }) {
  const { t, i18n } = useTranslation('exams')
  const locale = i18n.language
  const qc = useQueryClient()
  const packages = useQuery({ queryKey: ['package-options'], queryFn: optionsApi.packages, staleTime: 5 * 60_000 })
  const lessons = useQuery({ queryKey: ['lessons', 'all-options'], queryFn: () => lessonsApi.list({ per_page: 200 }), staleTime: 5 * 60_000 })
  // Memorization levels are the placement scale; the public settings carry the translated options.
  const settings = useQuery({ queryKey: ['public-settings', locale], queryFn: publicApi.settings, staleTime: 5 * 60_000 })
  const levels = settings.data?.memorization_levels ?? []
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
  // Shown lowest first while editing; the server stores them highest first.
  const [bands, setBands] = useState<Band[]>(() => (exam?.level_bands?.length ? [...exam.level_bands].map((b) => ({ min: b.min, level: b.level })).sort((a, b) => a.min - b.min) : DEFAULT_BANDS))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const set = <K extends keyof ExamInput>(k: K, v: ExamInput[K]) => setForm((f) => ({ ...f, [k]: v }))
  const placement = form.type === 'placement'
  // A placement test always belongs to a package.
  const effectiveScope = placement ? 'package' : scope

  const save = useMutation({
    mutationFn: () => {
      // The inputs hold Asia/Bahrain wall-clock time (UTC+3 all year); send an explicit offset so the server stores the right UTC instant.
      const withTz = (v: string) => (v.length === 16 ? `${v}:00+03:00` : v)
      const d: ExamInput = {
        ...form,
        opens_at: withTz(form.opens_at),
        closes_at: withTz(form.closes_at),
        package_id: effectiveScope === 'package' ? form.package_id : null,
        lesson_id: effectiveScope === 'lesson' ? form.lesson_id : null,
        ...(placement ? { pass_mark: 0, level_bands: bands } : {}),
      }
      return exam ? examsApi.update(exam.id, d) : examsApi.create(d)
    },
    onSuccess: (e) => { void qc.invalidateQueries({ queryKey: ['exams'] }); void qc.invalidateQueries({ queryKey: ['exam', e.id] }); onSaved(e) },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMsg(p.message) },
  })
  const err = (k: string) => errors[k]?.[0]
  const bandError = err('level_bands') ?? Object.entries(errors).find(([k]) => k.startsWith('level_bands.'))?.[1]?.[0]

  return (
    <Modal wide title={exam ? t('form.title_edit') : t('form.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{exam ? t('form.save') : t('form.save_next')}</PrimaryButton></>}>
      {msg && Object.keys(errors).length === 0 && <Notice tone="error">{msg}</Notice>}
      <TextInput label={t('form.name')} value={form.name} onChange={(e) => set('name', e.target.value)} dir="auto" />
      {err('name') && <p className="text-sm text-danger">{err('name')}</p>}
      <div className="flex flex-wrap items-center gap-4">
        <Segmented name="exam-type" label={t('form.type')} value={form.type} onChange={(v) => set('type', v)}
          options={[{ value: 'online', label: t('type.online') }, { value: 'paper', label: t('type.paper') }, { value: 'placement', label: t('type.placement') }]} />
        {!placement && <Segmented name="exam-scope" label={t('form.scope')} value={scope} onChange={setScope} options={[{ value: 'lesson', label: t('form.lesson') }, { value: 'package', label: t('form.package') }]} />}
      </div>
      {placement && <Notice tone="info">{t('placement.form_hint')}</Notice>}
      {effectiveScope === 'lesson' ? (
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
        {!placement && (
          <div>
            <TextInput label={t('form.pass_mark')} type="number" min={0} value={form.pass_mark} onChange={(e) => set('pass_mark', Number(e.target.value))} />
            {err('pass_mark') && <p className="mt-1 text-sm text-danger">{err('pass_mark')}</p>}
          </div>
        )}
      </div>

      {placement && (
        <fieldset className="min-w-0 space-y-3 rounded-xl border border-ink/10 p-4">
          <legend className="px-1 text-sm font-semibold text-ink">{t('placement.bands')}</legend>
          <p className="text-sm text-ink/60">{t('placement.bands_hint')}</p>
          <div className="space-y-2">
            {bands.map((b, i) => (
              <div key={i} className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2 text-sm text-ink/70">
                  <span className="sr-only">{t('placement.band_min')}</span>
                  <span aria-hidden>{t('placement.from')}</span>
                  <input type="number" min={0} max={100} inputMode="numeric" aria-label={t('placement.band_min')} value={b.min}
                    onChange={(e) => setBands(bands.map((x, j) => (j === i ? { ...x, min: Number(e.target.value) } : x)))}
                    className={inputClass('md', 'w-20 text-center tabular-nums')} />
                  <span aria-hidden>%</span>
                </label>
                <SelectField className="min-w-44 flex-1" label={t('placement.band_level')} hideLabel value={b.level}
                  onChange={(e) => setBands(bands.map((x, j) => (j === i ? { ...x, level: e.target.value } : x)))}
                  options={levels.length ? levels : [{ value: b.level, label: b.level }]} />
                {bands.length > 1 && <IconButton icon="close" tone="remove" label={t('placement.remove_band')} onClick={() => setBands(bands.filter((_, j) => j !== i))} />}
              </div>
            ))}
          </div>
          {bands.length < Math.max(levels.length, 1) && (
            <button type="button" className="text-sm font-medium text-brand-700 hover:underline"
              onClick={() => setBands([...bands, { min: Math.min(100, (bands.at(-1)?.min ?? 0) + 10), level: levels.find((l) => !bands.some((x) => x.level === l.value))?.value ?? 'none' }])}>
              + {t('placement.add_band')}
            </button>
          )}
          {bandError && <p className="text-sm text-danger">{bandError}</p>}
          <BandPreview bands={bands} levels={levels} locale={locale} />
        </fieldset>
      )}

      <TextArea label={t('form.syllabus')} rows={2} value={form.syllabus ?? ''} onChange={(e) => set('syllabus', e.target.value)} dir="auto" />
      {(form.type === 'online' || placement) && (
        <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={form.randomize} onChange={(e) => set('randomize', e.target.checked)} />{t('form.randomize')}</label>
      )}
    </Modal>
  )
}

/** "0–39% → Beginner · 40–69% → Juz' Amma …", from the bands as currently typed. */
export function BandPreview({ bands, levels, locale }: { bands: Band[]; levels: { value: string; label: string }[]; locale: string }) {
  const { t } = useTranslation('exams')
  const sorted = [...bands].sort((a, b) => a.min - b.min)
  const n = (v: number) => formatNumber(v, locale)
  return (
    <ul aria-label={t('placement.preview')} className="flex flex-wrap gap-2">
      {sorted.map((b, i) => {
        const to = i < sorted.length - 1 ? sorted[i + 1].min - 1 : 100
        const label = levels.find((l) => l.value === b.level)?.label ?? b.level
        return (
          <li key={`${b.min}-${b.level}`} className="rounded-lg bg-page px-2.5 py-1 text-xs text-ink/75">
            <span className="tabular-nums">{t('placement.range', { from: n(b.min), to: n(Math.max(b.min, to)) })}</span> → <span className="font-medium text-ink">{label}</span>
          </li>
        )
      })}
    </ul>
  )
}
