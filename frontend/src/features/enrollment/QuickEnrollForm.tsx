import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { enrollmentApi, type EnrollResult } from '../../api/enrollment'
import { parseApiError, type FieldErrors } from '../../api/client'
import Alert from '../../components/Alert'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import SelectField from '../../components/SelectField'
import { formatMoney, formatNumber, formatTime } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'

interface StudentFields {
  full_name: string
  birth_date: string
  gender: '' | 'male' | 'female'
  memorization_level: string
  student_phone: string
  guardian_name: string
  guardian_phone: string
}

const EMPTY_STUDENT: StudentFields = { full_name: '', birth_date: '', gender: '', memorization_level: '', student_phone: '', guardian_name: '', guardian_phone: '' }

function ageFrom(birth: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(birth)) return null
  const b = new Date(birth)
  const now = new Date()
  let age = now.getFullYear() - b.getFullYear()
  if (now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) age--
  return age >= 0 ? age : null
}

/** Keeps a value stable until the user pauses typing, so lookups do not fire on every key. */
function useDebounced<T>(value: T, ms = 450): T {
  const [v, setV] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms)
    return () => clearTimeout(id)
  }, [value, ms])
  return v
}

export default function QuickEnrollForm() {
  const { t, i18n } = useTranslation('enrollment')
  const locale = i18n.language
  const [f, setF] = useState<StudentFields>(EMPTY_STUDENT)
  const [packageId, setPackageId] = useState<number | null>(null)
  const [lessonId, setLessonId] = useState<number | null>(null)
  const [waitlist, setWaitlist] = useState(false)
  const [recordPayment, setRecordPayment] = useState(false)
  const [amount, setAmount] = useState('')
  const [photo, setPhoto] = useState<File | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [duplicate, setDuplicate] = useState<string | null>(null)
  const [done, setDone] = useState<EnrollResult | null>(null)
  const [saving, setSaving] = useState(false)
  const addAnother = useRef(false)
  const nameRef = useRef<HTMLInputElement>(null)
  const cameraRef = useRef<HTMLInputElement>(null)
  const uploadRef = useRef<HTMLInputElement>(null)

  const set = <K extends keyof StudentFields>(key: K, value: StudentFields[K]) => {
    setF((prev) => ({ ...prev, [key]: value }))
    setErrors((prev) => ({ ...prev, [key]: [] }))
    setDuplicate(null)
  }

  const age = ageFrom(f.birth_date)
  const options = useQuery({
    queryKey: ['enrollment-options', f.birth_date, f.gender, locale],
    queryFn: () => enrollmentApi.options({ birth_date: age !== null ? f.birth_date : undefined, gender: f.gender || undefined }),
    enabled: age !== null && !!f.gender,
  })
  const levels = useQuery({ queryKey: ['enrollment-levels', locale], queryFn: () => enrollmentApi.options({}) })

  const lookupParams = useDebounced({ guardian_phone: toLatinDigits(f.guardian_phone).trim(), full_name: f.full_name.trim(), birth_date: age !== null ? f.birth_date : '' })
  const lookup = useQuery({
    queryKey: ['enrollment-lookup', lookupParams],
    queryFn: () => enrollmentApi.lookup(lookupParams),
    enabled: lookupParams.guardian_phone.replace(/\D/g, '').length >= 8 || (lookupParams.full_name.length >= 3 && !!lookupParams.birth_date),
  })

  // Reuse the known guardian's name when the phone matches.
  const guardian = lookup.data?.guardian
  useEffect(() => {
    if (guardian && !f.guardian_name) setF((prev) => ({ ...prev, guardian_name: guardian.name }))
  }, [guardian, f.guardian_name])

  const packages = options.data?.data ?? []
  const selected = packages.find((p) => p.id === packageId) ?? null
  // Drop a package or circle that no longer fits after the age or gender changed.
  useEffect(() => {
    if (packageId && options.data && !selected) {
      setPackageId(null)
      setLessonId(null)
    }
  }, [packageId, options.data, selected])
  useEffect(() => {
    if (selected && lessonId && !selected.circles.some((c) => c.id === lessonId)) setLessonId(null)
    if (selected && !selected.is_full) setWaitlist(false)
  }, [selected, lessonId])

  const canPay = !!options.data?.can_record_payment && !waitlist
  const photoPreview = useMemo(() => (photo ? URL.createObjectURL(photo) : null), [photo])
  useEffect(() => () => void (photoPreview && URL.revokeObjectURL(photoPreview)), [photoPreview])

  const err = (key: string) => errors[key]?.[0]

  const submit = async (e: FormEvent, confirmDuplicate = false) => {
    e.preventDefault()
    if (!selected || !f.gender) {
      setErrors((prev) => ({ ...prev, package_id: [t('pick_package')] }))
      return
    }
    setSaving(true)
    setFormError(null)
    setDone(null)
    try {
      const result = await enrollmentApi.enroll({
        ...f,
        gender: f.gender,
        guardian_phone: toLatinDigits(f.guardian_phone),
        student_phone: toLatinDigits(f.student_phone) || undefined,
        package_id: selected.id,
        lesson_id: waitlist ? null : lessonId,
        waitlist,
        record_payment: canPay && recordPayment,
        payment_amount_fils: canPay && recordPayment ? Math.round(Number(toLatinDigits(amount)) * 1000) : undefined,
        confirm_duplicate: confirmDuplicate,
        photo,
      })
      setDone(result)
      setDuplicate(null)
      setErrors({})
      // "Save and add another" keeps the package and circle so a whole group goes in quickly.
      // The selection survives the empty birth date and is dropped only if the next child's age does not fit.
      setF((prev) => (addAnother.current ? { ...EMPTY_STUDENT, memorization_level: prev.memorization_level, gender: prev.gender } : EMPTY_STUDENT))
      if (!addAnother.current) {
        setPackageId(null)
        setLessonId(null)
      }
      setPhoto(null)
      setRecordPayment(false)
      setWaitlist(false)
      void options.refetch()
      nameRef.current?.focus()
    } catch (ex) {
      const { message, fields } = parseApiError(ex)
      if (fields.duplicate) setDuplicate(fields.duplicate[0])
      setErrors(fields)
      if (!Object.keys(fields).length) setFormError(message)
    } finally {
      setSaving(false)
      addAnother.current = false
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} noValidate className="space-y-6">
      {done && (
        <Alert tone="success">
          <p className="font-medium">{done.message}</p>
          {done.student && (
            <p className="mt-1">
              <Link to={`/students/${done.student.id}`} className="underline">
                {done.student.full_name}
              </Link>{' '}
              · <span dir="ltr">{done.student.student_no}</span>
              {done.payment && <> · {t('receipt', { no: done.payment.receipt_no })}</>}
            </p>
          )}
          {done.status === 'waitlist' && <p className="mt-1">{t('waitlist_position', { position: formatNumber(done.waitlist_position ?? 0, locale) })}</p>}
        </Alert>
      )}
      {formError && <Alert>{formError}</Alert>}

      <Section title={t('section_student')} className="grid gap-4 sm:grid-cols-2">
        <FormField ref={nameRef} label={t('full_name')} value={f.full_name} onChange={(e) => set('full_name', e.target.value)} error={err('full_name')} autoComplete="off" required className="sm:col-span-2" autoFocus />
        <FormField
          label={t('birth_date')}
          type="date"
          value={f.birth_date}
          onChange={(e) => set('birth_date', e.target.value)}
          error={err('birth_date')}
          hint={age !== null ? t('age_now', { age: formatNumber(age, locale) }) : undefined}
          max={new Date().toISOString().slice(0, 10)}
          required
        />
        <div>
          <span id="gender-label" className="mb-1.5 block text-sm font-medium text-stone-700">{t('gender')}</span>
          <div role="radiogroup" aria-labelledby="gender-label" className="grid grid-cols-2 gap-2">
            {(['male', 'female'] as const).map((g) => (
              <label key={g} className={`flex cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-medium transition focus-within:ring-4 focus-within:ring-brand-100 ${f.gender === g ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-stone-300 bg-white text-ink/70'}`}>
                <input type="radio" name="gender" value={g} checked={f.gender === g} onChange={() => set('gender', g)} className="sr-only" />
                {t(`gender_${g}`)}
              </label>
            ))}
          </div>
          {err('gender') && <p className="mt-1.5 text-sm text-danger">{err('gender')}</p>}
        </div>
        <SelectField
          label={t('memorization_level')}
          value={f.memorization_level}
          onChange={(e) => set('memorization_level', e.target.value)}
          options={[{ value: '', label: t('choose') }, ...(levels.data?.memorization_levels ?? [])]}
          required
        />
        <FormField label={t('student_phone')} type="tel" inputMode="tel" dir="ltr" value={f.student_phone} onChange={(e) => set('student_phone', e.target.value)} error={err('student_phone')} hint={t('optional')} placeholder="3xxxxxxx" />
        <div className="sm:col-span-2">
          <span className="mb-1.5 block text-sm font-medium text-stone-700">
            {t('photo')} <span className="font-normal text-ink/50">({t('optional')})</span>
          </span>
          <div className="flex flex-wrap items-center gap-3">
            {photoPreview && <img src={photoPreview} alt="" className="size-16 rounded-xl object-cover ring-1 ring-ink/10" />}
            <input ref={cameraRef} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
            <input ref={uploadRef} type="file" accept="image/jpeg,image/png,image/heic,image/heif" className="sr-only" tabIndex={-1} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
            <button type="button" onClick={() => cameraRef.current?.click()} className="rounded-lg border border-ink/15 bg-white px-3 py-2 text-sm font-medium text-ink/75 hover:bg-ink/5">{t('photo_camera')}</button>
            <button type="button" onClick={() => uploadRef.current?.click()} className="rounded-lg border border-ink/15 bg-white px-3 py-2 text-sm font-medium text-ink/75 hover:bg-ink/5">{t('photo_upload')}</button>
            {photo && <button type="button" onClick={() => setPhoto(null)} className="text-sm text-danger hover:underline">{t('photo_remove')}</button>}
          </div>
          {err('photo') && <p className="mt-1.5 text-sm text-danger">{err('photo')}</p>}
        </div>
      </Section>

      <Section title={t('section_guardian')} className="grid gap-4 sm:grid-cols-2">
        <FormField
          label={t('guardian_phone')}
          type="tel"
          inputMode="tel"
          dir="ltr"
          value={f.guardian_phone}
          onChange={(e) => set('guardian_phone', e.target.value)}
          error={err('guardian_phone')}
          placeholder="3xxxxxxx"
          hint={t('phone_hint')}
          required
        />
        <FormField label={t('guardian_name')} value={f.guardian_name} onChange={(e) => set('guardian_name', e.target.value)} error={err('guardian_name')} required />
        {guardian && (
          <div className="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 sm:col-span-2" role="status">
            <p className="font-medium">{t('guardian_found', { name: guardian.name })}</p>
            {guardian.children.length > 0 && (
              <p className="mt-1">
                {t('siblings')}: {guardian.children.map((c) => c.full_name).join(locale === 'ar' ? '، ' : ', ')}
              </p>
            )}
            {guardian.hidden_children > 0 && <p className="mt-1 text-brand-900/70">{t('hidden_children', { count: guardian.hidden_children })}</p>}
          </div>
        )}
        {(lookup.data?.duplicates.length ?? 0) > 0 && (
          <div className="rounded-xl border border-gold-500/40 bg-gold-500/8 px-4 py-3 text-sm text-gold-700 sm:col-span-2" role="status">
            <p className="font-medium">{t('duplicate_title')}</p>
            <ul className="mt-1 list-inside list-disc">
              {lookup.data!.duplicates.map((d, i) => (
                <li key={i}>
                  {t(`duplicate_${d.reason}`)}
                  {d.full_name && (
                    <>
                      {' '}— {d.full_name} <span dir="ltr">({d.student_no})</span>
                    </>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </Section>

      <Section title={t('section_placement')} className="space-y-4">
        {age === null || !f.gender ? (
          <p className="text-sm text-ink/55">{t('placement_hint')}</p>
        ) : options.isLoading ? (
          <p className="text-sm text-ink/55">{t('loading')}</p>
        ) : packages.length === 0 ? (
          <Alert tone="info">{t('no_packages')}</Alert>
        ) : (
          <>
            <SelectField
              label={t('package')}
              value={packageId ?? ''}
              onChange={(e) => {
                const next = packages.find((p) => p.id === Number(e.target.value))
                setPackageId(next?.id ?? null)
                setAmount(next ? (next.price_fils / 1000).toFixed(3) : '')
                setLessonId(null)
                setErrors((prev) => ({ ...prev, package_id: [] }))
              }}
              options={[
                { value: '', label: t('choose') },
                ...packages.map((p) => ({
                  value: String(p.id),
                  label: `${p.name} · ${formatMoney(p.price_fils, locale)} · ${p.is_full ? t('full') : t('seats_left', { count: p.seats_left })}`,
                })),
              ]}
            />
            {err('package_id') && <p className="-mt-2 text-sm text-danger">{err('package_id')}</p>}

            {selected?.is_full && (
              <label className="flex items-center gap-3 rounded-xl border border-gold-500/40 bg-gold-500/8 px-4 py-3 text-sm text-gold-700">
                <input type="checkbox" checked={waitlist} onChange={(e) => setWaitlist(e.target.checked)} className="size-4 accent-brand-700" />
                {t('waitlist_option')}
              </label>
            )}

            {selected && !waitlist && (
              <div role="radiogroup" aria-label={t('circle')} className="grid gap-2 sm:grid-cols-2">
                {selected.circles.length === 0 && <p className="text-sm text-ink/55 sm:col-span-2">{t('no_circles')}</p>}
                {selected.circles.map((c) => (
                  <label key={c.id} className={`cursor-pointer rounded-xl border px-4 py-3 transition focus-within:ring-4 focus-within:ring-brand-100 ${lessonId === c.id ? 'border-brand-600 bg-brand-50' : 'border-stone-300 bg-white hover:border-brand-500/50'}`}>
                    <input type="radio" name="lesson" value={c.id} checked={lessonId === c.id} onChange={() => setLessonId(c.id)} className="sr-only" />
                    <span className="block font-medium text-ink">{c.name}</span>
                    <span className="mt-0.5 block text-sm text-ink/60">
                      {c.teacher} · {c.location} · {formatTime(c.start_time, locale)}
                    </span>
                    <span className="mt-0.5 block text-xs text-brand-700">{t('free_seats', { count: c.free_seats })}</span>
                  </label>
                ))}
              </div>
            )}
            {err('lesson_id') && <p className="text-sm text-danger">{err('lesson_id')}</p>}
          </>
        )}
      </Section>

      {canPay && selected && selected.price_fils > 0 && (
        <Section title={t('section_payment')} className="flex flex-wrap items-end gap-4">
          <label className="flex items-center gap-3 py-3 text-sm font-medium text-ink">
            <input type="checkbox" checked={recordPayment} onChange={(e) => setRecordPayment(e.target.checked)} className="size-4 accent-brand-700" />
            {t('record_payment')}
          </label>
          {recordPayment && (
            <FormField label={t('amount_bhd')} inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value)} error={err('payment_amount_fils') ?? err('record_payment')} className="w-40" />
          )}
        </Section>
      )}

      {duplicate && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gold-500/50 bg-gold-500/10 px-4 py-3 text-sm text-gold-700">
          <span>{duplicate}</span>
          <button type="button" onClick={(e) => void submit(e, true)} className="rounded-lg bg-gold-700 px-3 py-1.5 font-semibold text-white hover:bg-gold-500">
            {t('save_anyway')}
          </button>
        </div>
      )}

      <div className="flex flex-col gap-3 sm:flex-row">
        <Button type="submit" loading={saving} className="sm:w-auto sm:px-8">
          {waitlist ? t('save_waitlist') : t('save')}
        </Button>
        <Button type="submit" variant="ghost" className="border border-brand-700/30 sm:w-auto sm:px-6" disabled={saving} onClick={() => (addAnother.current = true)}>
          {t('save_another')}
        </Button>
      </div>
    </form>
  )
}

/** A form section: a fieldset whose legend sits inside the card (float trick) and which may shrink below its content width. */
function Section({ title, className = '', children }: { title: string; className?: string; children: ReactNode }) {
  return (
    <fieldset className="min-w-0 rounded-2xl border border-ink/8 bg-white p-5 shadow-sm">
      <legend className="float-start mb-4 w-full text-sm font-semibold text-ink/70">{title}</legend>
      <div className={`clear-both ${className}`}>{children}</div>
    </fieldset>
  )
}
