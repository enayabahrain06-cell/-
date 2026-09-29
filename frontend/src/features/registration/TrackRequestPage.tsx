import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { publicApi, type RegistrationRequest } from '../../api/registration'
import Alert from '../../components/Alert'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Icon from '../../components/Icon'
import { OrnamentDivider } from '../../components/ornaments'
import { SURFACE } from '../../components/ui'
import PublicLayout from '../../layouts/PublicLayout'
import { formatDate, formatNumber } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'

/** Per status: the icon on the result header and its tinted surface. Unknown statuses fall back to pending. */
const LOOK: Record<string, { icon: string; surface: string }> = {
  pending: { icon: 'clock', surface: 'bg-gold-500/8 text-gold-700' },
  waitlist: { icon: 'clock', surface: 'bg-info/8 text-info-700' },
  accepted: { icon: 'check', surface: 'bg-brand-50 text-brand-700' },
  enrolled: { icon: 'check', surface: 'bg-brand-50 text-brand-700' },
  pending_lottery: { icon: 'lottery', surface: 'bg-gold-500/8 text-gold-700' },
  rejected: { icon: 'ban', surface: 'bg-danger/5 text-danger' },
}

/** Public tracking: request number + guardian phone (both must match on the server). */
export default function TrackRequestPage() {
  const { no } = useParams()
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const [requestNo, setRequestNo] = useState(no ?? '')
  const [phone, setPhone] = useState('')
  const [found, setFound] = useState<RegistrationRequest | null>(null)
  const [notFound, setNotFound] = useState(false)

  const search = useMutation({
    mutationFn: () => publicApi.track(requestNo.trim(), toLatinDigits(phone)),
    onSuccess: (r) => { setFound(r); setNotFound(false) },
    onError: () => { setFound(null); setNotFound(true) },
  })

  return (
    <PublicLayout>
      <div className="mx-auto max-w-lg space-y-6">
        <div className="text-center">
          <h1 className="font-display text-3xl text-ink sm:text-4xl">{t('track.title')}</h1>
          <p className="mt-2 text-ink/65">{t('track.subtitle')}</p>
          <OrnamentDivider align="center" className="mx-auto mt-3 text-gold-500" />
        </div>

        <form className={`${SURFACE} space-y-5 p-6`} onSubmit={(e) => { e.preventDefault(); search.mutate() }} aria-describedby="track-hint">
          <div className="flex items-start gap-3">
            <span className="shrink-0 rounded-xl bg-brand-50 p-2.5 text-brand-700"><Icon name="search" className="size-5" /></span>
            <p id="track-hint" className="text-sm leading-relaxed text-ink/65">{t('track.hint')}</p>
          </div>
          <FormField label={t('track.request_no')} hint={t('track.request_no_hint')} value={requestNo} onChange={(e) => setRequestNo(e.target.value.toUpperCase())}
            dir="ltr" autoComplete="off" spellCheck={false} required className="[&_input]:font-mono [&_input]:tracking-wider" />
          <FormField label={t('track.phone')} hint={t('public.phone_hint')} type="tel" inputMode="tel" autoComplete="tel" dir="ltr" placeholder="3xxxxxxx"
            value={phone} onChange={(e) => setPhone(e.target.value)} required />
          {notFound && <Alert>{t('track.not_found')}</Alert>}
          <Button type="submit" loading={search.isPending}>{t('track.search')}</Button>
        </form>

        {found && <Result request={found} locale={locale} />}

        <p className="text-center text-sm">
          <Link to="/register" className="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline">
            <Icon name="enroll" className="size-4" />{t('public.new_request')}
          </Link>
        </p>
      </div>
    </PublicLayout>
  )
}

function Result({ request: r, locale }: { request: RegistrationRequest; locale: string }) {
  const { t } = useTranslation('registration')
  const look = LOOK[r.status] ?? LOOK.pending
  const label = t(`status.${r.status}`, { defaultValue: r.status_label })
  const body = t(`track.${r.status}_body`, { defaultValue: '' })
  // Only final outcomes fill the decision step; pending, waitlist and lottery stages are still waiting.
  const decided = ['accepted', 'enrolled', 'rejected'].includes(r.status)

  // Submitted → under review → decision. Waitlist stops at review; a decision fills the last step.
  const steps = [
    { key: 'submitted', label: t('track.step_submitted'), date: r.created_at, state: 'done' as const },
    { key: 'review', label: r.status === 'waitlist' ? label : t('track.step_review'), date: null, state: decided ? ('done' as const) : ('current' as const) },
    { key: 'decision', label: decided ? label : t('track.step_decision'), date: r.decided_at, state: decided ? ('done' as const) : ('todo' as const) },
  ]

  return (
    <section className={`${SURFACE} overflow-hidden`} aria-labelledby="track-result" aria-live="polite">
      <div className={`flex items-start gap-3 p-5 ${look.surface}`}>
        <span className="shrink-0 rounded-full bg-white/70 p-2"><Icon name={look.icon} className="size-5" /></span>
        <div className="min-w-0 flex-1">
          <h2 id="track-result" className="text-lg font-semibold">{label}</h2>
          {body && <p className="mt-0.5 text-sm text-ink/75">{body}</p>}
        </div>
      </div>

      <div className="space-y-5 p-5 sm:p-6">
        <div className="min-w-0">
          <p className="text-xs text-ink/50">{t('track.student')}</p>
          <p dir="auto" className="truncate text-lg font-semibold text-ink">{r.full_name}</p>
        </div>

        {r.status === 'waitlist' && r.waitlist_position && (
          <div className="flex items-center justify-between gap-3 rounded-xl border border-info/20 bg-info/5 px-4 py-3">
            <span className="text-sm text-info-700">{t('track.position_label')}</span>
            <span className="text-2xl font-semibold tabular-nums text-info-700">{formatNumber(r.waitlist_position, locale)}</span>
          </div>
        )}

        <ol className="grid grid-cols-3 gap-2" aria-label={t('track.result')}>
          {steps.map((s) => (
            <li key={s.key} className="min-w-0">
              <span aria-hidden className={`block h-1.5 rounded-full ${s.state === 'done' ? (r.status === 'rejected' && s.key === 'decision' ? 'bg-danger' : 'bg-brand-600') : s.state === 'current' ? 'bg-gold-500' : 'bg-ink/10'}`} />
              <p className={`mt-2 truncate text-sm font-medium ${s.state === 'todo' ? 'text-ink/45' : 'text-ink'}`}>{s.label}</p>
              <p className="text-xs tabular-nums text-ink/50">{s.date && s.state === 'done' ? formatDate(s.date, locale, { day: 'numeric', month: 'short', year: 'numeric' }) : ' '}</p>
            </li>
          ))}
        </ol>

        {r.reason && (
          <p className="rounded-xl bg-page px-4 py-3 text-sm text-ink/75"><b className="text-ink">{t('track.reason')}:</b> <span dir="auto">{r.reason}</span></p>
        )}

        <dl className="grid gap-x-4 gap-y-3 border-t border-ink/6 pt-4 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs text-ink/50">{t('track.request_no')}</dt>
            <dd className="text-ink"><span dir="ltr" className="font-mono tracking-wider">{r.request_no}</span></dd>
          </div>
          {r.package && (
            <div>
              <dt className="text-xs text-ink/50">{t('track.package')}</dt>
              <dd dir="auto" className="text-ink">{r.package.name}</dd>
            </div>
          )}
        </dl>
      </div>
    </section>
  )
}
