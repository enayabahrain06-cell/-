import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useLocation } from 'react-router-dom'
import AuthLayout from '../../layouts/AuthLayout'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Alert from '../../components/Alert'
import { OrnamentDivider } from '../../components/ornaments'
import { authApi, type OtpRequestResponse } from '../../api/auth'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import { looksLikePhone, toLatinDigits } from '../../lib/phone'

type Mode = 'password' | 'otp'

function useAfterLogin() {
  const navigate = useNavigate()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from ?? '/'
  return () => navigate(from, { replace: true })
}

export default function LoginPage() {
  const { t } = useTranslation('auth')
  const [mode, setMode] = useState<Mode>('password')

  return (
    <AuthLayout>
      <h1 className="font-display text-3xl text-ink sm:text-4xl max-lg:text-[28px] max-lg:leading-normal">{t('title')}</h1>
      <OrnamentDivider className="mt-2 text-gold-500 max-lg:hidden" />
      <p className="mt-3 text-ink/65 max-lg:mt-1 max-lg:text-[15px]">{t('subtitle')}</p>

      <div role="tablist" aria-label={t('title')} className="mt-8 grid grid-cols-2 gap-1 rounded-2xl bg-ink/5 p-1 max-lg:mt-5 max-lg:gap-[3px] max-lg:rounded-ctl max-lg:p-[3px]">
        {(['password', 'otp'] as const).map((m) => (
          <button
            key={m}
            role="tab"
            type="button"
            id={`tab-${m}`}
            aria-selected={mode === m}
            aria-controls={`panel-${m}`}
            onClick={() => setMode(m)}
            className={`rounded-xl px-3 py-2.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-brand-500 max-lg:min-h-11 max-lg:rounded-lg max-lg:px-2 max-lg:py-1.5 ${
              mode === m ? 'bg-white text-ink shadow-sm max-lg:text-brand-700 max-lg:shadow-card' : 'text-ink/65 hover:text-ink'
            }`}
          >
            {t(`tabs.${m}`)}
          </button>
        ))}
      </div>

      <section id={`panel-${mode}`} role="tabpanel" aria-labelledby={`tab-${mode}`} className="mt-6 max-lg:mt-4">
        <p className="mb-5 text-sm text-ink/55 max-lg:mb-4 max-lg:text-[13px] max-lg:text-ink/65">{t(`tab_hints.${mode}`)}</p>
        {mode === 'password' ? <PasswordForm /> : <OtpForm />}
      </section>

      <div className="mt-8 space-y-2 border-t border-ink/10 pt-6 text-center text-sm max-lg:mt-6 max-lg:space-y-0 max-lg:pt-3 max-lg:text-[15px]">
        <Link to="/register" className="block font-medium text-brand-700 hover:underline max-lg:flex max-lg:min-h-11 max-lg:items-center max-lg:justify-center max-lg:font-semibold">
          {t('register_cta')}
        </Link>
        <Link to="/track" className="block text-ink/55 hover:text-ink/80 hover:underline max-lg:flex max-lg:min-h-11 max-lg:items-center max-lg:justify-center max-lg:text-ink/65">
          {t('track_cta')}
        </Link>
      </div>
    </AuthLayout>
  )
}

