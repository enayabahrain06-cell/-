import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { publicApi, type RegistrationRequest } from '../../api/registration'
import { OrnamentDivider } from '../../components/ornaments'
import { Badge, Notice, PrimaryButton, TextInput, type Tone } from '../../components/ui'
import PublicLayout from '../../layouts/PublicLayout'
import { formatDate, formatNumber } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'

const TONE: Record<string, Tone> = { pending: 'gold', accepted: 'brand', waitlist: 'info', rejected: 'danger' }

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
      <div className="mx-auto max-w-lg space-y-5">
        <div className="text-center">
          <h1 className="font-display text-3xl text-ink">{t('track.title')}</h1>
          <p className="mt-1 text-ink/60">{t('track.subtitle')}</p>
          <OrnamentDivider align="center" className="mx-auto mt-3 text-gold-500" />
        </div>
        <form className="space-y-4 rounded-2xl border border-ink/8 bg-white p-6 shadow-sm" onSubmit={(e) => { e.preventDefault(); search.mutate() }}>
          <TextInput label={t('track.request_no')} value={requestNo} onChange={(e) => setRequestNo(e.target.value.toUpperCase())} dir="ltr" required className="[&_input]:font-mono [&_input]:tracking-wider" />
          <TextInput label={t('track.phone')} type="tel" inputMode="tel" dir="ltr" placeholder="3xxxxxxx" value={phone} onChange={(e) => setPhone(e.target.value)} required />
          <PrimaryButton type="submit" className="w-full" loading={search.isPending}>{t('track.search')}</PrimaryButton>
        </form>
        {notFound && <Notice tone="error">{t('track.not_found')}</Notice>}
        {found && (
          <section className="space-y-3 rounded-2xl border border-ink/8 bg-white p-6 shadow-sm" aria-live="polite">
            <div className="flex items-center justify-between gap-2">
              <p dir="auto" className="text-lg font-semibold text-ink">{found.full_name}</p>
              <Badge tone={TONE[found.status]}>{t(`status.${found.status}`)}</Badge>
            </div>
            <p className="text-ink/75">{t(`track.${found.status}_body`)}</p>
            {found.status === 'waitlist' && found.waitlist_position && <p className="font-medium text-gold-700">{t('track.position', { n: formatNumber(found.waitlist_position, locale) })}</p>}
            {found.reason && <p className="text-sm text-ink/70"><b>{t('track.reason')}:</b> <span dir="auto">{found.reason}</span></p>}
            <dl className="grid grid-cols-2 gap-3 border-t border-ink/6 pt-3 text-sm">
              <div><dt className="text-xs text-ink/50">{t('track.package')}</dt><dd dir="auto" className="text-ink">{found.package?.name}</dd></div>
              <div><dt className="text-xs text-ink/50">{t('track.submitted')}</dt><dd className="text-ink">{formatDate(found.created_at, locale)}</dd></div>
              {found.decided_at && <div><dt className="text-xs text-ink/50">{t('track.decided')}</dt><dd className="text-ink">{formatDate(found.decided_at, locale)}</dd></div>}
            </dl>
          </section>
        )}
        <p className="text-center text-sm"><Link to="/register" className="text-brand-700 hover:underline">{t('public.new_request')}</Link></p>
      </div>
    </PublicLayout>
  )
}
