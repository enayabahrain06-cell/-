import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { publicApi, type Package, type PlacementResult, type SubmitResult } from '../../api/registration'
import { parseApiError, type FieldErrors } from '../../api/client'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { OrnamentDivider, OrnamentFrame } from '../../components/ornaments'
import { Badge, buttonClass, LoadingState, Notice, PrimaryButton, SecondaryButton, TextArea, TextInput, SURFACE } from '../../components/ui'
import PublicLayout from '../../layouts/PublicLayout'
import { formatDate, formatMoney, formatNumber, formatTime } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'
import { PlacementResultView, PlacementTest } from './PlacementStep'
import { clearPlacementToken } from './placementToken'
import { Field, FormSection, StepBar, StepFooter, StepHeading } from './StepParts'

type Step = 'who' | 'package' | 'details' | 'placement' | 'result' | 'confirm' | 'done'
const BASE_STEPS: Step[] = ['who', 'package', 'details', 'done']
/** A package with an open placement test adds the test, its result and a review step before sending. */
const PLACEMENT_STEPS: Step[] = ['who', 'package', 'details', 'placement', 'result', 'confirm', 'done']

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
  const [placement, setPlacement] = useState<{ token: string; result: PlacementResult } | null>(null)
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
      if (placement) fd.append('placement_token', placement.token)
      return publicApi.submit(fd)
    },
    onSuccess: (r) => {
      if (pkg) clearPlacementToken(pkg.id)
      setPlacement(null)
      setResult(r); setStep('done'); window.scrollTo({ top: 0 })
    },
    onError: (e) => {
      const p = parseApiError(e); setErrors(p.fields); setMessage(p.message)
      if (p.fields.package_id || p.fields.birth_date || p.fields.gender) setStep('package')
      else if (p.fields.placement_token) setStep(pkg?.placement ? 'confirm' : 'details')
      else if (Object.keys(p.fields).length > 0) setStep('details')
    },
  })

  const go = (next: Step) => { setStep(next); window.scrollTo({ top: 0 }) }
  const onPlacementFinished = useCallback((token: string, r: PlacementResult) => { setPlacement({ token, result: r }); setStep('result'); window.scrollTo({ top: 0 }) }, [])
  const retakePlacement = () => { if (pkg) clearPlacementToken(pkg.id); setPlacement(null); setErrors({}); setMessage(null); go('placement') }

  const err = (k: string) => errors[k]?.[0]
  const n = (v: number) => formatNumber(v, locale)
  const s = settings.data
  const STEPS = pkg?.placement ? PLACEMENT_STEPS : BASE_STEPS
  const idx = STEPS.indexOf(step)
  const formSteps = STEPS.length - 1
  const stepLabel = (k: Step) => t('public.step_of', { n: n(STEPS.indexOf(k) + 1), total: n(formSteps) })
  const mobileHeader = { title: t('mobile.register_title'), back: '/login' }

  if (settings.isLoading) return <PublicLayout mobile={mobileHeader}><LoadingState /></PublicLayout>
  if (s && !s.registration_open) return <PublicLayout mobile={mobileHeader}><Notice tone="info">{t('public.closed')}</Notice></PublicLayout>

  return (
    <PublicLayout mobile={mobileHeader}>
      <div className="space-y-6">
        <div className="text-center max-lg:hidden">
          <h1 className="font-display text-3xl text-ink sm:text-4xl">{t('public.title')}</h1>
          <p className="mx-auto mt-2 max-w-xl text-ink/60">{t('public.subtitle')}</p>
          <OrnamentDivider align="center" className="mx-auto mt-3 text-gold-500" />
        </div>

        {/* Stepper: numbered circles joined by a line that fills as the steps complete. */}
        <ol className="mx-auto flex max-w-2xl items-start max-lg:hidden" aria-label={t('public.title')}>
          {STEPS.map((k, i) => (
            <li key={k} className="flex flex-1 flex-col items-center gap-1.5 text-center" aria-current={k === step ? 'step' : undefined}>
              <div className="flex w-full items-center">
                <span className={`h-0.5 flex-1 ${i === 0 ? 'invisible' : i <= idx ? 'bg-brand-600' : 'bg-ink/10'}`} aria-hidden />
                <span className={`grid size-8 shrink-0 place-items-center rounded-full text-sm font-semibold transition ${
                  i < idx || step === 'done' ? 'bg-brand-600 text-white' : i === idx ? 'bg-gold-500 text-white ring-4 ring-gold-500/20' : 'bg-ink/8 text-ink/50'
                }`}>
                  {i < idx || step === 'done' ? <Icon name="check" className="size-4" /> : n(i + 1)}
                </span>
                <span className={`h-0.5 flex-1 ${i === STEPS.length - 1 ? 'invisible' : i < idx ? 'bg-brand-600' : 'bg-ink/10'}`} aria-hidden />
              </div>
              <span className={`px-0.5 text-[0.6875rem] leading-tight sm:text-sm ${i === idx ? 'font-semibold text-ink' : 'text-ink/50'}`}>{t(`public.steps.${k}`)}</span>
            </li>
          ))}
        </ol>

        {/* Below lg: 4px step segments and one line "step n of N · name" (spec §6.2). */}
        {step !== 'done' && (
          <div className="space-y-2 lg:hidden">
            <div aria-hidden className="flex gap-1">
              {STEPS.slice(0, formSteps).map((k, i) => (
                <span key={k} className={`h-1 flex-1 rounded-full ${i < idx ? 'bg-brand-700' : i === idx ? 'bg-gold-500' : 'bg-ink/10'}`} />
              ))}
            </div>
            <p className="text-[13px] text-ink/65" aria-current="step"><span className="font-semibold tabular-nums text-ink">{stepLabel(step)}</span> · {t(`public.steps.${step}`)}</p>
          </div>
        )}

        {message && step !== 'done' && Object.keys(errors).length === 0 && <div className="mx-auto max-w-2xl"><Notice tone="error">{message}</Notice></div>}

        {step === 'who' && (
          <div className="mx-auto max-w-lg space-y-4">
            <section aria-labelledby="step-title" className={`${SURFACE} space-y-5 p-6 max-lg:p-4`}>
              <StepHeading step={stepLabel('who')} title={t('public.who_title')} help={t('public.who_help')} />
              <fieldset>
                <legend className="mb-2 text-sm font-medium text-ink/75">{t('public.gender_q')}</legend>
                <div className="grid grid-cols-2 gap-3">
                  {(['male', 'female'] as const).map((g) => (
                    <label key={g} className={`flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 p-4 text-center transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${gender === g ? 'border-brand-600 bg-brand-50' : 'border-ink/10 hover:border-ink/25'}`}>
                      <input type="radio" name="gender" className="sr-only" checked={gender === g} onChange={() => setGender(g)} />
                      <span className={`grid size-11 place-items-center rounded-full ${g === 'female' ? 'bg-gold-500/15 text-gold-700' : 'bg-brand-100 text-brand-700'}`}>
                        <Icon name={gender === g ? 'check' : 'students'} className="size-5" />
                      </span>
                      <span className="font-semibold text-ink">{g === 'male' ? t('public.boy') : t('public.girl')}</span>
                    </label>
                  ))}
                </div>
              </fieldset>
              <TextInput label={t('public.birth_date')} type="date" value={birth} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setBirth(e.target.value)} />
              <StepBar>
                <PrimaryButton className="w-full" disabled={!gender || !birth} onClick={() => { setPkg(null); setStep('package') }}>
                  {t('public.next')}<Icon name="chevron" className="size-4 rtl:rotate-180" />
                </PrimaryButton>
              </StepBar>
            </section>
            <p className="flex flex-wrap justify-center gap-x-4 gap-y-1 text-sm max-lg:gap-y-0 max-lg:text-[15px]">
              <Link to="/login" className="text-brand-700 hover:underline max-lg:inline-flex max-lg:min-h-11 max-lg:items-center">{t('public.have_account')}</Link>
              <Link to="/track" className="text-brand-700 hover:underline max-lg:inline-flex max-lg:min-h-11 max-lg:items-center">{t('public.track_link')}</Link>
            </p>
          </div>
        )}

        {step === 'package' && (
          <section aria-labelledby="step-title" className={`${SURFACE} mx-auto max-w-2xl space-y-5 p-6 max-lg:p-4`}>
            <StepHeading step={stepLabel('package')} title={t('public.choose_package')} help={t('public.package_help')} />
            {err('package_id') && <Notice tone="error">{err('package_id')}</Notice>}
            {packages.isLoading ? <LoadingState /> : (packages.data ?? []).length === 0 ? <Notice tone="info">{t('public.no_packages')}</Notice> : (
              <ul className="grid gap-3 *:min-w-0 sm:grid-cols-2">
                {packages.data!.map((p) => {
                  const suit = p.suitability
                  const locked = suit && !suit.suitable
                  const selected = pkg?.id === p.id
                  return (
                    <li key={p.id}>
                      <button type="button" disabled={!!locked} onClick={() => { if (pkg?.id !== p.id) setPlacement(null); setPkg(p) }} aria-pressed={selected}
                        className={`flex h-full w-full flex-col rounded-2xl border-2 bg-white p-4 text-start transition ${locked ? 'cursor-not-allowed border-ink/8 bg-page/50 opacity-60' : selected ? 'border-brand-600 bg-brand-50/40 ring-4 ring-brand-100' : 'border-ink/10 hover:border-brand-500/40'}`}>
                        <div className="flex items-start justify-between gap-2">
                          <span dir="auto" className="font-semibold text-ink">{p.name}</span>
                          {locked ? <Icon name="alert" className="size-5 shrink-0 text-ink/40" /> : selected ? <Icon name="check" className="size-5 shrink-0 text-brand-600" /> : null}
                        </div>
                        <ul className="mt-2 space-y-1 text-sm text-ink/60">
                          <li className="flex items-center gap-1.5"><Icon name="students" className="size-4 shrink-0 text-ink/40" />{t('public.ages', { min: n(p.min_age), max: n(p.max_age) })}{p.gender === 'mixed' && <> · {t('public.early_years')}</>}</li>
                          <li className="flex items-center gap-1.5"><Icon name="clock" className="size-4 shrink-0 text-ink/40" /><span className="tabular-nums">{n(p.days.length)} × {formatTime(p.start_time, locale)}–{formatTime(p.end_time, locale)} · {formatDate(p.start_date, locale, { day: 'numeric', month: 'short' })}</span></li>
                        </ul>
                        <p className="mt-3 text-base font-semibold text-ink">{p.price_fils > 0 ? t('public.price', { price: formatMoney(p.price_fils, locale) }) : t('public.free')}</p>
                        <div className="mt-auto flex flex-wrap gap-2 pt-3">
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
            <StepFooter>
              <SecondaryButton onClick={() => setStep('who')}><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('public.back')}</SecondaryButton>
              <PrimaryButton disabled={!pkg} onClick={() => setStep('details')}>{t('public.next')}<Icon name="chevron" className="size-4 rtl:rotate-180" /></PrimaryButton>
            </StepFooter>
          </section>
        )}

        {step === 'details' && pkg && (
          <form aria-labelledby="step-title" className={`${SURFACE} mx-auto max-w-2xl space-y-6 p-6 max-lg:p-4`} onSubmit={(e) => { e.preventDefault(); if (pkg.placement) go(placement ? 'result' : 'placement'); else submit.mutate() }}>
            <StepHeading step={stepLabel('details')} title={t('public.details_title')} help={t('public.details_help')} />

            <div className="flex flex-wrap items-center gap-3 rounded-xl bg-brand-50/60 px-4 py-3">
              <span className="grid size-9 shrink-0 place-items-center rounded-full bg-white text-brand-700"><Icon name="packages" className="size-4" /></span>
              <div className="min-w-0 flex-1">
                <p className="text-xs text-ink/55">{t('public.chosen_package')}</p>
                <p dir="auto" className="truncate font-semibold text-ink">{pkg.name}</p>
              </div>
              <button type="button" className="text-sm font-medium text-brand-700 hover:underline" onClick={() => setStep('package')}>{t('public.change')}</button>
            </div>

            <FormSection title={t('public.section_student')}>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field err={err('full_name')} className="sm:col-span-2"><TextInput label={t('public.full_name')} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} required minLength={3} dir="auto" autoComplete="name" /></Field>
                <Field err={err('memorization_level')}><SelectField label={t('public.level')} value={form.memorization_level} onChange={(e) => setForm({ ...form, memorization_level: e.target.value })} options={s?.memorization_levels ?? []} /></Field>
                <Field err={err('student_phone')}><TextInput label={t('public.student_phone')} type="tel" inputMode="tel" dir="ltr" value={form.student_phone} onChange={(e) => setForm({ ...form, student_phone: e.target.value })} /></Field>
              </div>
            </FormSection>

            <FormSection title={t('public.section_guardian')}>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field err={err('guardian_name')} className="sm:col-span-2"><TextInput label={t('public.guardian_name')} value={form.guardian_name} onChange={(e) => setForm({ ...form, guardian_name: e.target.value })} required minLength={3} dir="auto" /></Field>
                <Field err={err('guardian_phone')} hint={t('public.phone_hint')}><TextInput label={t('public.guardian_phone')} type="tel" inputMode="tel" dir="ltr" placeholder="3xxxxxxx" value={form.guardian_phone} onChange={(e) => setForm({ ...form, guardian_phone: e.target.value })} required /></Field>
                <SelectField label={t('public.language')} value={form.locale} onChange={(e) => setForm({ ...form, locale: e.target.value })} options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
              </div>
            </FormSection>

            <FormSection title={t('public.photo')} aside={<span className="text-sm font-normal text-ink/50">{s?.photo_required ? t('public.photo_required') : t('public.photo_optional')}</span>}>
              <div className={`flex flex-wrap items-center gap-4 rounded-xl border border-dashed p-4 ${err('photo') ? 'border-danger/50 bg-danger/5' : 'border-ink/20'}`}>
                {preview
                  ? <img src={preview} alt="" className="size-20 shrink-0 rounded-full object-cover shadow ring-2 ring-white" />
                  : <span className="grid size-20 shrink-0 place-items-center rounded-full bg-ink/5 text-ink/35"><Icon name="camera" className="size-7" /></span>}
                <div className="min-w-0 flex-[1_1_14rem] space-y-3">
                  <p className="text-xs text-ink/50">{t('public.photo_hint')}</p>
                  <div className="flex flex-wrap items-center gap-2">
                    <SecondaryButton onClick={() => cameraRef.current?.click()}><Icon name="camera" className="size-4" />{t('public.take_photo')}</SecondaryButton>
                    <SecondaryButton onClick={() => uploadRef.current?.click()}><Icon name="download" className="size-4 rotate-180" />{t('public.upload_photo')}</SecondaryButton>
                    {photo && <button type="button" className="px-2 text-sm text-danger hover:underline" onClick={() => setPhoto(null)}>{t('public.remove_photo')}</button>}
                  </div>
                </div>
                <input ref={cameraRef} type="file" accept="image/*" capture="user" className="sr-only" tabIndex={-1} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                <input ref={uploadRef} type="file" accept="image/jpeg,image/png,image/heic,image/heif" className="sr-only" tabIndex={-1} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
              </div>
              {err('photo') && <p className="mt-2 text-sm text-danger">{err('photo')}</p>}
            </FormSection>

            <TextArea label={t('public.notes')} rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} dir="auto" />

            <StepFooter>
              <SecondaryButton onClick={() => setStep('package')}><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('public.back')}</SecondaryButton>
              {pkg.placement
                ? <PrimaryButton type="submit" disabled={!!s?.photo_required && !photo}>{placement ? t('placement.continue') : t('placement.continue_to_test')}<Icon name="chevron" className="size-4 rtl:rotate-180" /></PrimaryButton>
                : <PrimaryButton type="submit" loading={submit.isPending} disabled={!!s?.photo_required && !photo}>{submit.isPending ? t('public.submitting') : t('public.submit')}</PrimaryButton>}
            </StepFooter>
          </form>
        )}

        {step === 'placement' && pkg?.placement && (
          <PlacementTest key={pkg.id} pkg={pkg} fullName={form.full_name} guardianPhone={form.guardian_phone} stepLabel={stepLabel('placement')}
            onBack={() => go('details')} onFinished={onPlacementFinished} />
        )}

        {step === 'result' && placement && (
          <PlacementResultView result={placement.result} stepLabel={stepLabel('result')} onContinue={() => go('confirm')} />
        )}

        {step === 'confirm' && pkg && placement && (
          <section aria-labelledby="step-title" className={`${SURFACE} mx-auto max-w-2xl space-y-5 p-6 max-lg:p-4`}>
            <StepHeading step={stepLabel('confirm')} title={t('placement.confirm_step_title')} help={t('placement.confirm_step_help')} />
            <dl className="divide-y divide-ink/6 rounded-xl border border-ink/8 text-sm">
              <SummaryRow label={t('placement.summary_student')} onEdit={() => go('details')} editLabel={t('public.change')}><span dir="auto">{form.full_name}</span></SummaryRow>
              <SummaryRow label={t('placement.summary_guardian')} onEdit={() => go('details')} editLabel={t('public.change')}>
                <span dir="auto">{form.guardian_name}</span> · <span dir="ltr" className="tabular-nums">{toLatinDigits(form.guardian_phone)}</span>
              </SummaryRow>
              <SummaryRow label={t('placement.summary_package')} onEdit={() => go('package')} editLabel={t('public.change')}><span dir="auto">{pkg.name}</span></SummaryRow>
              <SummaryRow label={t('placement.summary_placement')}>
                {t('placement.summary_result', {
                  percent: formatNumber(placement.result.percent / 100, locale, { style: 'percent', maximumFractionDigits: 1 }),
                  level: placement.result.recommended_level_label ?? t('placement.no_level'),
                })}
              </SummaryRow>
            </dl>
            {err('placement_token') && (
              <Notice tone="error">
                {err('placement_token')}{' '}
                <button type="button" className="font-medium underline" onClick={retakePlacement}>{t('placement.retake')}</button>
              </Notice>
            )}
            <StepFooter>
              <SecondaryButton onClick={() => go('result')}><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('public.back')}</SecondaryButton>
              <PrimaryButton loading={submit.isPending} disabled={submit.isPending} onClick={() => submit.mutate()}>{submit.isPending ? t('public.submitting') : t('public.submit')}</PrimaryButton>
            </StepFooter>
          </section>
        )}

        {step === 'done' && result && (
          <OrnamentFrame className="mx-auto max-w-lg text-gold-500">
            <div className="space-y-3 p-6 text-center">
              <span className="mx-auto grid size-14 place-items-center rounded-full bg-brand-50 text-brand-600"><Icon name="check" className="size-7" /></span>
              <h2 className="text-xl font-semibold text-ink">{t('public.done_title')}</h2>
              <p className="text-ink/75">{t('public.done_body', { no: result.request_no })}</p>
              {result.waitlist_position && <p className="text-gold-700">{t('public.done_waitlist', { n: n(result.waitlist_position) })}</p>}
              <p className="mx-auto w-fit rounded-xl bg-page px-4 py-2 font-mono text-2xl tracking-widest text-ink" dir="ltr">{result.request_no}</p>
              <div className="flex flex-wrap justify-center gap-2 pt-2">
                <Link to={`/track/${result.request_no}`} className={buttonClass()}>{t('public.track_link')}</Link>
                <SecondaryButton onClick={() => { setStep('who'); setResult(null); setPkg(null); setPlacement(null); setPhoto(null); setForm({ ...form, full_name: '', notes: '' }) }}>{t('public.new_request')}</SecondaryButton>
              </div>
            </div>
          </OrnamentFrame>
        )}
      </div>
    </PublicLayout>
  )
}

/** One line of the review step: label, value, and an optional link back to the step that set it. */
function SummaryRow({ label, children, onEdit, editLabel }: { label: string; children: ReactNode; onEdit?: () => void; editLabel?: string }) {
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-4 py-3">
      <dt className="w-full text-ink/55 sm:w-36">{label}</dt>
      <dd className="min-w-0 flex-1 font-medium text-ink">{children}</dd>
      {onEdit && <button type="button" className="text-sm font-medium text-brand-700 hover:underline" onClick={onEdit}>{editLabel}</button>}
    </div>
  )
}