function PasswordForm() {
  const { t } = useTranslation('auth')
  const { signIn } = useAuth()
  const done = useAfterLogin()
  const [show, setShow] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const schema = z.object({
    phone: z.string().trim().min(1, t('validation.phone_required')).refine(looksLikePhone, t('validation.phone_invalid')),
    password: z.string().min(1, t('validation.password_required')),
  })
  type Values = z.infer<typeof schema>

  const { register, handleSubmit, setError: setFieldError, formState } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { phone: '', password: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setError(null)
    try {
      signIn(await authApi.login(toLatinDigits(values.phone), values.password))
      done()
    } catch (e) {
      const { message, fields } = parseApiError(e)
      if (fields.phone) setFieldError('phone', { message: fields.phone[0] })
      else if (fields.password) setFieldError('password', { message: fields.password[0] })
      else setError(message)
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-5">
      {error && <Alert>{error}</Alert>}
      <FormField
        label={t('phone')}
        type="tel"
        inputMode="tel"
        dir="ltr"
        autoComplete="username"
        placeholder={t('phone_placeholder')}
        error={formState.errors.phone?.message}
        {...register('phone')}
      />
      <FormField
        label={t('password')}
        type={show ? 'text' : 'password'}
        autoComplete="current-password"
        error={formState.errors.password?.message}
        end={
          <button
            type="button"
            onClick={() => setShow((s) => !s)}
            className="rounded-lg px-2.5 py-1.5 text-sm font-medium text-ink/55 hover:bg-ink/5 hover:text-ink max-lg:min-h-11 max-lg:text-ink/65"
          >
            {show ? t('hide_password') : t('show_password')}
          </button>
        }
        {...register('password')}
      />
      <Button type="submit" loading={formState.isSubmitting}>
        {t('submit')}
      </Button>
    </form>
  )
}

const RESEND_SECONDS = 60

function OtpForm() {
  const { t } = useTranslation('auth')
  const { signIn } = useAuth()
  const done = useAfterLogin()
  const [sent, setSent] = useState<OtpRequestResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)

  useEffect(() => {
    if (cooldown <= 0) return
    const id = setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => clearTimeout(id)
  }, [cooldown])

  const phoneSchema = z.object({
    phone: z.string().trim().min(1, t('validation.phone_required')).refine(looksLikePhone, t('validation.phone_invalid')),
  })
  const codeSchema = z.object({
    code: z
      .string()
      .transform((v) => toLatinDigits(v).replace(/\D/g, ''))
      .refine((v) => v.length === 6, t('validation.code_required')),
  })

  const phoneForm = useForm<z.infer<typeof phoneSchema>>({ resolver: zodResolver(phoneSchema), defaultValues: { phone: '' } })
  const codeForm = useForm<z.input<typeof codeSchema>, unknown, z.output<typeof codeSchema>>({
    resolver: zodResolver(codeSchema),
    defaultValues: { code: '' },
  })

  const requestCode = async (phone: string) => {
    setError(null)
    try {
      const res = await authApi.requestOtp(toLatinDigits(phone))
      setSent(res)
      setCooldown(RESEND_SECONDS)
      codeForm.reset({ code: '' })
    } catch (e) {
      const { message, fields } = parseApiError(e)
      if (fields.phone) phoneForm.setError('phone', { message: fields.phone[0] })
      else setError(message)
    }
  }

  const onRequest = phoneForm.handleSubmit((v) => requestCode(v.phone))

  const onVerify = codeForm.handleSubmit(async ({ code }) => {
    if (!sent) return
    setError(null)
    try {
      signIn(await authApi.verifyOtp(sent.phone, code))
      done()
    } catch (e) {
      const { message, fields } = parseApiError(e)
      if (fields.code) codeForm.setError('code', { message: fields.code[0] })
      else setError(fields.phone?.[0] ?? message)
    }
  })

  if (!sent) {
    return (
      <form onSubmit={onRequest} noValidate className="space-y-5">
        {error && <Alert>{error}</Alert>}
        <FormField
          label={t('phone')}
          type="tel"
          inputMode="tel"
          dir="ltr"
          autoComplete="tel"
          placeholder={t('phone_placeholder')}
          error={phoneForm.formState.errors.phone?.message}
          {...phoneForm.register('phone')}
        />
        <Button type="submit" loading={phoneForm.formState.isSubmitting}>
          <WhatsAppIcon />
          {t('send_code')}
        </Button>
      </form>
    )
  }

  return (
    <form onSubmit={onVerify} noValidate className="space-y-5">
      {error && <Alert>{error}</Alert>}
      {/* U+2066/U+2069 isolate the phone number so it reads left-to-right inside Arabic text. */}
      <Alert tone="success">{t('code_sent', { phone: `⁦${sent.phone}⁩` })}</Alert>
      {sent.debug_code && <Alert tone="info">{t('dev_code', { code: sent.debug_code })}</Alert>}
      <FormField
        label={t('code')}
        inputMode="numeric"
        autoComplete="one-time-code"
        dir="ltr"
        maxLength={6}
        autoFocus
        className="[&_input]:text-center [&_input]:text-2xl [&_input]:tracking-[0.5em]"
        error={codeForm.formState.errors.code?.message}
        {...codeForm.register('code')}
      />
      <Button type="submit" loading={codeForm.formState.isSubmitting}>
        {t('verify')}
      </Button>
      <div className="flex items-center justify-between text-sm">
        <button type="button" onClick={() => setSent(null)} className="font-medium text-ink/65 hover:text-ink hover:underline max-lg:min-h-11">
          {t('change_phone')}
        </button>
        <button
          type="button"
          disabled={cooldown > 0}
          onClick={() => requestCode(sent.phone)}
          className="font-medium text-brand-700 hover:underline disabled:cursor-not-allowed disabled:text-ink/40 disabled:no-underline max-lg:min-h-11 max-lg:tabular-nums max-lg:disabled:text-ink/65"
        >
          {cooldown > 0 ? t('resend_in', { seconds: cooldown }) : t('resend')}
        </button>
      </div>
    </form>
  )
}

function WhatsAppIcon() {
  return (
    <svg aria-hidden viewBox="0 0 24 24" className="size-5" fill="currentColor">
      <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.4.8 3.2.6a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z" />
    </svg>
  )
}
