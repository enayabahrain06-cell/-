import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { certificatesApi } from '../../api/certificates'
import { OrnamentDivider } from '../../components/ornaments'
import { LoadingState, SecondaryButton } from '../../components/ui'
import PublicLayout from '../../layouts/PublicLayout'
import { formatDate } from '../../lib/format'
import Icon from '../../components/Icon'

/** Public QR verification (no login): valid, revoked or not found. */
export default function VerifyCertificatePage() {
  const { token = '' } = useParams()
  const { t, i18n } = useTranslation('certificates')
  const locale = i18n.language
  const q = useQuery({
    queryKey: ['certificate-verify', token, locale],
    queryFn: () => certificatesApi.verify(token),
    retry: (count, e) => !(axios.isAxiosError(e) && e.response?.status === 404) && count < 2,
    enabled: token.length > 0,
  })
  const notFound = !token || (axios.isAxiosError(q.error) && q.error.response?.status === 404)
  const c = q.data

  return (
    <PublicLayout>
      <div className="mx-auto max-w-xl space-y-5">
        <div className="text-center">
          <h1 className="font-display text-3xl text-ink">{t('verify.title')}</h1>
          <p className="mt-1 text-ink/60">{t('verify.subtitle')}</p>
          <OrnamentDivider align="center" className="mx-auto mt-3 text-gold-500" />
        </div>

        {q.isLoading && token ? <LoadingState /> : notFound ? (
          <StateCard tone="muted" symbol="?" title={t('verify.not_found')} body={t('verify.not_found_body')} />
        ) : q.isError || !c ? (
          <div className="space-y-3 text-center">
            <StateCard tone="muted" symbol="!" title={t('verify.error')} />
            <SecondaryButton onClick={() => void q.refetch()}>{t('common:retry')}</SecondaryButton>
          </div>
        ) : (
          <section aria-live="polite" className={`overflow-hidden rounded-2xl border-2 bg-white shadow-sm ${c.valid ? 'border-brand-600/40' : 'border-danger/40'}`}>
            <div className={`flex items-center gap-4 px-6 py-5 ${c.valid ? 'bg-brand-50' : 'bg-danger/5'}`}>
              <span aria-hidden className={`grid size-14 shrink-0 place-items-center rounded-full text-white ${c.valid ? 'bg-brand-700' : 'bg-danger'}`}><Icon name={c.valid ? 'check' : 'close'} className="size-8" /></span>
              <div>
                <p className={`text-xl font-semibold ${c.valid ? 'text-brand-800' : 'text-danger'}`}>{c.status_label || t(c.valid ? 'verify.valid' : 'verify.revoked')}</p>
                <p className="text-sm text-ink/65">{t(c.valid ? 'verify.valid_body' : 'verify.revoked_body')}</p>
              </div>
            </div>
            <div className="border-4 border-double border-gold-400/60 m-4 rounded-xl p-5">
              <p className="text-center text-xs font-medium text-gold-700">{c.type_label}</p>
              <p dir="auto" className={`mt-1 text-center font-display text-2xl text-brand-900 ${c.valid ? '' : 'line-through decoration-danger/50'}`}>{c.title}</p>
              <p dir="auto" className="text-center text-ink/70">{c.achievement}</p>
              <OrnamentDivider align="center" className="mx-auto my-3 text-gold-500" />
              <dl className="grid grid-cols-2 gap-3 text-sm">
                <Field label={t('verify.student')} wide><span dir="auto" className="font-semibold">{c.student_name ?? '—'}</span></Field>
                {c.grade_label && <Field label={t('detail.grade')}>{c.grade_label}</Field>}
                <Field label={t('detail.serial')}><span className="font-mono tabular-nums">{c.certificate_no}</span></Field>
                <Field label={t('detail.issued_on')}>
                  {c.issued_on ? <><span className="block">{formatDate(c.issued_on, locale)}</span>{c.issued_on_hijri && <span className="block text-xs text-ink/50">{c.issued_on_hijri}</span>}</> : '—'}
                </Field>
                {c.revoked_at && <Field label={t('verify.revoked_on')}><span className="text-danger">{formatDate(c.revoked_at, locale)}</span></Field>}
                <Field label={t('verify.issued_by')} wide><span dir="auto">{c.authority}</span></Field>
              </dl>
            </div>
          </section>
        )}
      </div>
    </PublicLayout>
  )
}

function StateCard({ tone, symbol, title, body }: { tone: 'muted'; symbol: string; title: string; body?: string }) {
  return (
    <section role="alert" className={`rounded-2xl border bg-white p-6 text-center shadow-sm ${tone === 'muted' ? 'border-ink/10' : ''}`}>
      <span aria-hidden className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-ink/6 text-2xl font-bold text-ink/50">{symbol}</span>
      <p className="text-lg font-semibold text-ink">{title}</p>
      {body && <p className="mt-1 text-sm text-ink/60">{body}</p>}
    </section>
  )
}

function Field({ label, children, wide }: { label: string; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className={wide ? 'col-span-2' : ''}>
      <dt className="text-xs text-ink/50">{label}</dt>
      <dd className="text-ink">{children}</dd>
    </div>
  )
}
