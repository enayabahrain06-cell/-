import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { publicApi, type Package, type SubmitResult } from '../../api/registration'
import { parseApiError, type FieldErrors } from '../../api/client'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { OrnamentDivider, OrnamentFrame } from '../../components/ornaments'
import { Badge, buttonClass, LoadingState, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput, SURFACE } from '../../components/ui'
import PublicLayout from '../../layouts/PublicLayout'
import { formatDate, formatMoney, formatNumber, formatTime } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'

type Step = 'who' | 'package' | 'details' | 'done'
const STEPS: Step[] = ['who', 'package', 'details', 'done']

/** Public, no login. Gender first, then only matching packages; the server re-validates age and gender. */
export default function PublicRegisterPage() {
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const settings = useQuery({ queryKey: ['public-settings'], queryFn: publicApi.settings, staleTime: 5 * 60_000 })
  const [step, setStep] = useState<Step>('who')
  const [gender, setGender] = useState<'male' | 'female' | null>(null)
  const [birth, setBirth] = useState('')
  const [pkg, setPkg] = useState<Package | null>(null)
  const [form, setForm] = useState({ full_name: '', student_phone: '', guardian_name: '', guardian_phone: '', memorization_level: 'none', locale, notes: '' })
  const [photo, setPhoto] = useState<File | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [result, setResult] = useState<SubmitResult | null>(null)
  const cameraRef = useRef<HTMLInputElement>(null)
  const uploadRef = useRef<HTMLInputElement>(null)

  const packages = useQuery({ queryKey: ['public-packages', gender, birth, locale], queryFn: () => publicApi.packages(gender as string, birth), enabled: step === 'package' && !!gender && !!birth })
  const preview = useMemo(() => (photo ? URL.createObjectURL(photo) : null), [photo])

  const submit = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('package_id', String(pkg!.id))
      fd.append('gender', gender!)
      fd.append('birth_date', birth)
      for (const [k, v] of Object.entries(form)) if (v) fd.append(k, k.endsWith('phone') ? toLatinDigits(v) : v)
      if (photo) fd.append('photo', photo)
      return publicApi.submit(fd)
    },
    onSuccess: (r) => { setResult(r); setStep('done'); window.scrollTo({ top: 0 }) },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMessage(p.message); if (p.fields.package_id || p.fields.birth_date || p.fields.gender) setStep('package') },
  })

  const err = (k: string) => errors[k]?.[0]
  const n = (v: number) => formatNumber(v, locale)
  const s = settings.data

  if (settings.isLoading) return <PublicLayout><LoadingState /></PublicLayout>
  if (s && !s.registration_open) return <PublicLayout><Notice tone="info">{t('public.closed')}</Notice></PublicLayout>

  return (
    <PublicLayout>
      <div className="space-y-5">
        <div className="text-center">
          <h1 className="font-display text-3xl text-ink sm:text-4xl">{t('public.title')}</h1>
          <p className="mt-2 text-ink/60">{t('public.subtitle')}</p>
          <OrnamentDivider align="center" className="mx-auto mt-3 text-gold-500" />
        </div>

        <ol className="flex items-center justify-center gap-2 text-sm" aria-label={t('public.title')}>
          {STEPS.map((k, i) => {
            const idx = STEPS.indexOf(step)
            return (
              <li key={k} className="flex items-center gap-2" aria-current={k === step ? 'step' : undefined}>
                <span className={`grid size-7 place-items-center rounded-full text-xs font-semibold ${i < idx ? 'bg-brand-600 text-white' : i === idx ? 'bg-gold-500 text-white' : 'bg-ink/8 text-ink/50'}`}>{n(i + 1)}</span>
                <span className={i === idx ? 'font-medium text-ink' : 'hidden text-ink/50 sm:inline'}>{t(`public.steps.${k}`)}</span>
                {i < STEPS.length - 1 && <span className="h-px w-6 bg-ink/15" aria-hidden />}
              </li>
            )
          })}
        </ol>

        {message && step !== 'done' && Object.keys(errors).length === 0 && <Notice tone="error">{message}</Notice>}

        {step === 'who' && (
          <section className={`${SURFACE} mx-auto max-w-lg space-y-5 p-6`}>
            <fieldset>
              <legend className="mb-2 text-sm font-medium text-ink/75">{t('public.gender_q')}</legend>
              <div className="grid grid-cols-2 gap-3">
                {(['male', 'female'] as const).map((g) => (
                  <label key={g} className={`cursor-pointer rounded-2xl border-2 p-4 text-center transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${gender === g ? 'border-brand-600 bg-brand-50' : 'border-ink/10 hover:border-ink/25'}`}>
                    <input type="radio" name="gender" className="sr-only" checked={gender === g} onChange={() => setGender(g)} />
                    <span className="mt-1 block font-semibold text-ink">{g === 'male' ? t('public.boy') : t('public.girl')}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <TextInput label={t('public.birth_date')} type="date" value={birth} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setBirth(e.target.value)} />
            <PrimaryButton className="w-full" disabled={!gender || !birth} onClick={() => { setPkg(null); setStep('package') }}>{t('public.next')}</PrimaryButton>
            <p className="text-center text-sm"><Link to="/login" className="text-brand-700 hover:underline">{t('public.have_account')}</Link> · <Link to="/track" className="text-brand-700 hover:underline">{t('public.track_link')}</Link></p>
          </section>
        )}

        {step === 'package' && (
          <section className="space-y-4">
            <h2 className="text-lg font-semibold text-ink">{t('public.choose_package')}</h2>
            {err('package_id') && <Notice tone="error">{err('package_id')}</Notice>}
            {packages.isLoading ? <LoadingState /> : (packages.data ?? []).length === 0 ? <Notice tone="info">{t('public.no_packages')}</Notice> : (
              <ul className="grid gap-3 *:min-w-0 sm:grid-cols-2">
                {packages.data!.map((p) => {
                  const suit = p.suitability
                  const locked = suit && !suit.suitable
                  const selected = pkg?.id === p.id
                  return (
                    <li key={p.id}>
                      <button type="button" disabled={!!locked} onClick={() => setPkg(p)} aria-pressed={selected}
                        className={`h-full w-full rounded-2xl border-2 bg-white p-4 text-start shadow-sm transition ${locked ? 'cursor-not-allowed border-ink/8 opacity-60' : selected ? 'border-brand-600 ring-4 ring-brand-100' : 'border-ink/8 hover:border-brand-500/40'}`}>
                        <div className="flex items-start justify-between gap-2">
                          <span dir="auto" className="font-semibold text-ink">{p.name}</span>
                          {locked ? <Icon name="alert" className="size-5 text-ink/40" /> : selected ? <Icon name="check" className="size-5 text-brand-600" /> : null}
                        </div>
                        <p className="mt-1 text-sm text-ink/60">{t('public.ages', { min: n(p.min_age), max: n(p.max_age) })}{p.gender === 'mixed' && <> · {t('public.early_years')}</>}</p>
                        <p className="mt-1 text-sm text-ink/60">{p.days.length} × {formatTime(p.start_time, locale)}–{formatTime(p.end_time, locale)} · {formatDate(p.start_date, locale, { day: 'numeric', month: 'short' })}</p>
                        <p className="mt-2 text-sm font-medium text-ink">{p.price_fils > 0 ? t('public.price', { price: formatMoney(p.price_fils, locale) }) : t('public.free')}</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                          {locked && suit.reason && <Badge tone="muted">{t(`public.locked.${suit.reason}`, { min: n(p.min_age), max: n(p.max_age) })}</Badge>}
                          {!locked && suit && <Badge tone="brand">{t('public.age_at_start', { age: n(suit.age_at_start) })}</Badge>}
                          {!locked && p.is_full ? <Badge tone="gold">{t('public.full_waitlist')}</Badge> : !locked && <Badge tone="info">{t('public.seats_left', { n: n(p.seats_left) })}</Badge>}
                        </div>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
            <div className="flex justify-between gap-3">
              <SecondaryButton onClick={() => setStep('who')}>{t('public.back')}</SecondaryButton>
              <PrimaryButton disabled={!pkg} onClick={() => setStep('details')}>{t('public.next')}</PrimaryButton>
            </div>
          </section>
        )}

        {step === 'details' && pkg && (
          <form className={`${SURFACE} mx-auto max-w-2xl space-y-4 p-6`} onSubmit={(e) => { e.preventDefault(); submit.mutate() }}>
            <p className="text-sm text-ink/60"><span dir="auto" className="font-medium text-ink">{pkg.name}</span></p>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field err={err('full_name')}><TextInput label={t('public.full_name')} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} required minLength={3} dir="auto" autoComplete="name" /></Field>
              <Field err={err('memorization_level')}><SelectField label={t('public.level')} value={form.memorization_level} onChange={(e) => setForm({ ...form, memorization_level: e.target.value })} options={s?.memorization_levels ?? []} /></Field>
              <Field err={err('guardian_name')}><TextInput label={t('public.guardian_name')} value={form.guardian_name} onChange={(e) => setForm({ ...form, guardian_name: e.target.value })} required minLength={3} dir="auto" /></Field>
              <Field err={err('guardian_phone')} hint={t('public.phone_hint')}><TextInput label={t('public.guardian_phone')} type="tel" inputMode="tel" dir="ltr" placeholder="3xxxxxxx" value={form.guardian_phone} onChange={(e) => setForm({ ...form, guardian_phone: e.target.value })} required /></Field>
              <Field err={err('student_phone')}><TextInput label={t('public.student_phone')} type="tel" inputMode="tel" dir="ltr" value={form.student_phone} onChange={(e) => setForm({ ...form, student_phone: e.target.value })} /></Field>
              <SelectField label={t('public.language')} value={form.locale} onChange={(e) => setForm({ ...form, locale: e.target.value })} options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
            </div>
            <TextArea label={t('public.notes')} rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} dir="auto" />

            <div className="rounded-xl border border-dashed border-ink/20 p-4">
              <p className="text-sm font-medium text-ink/75">{t('public.photo')} <span className="text-ink/50">{s?.photo_required ? t('public.photo_required') : t('public.photo_optional')}</span></p>
              <p className="text-xs text-ink/50">{t('public.photo_hint')}</p>
              <div className="mt-3 flex flex-wrap items-center gap-3">
                {preview && <img src={preview} alt="" className="size-20 rounded-full object-cover ring-2 ring-white shadow" />}
                <SecondaryButton onClick={() => cameraRef.current?.click()}><Icon name="camera" className="size-4" />{t('public.take_photo')}</SecondaryButton>
                <SecondaryButton onClick={() => uploadRef.current?.click()}>{t('public.upload_photo')}</SecondaryButton>
                {photo && <button type="button" className="text-sm text-danger hover:underline" onClick={() => setPhoto(null)}>{t('public.remove_photo')}</button>}
                <input ref={cameraRef} type="file" accept="image/*" capture="user" className="sr-only" tabIndex={-1} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                <input ref={uploadRef} type="file" accept="image/jpeg,image/png,image/heic,image/heif" className="sr-only" tabIndex={-1} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
              </div>
              {err('photo') && <p className="mt-2 text-sm text-danger">{err('photo')}</p>}
            </div>

            <div className="flex justify-between gap-3">
              <SecondaryButton onClick={() => setStep('package')}>{t('public.back')}</SecondaryButton>
              <PrimaryButton type="submit" loading={submit.isPending} disabled={!!s?.photo_required && !photo}>{submit.isPending ? t('public.submitting') : t('public.submit')}</PrimaryButton>
            </div>
          </form>
        )}

        {step === 'done' && result && (
          <OrnamentFrame className="mx-auto max-w-lg text-gold-500">
            <div className="space-y-3 p-6 text-center">
              <Icon name="check" className="mx-auto size-10 text-brand-600" />
              <h2 className="font-display text-2xl text-ink">{t('public.done_title')}</h2>
              <p className="text-ink/75">{t('public.done_body', { no: result.request_no })}</p>
              {result.waitlist_position && <p className="text-gold-700">{t('public.done_waitlist', { n: n(result.waitlist_position) })}</p>}
              <p className="font-mono text-2xl tracking-widest text-ink" dir="ltr">{result.request_no}</p>
              <div className="flex flex-wrap justify-center gap-2 pt-2">
                <Link to={`/track/${result.request_no}`} className={buttonClass()}>{t('public.track_link')}</Link>
                <SecondaryButton onClick={() => { setStep('who'); setResult(null); setPkg(null); setPhoto(null); setForm({ ...form, full_name: '', notes: '' }) }}>{t('public.new_request')}</SecondaryButton>
              </div>
            </div>
          </OrnamentFrame>
        )}
      </div>
    </PublicLayout>
  )
}

function Field({ children, err, hint }: { children: React.ReactNode; err?: string; hint?: string }) {
  return (
    <div>
      {children}
      {hint && !err && <p className="mt-1 text-xs text-ink/50">{hint}</p>}
      {err && <p className="mt-1 text-sm text-danger">{err}</p>}
    </div>
  )
}
