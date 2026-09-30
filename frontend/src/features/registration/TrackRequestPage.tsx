import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { publicApi, type RegistrationRequest } from '../../api/registration'
import Alert from '../../components/Alert'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Icon from '../../components/Icon'
import { M_CARD, Pill, type PillTone } from '../../components/mobile/atoms'
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
    <PublicLayout mobile={{ title: t('mobile.track_title'), back: '/login' }}>
      <div className="mx-auto max-w-lg space-y-6">
        <div className="text-center max-lg:hidden">
          <h1 className="font-display text-3xl text-ink sm:text-4xl">{t('track.title')}</h1>
          <p className="mt-2 text-ink/65">{t('track.subtitle')}</p>
          <OrnamentDivider align="center" className="mx-auto mt-3 text-gold-500" />
        </div>

        <form className={`${SURFACE} space-y-5 p-6 max-lg:p-4`} onSubmit={(e) => { e.preventDefault(); search.mutate() }} aria-describedby="track-hint">
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

        {found && <div className="hidden lg:block"><Result request={found} locale={locale} /></div>}
        {found && <MobileTrackResult request={found} locale={locale} />}
        <MobileTrackContact />

        <p className="text-center text-sm">
          <Link to="/register" className="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline max-lg:min-h-11 max-lg:text-[15px]">
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

const PILL_TONE: Record<string, PillTone> = { pending: 'warn', pending_lottery: 'warn', waitlist: 'info', accepted: 'ok', enrolled: 'ok', rejected: 'err' }

/**
 * Result below lg (mobile spec §6.3): name + request number + status pill, then a vertical timeline
 * (done = emerald dot, current = gold ring, pending = hollow). Same data and steps as the desktop result.
 */
function MobileTrackResult({ request: r, locale }: { request: RegistrationRequest; locale: string }) {
  const { t } = useTranslation('registration')
  const label = t(`status.${r.status}`, { defaultValue: r.status_label })
  const body = t(`track.${r.status}_body`, { defaultValue: '' })
  const decided = ['accepted', 'enrolled', 'rejected'].includes(r.status)
  const date = (d: string | null) => (d ? formatDate(d, locale, { day: 'numeric', month: 'short', year: 'numeric' }) : null)
  const steps = [
    { key: 'submitted', label: t('track.step_submitted'), date: date(r.created_at), state: 'done' },
    { key: 'review', label: r.status === 'waitlist' ? label : t('track.step_review'), date: null, state: decided ? 'done' : 'current' },
    { key: 'decision', label: decided ? label : t('track.step_decision'), date: decided ? date(r.decided_at) : null, state: decided ? 'done' : 'todo' },
  ] as const

  return (
    <section aria-labelledby="track-result-m" aria-live="polite" className={`${M_CARD} space-y-4 p-4 lg:hidden`}>
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <h2 id="track-result-m" className="truncate text-[15px] font-semibold text-ink"><bdi>{r.full_name}</bdi></h2>
          <p className="mt-0.5 truncate text-[13px] text-ink/65"><span dir="ltr" className="font-mono tracking-wider">{r.request_no}</span>{r.package && <> · <bdi>{r.package.name}</bdi></>}</p>
        </div>
        <Pill tone={PILL_TONE[r.status] ?? 'warn'}>{label}</Pill>
      </div>
      {body && <p className="text-[15px] text-ink/75">{body}</p>}

      {r.status === 'waitlist' && r.waitlist_position && (
        <div className="flex items-center justify-between gap-3 rounded-ctl bg-info/10 px-4 py-3 text-info">
          <span className="text-[13px] font-semibold">{t('track.position_label')}</span>
          <span className="text-2xl font-semibold tabular-nums">{formatNumber(r.waitlist_position, locale)}</span>
        </div>
      )}

      <ol aria-label={t('track.result')}>
        {steps.map((s, i) => {
          const rejected = r.status === 'rejected' && s.key === 'decision'
          return (
            <li key={s.key} className="relative flex min-h-12 gap-3" aria-current={s.state === 'current' ? 'step' : undefined}>
              {i < steps.length - 1 && <span aria-hidden className={`absolute bottom-0 start-[9px] top-6 w-0.5 ${s.state === 'done' ? 'bg-brand-700' : 'bg-ink/10'}`} />}
              <span aria-hidden className={`relative mt-0.5 grid size-5 shrink-0 place-items-center rounded-full ${
                s.state === 'done' ? (rejected ? 'bg-danger text-white' : 'bg-brand-700 text-white') : s.state === 'current' ? 'border-[3px] border-gold-500 bg-white' : 'border-2 border-ink/20 bg-white'
              }`}>
                {s.state === 'done' && <Icon name={rejected ? 'close' : 'check'} className="size-3" />}
              </span>
              <div className="min-w-0 flex-1 pb-3">
                <p className={`text-[15px] ${s.state === 'todo' ? 'text-ink/65' : 'font-semibold text-ink'}`}>{s.label}</p>
                {s.date && <p className="text-[13px] tabular-nums text-ink/65">{s.date}</p>}
              </div>
            </li>
          )
        })}
      </ol>

      {r.reason && <p className="rounded-ctl bg-page px-4 py-3 text-[13px] text-ink/75"><b className="text-ink">{t('track.reason')}:</b> <span dir="auto">{r.reason}</span></p>}
    </section>
  )
}

/** Lapis note with the authority's phone (public settings), below lg only. */
function MobileTrackContact() {
  const { t } = useTranslation('registration')
  const settings = useQuery({ queryKey: ['public-settings'], queryFn: publicApi.settings, staleTime: 5 * 60_000 })
  const phone = settings.data?.authority.phone
  if (!phone) return null
  return (
    <div className="flex items-center gap-3 rounded-card bg-info/10 px-4 py-2 text-[13px] text-info lg:hidden">
      <Icon name="phone" className="size-5 shrink-0" />
      <p className="min-w-0 flex-1">{t('mobile.track_contact')}</p>
      <a href={`tel:${phone}`} dir="ltr" className="inline-flex min-h-11 shrink-0 items-center font-semibold tabular-nums underline">{phone}</a>
    </div>
  )
}
