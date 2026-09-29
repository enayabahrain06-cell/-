import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useCertificates, useCertT } from './context'
import { ActionButtons, IssueCertificateDialog, useCertificateActions } from './dialogs'
import { CertificateThumb, IssuedDate, StatusBadge, useCertificateOptions } from './shared'

/**
 * One recipient's certificates (a profile tab, a family or self-service page).
 * Staff see drafts and can issue; everyone else gets a read-only list (download + share).
 * `summaryTypes` adds a count per certificate type to the summary strip (label defaults to the type label).
 */
export default function RecipientCertificates({ type, id, summaryTypes = [] }: {
  type: string
  id: number | string
  summaryTypes?: { type: string; label?: string }[]
}) {
  const { t, locale } = useCertT()
  const { api, ui, formatDate, formatNumber } = useCertificates()
  const options = useCertificateOptions()
  const q = useQuery({ queryKey: ['certificates', 'recipient', type, String(id), locale], queryFn: () => api.forRecipient(type, id) })
  const actions = useCertificateActions()
  const [issuing, setIssuing] = useState(false)
  const [issued, setIssued] = useState<string | null>(null)
  const n = (v: number) => formatNumber(v, locale)

  if (q.isLoading) return <ui.LoadingState />
  if (q.isError || !q.data) return <ui.ErrorState onRetry={() => void q.refetch()} />
  const { data, summary, meta, recipient } = q.data
  const readOnly = meta.read_only
  const typeLabel = (k: string) => options.data?.types.find((o) => o.value === k)?.label ?? k

  return (
    <div className="space-y-4">
      <section aria-label={t('recipient.title')} className={`${ui.classes.surface} flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4`}>
        <SummaryStat value={n(summary.total)} label={t('recipient.total')} />
        {summaryTypes.map((s) => <SummaryStat key={s.type} value={n(summary.by_type[s.type] ?? 0)} label={s.label ?? typeLabel(s.type)} />)}
        {summary.drafts !== null && summary.drafts > 0 && <SummaryStat value={n(summary.drafts)} label={t('recipient.drafts')} tone="gold" />}
        <div className="min-w-0 flex-1 border-ink/8 sm:border-s sm:ps-6">
          <p className="text-xs text-ink/55">{t('recipient.latest')}</p>
          {summary.latest ? (
            <p className="truncate text-sm font-medium text-ink">
              <span dir="auto">{summary.latest.title}</span>
              {summary.latest.achievement && <span dir="auto" className="text-ink/60"> — {summary.latest.achievement}</span>}
              {summary.latest.issued_on && <span className="ms-2 text-xs font-normal text-ink/50">{formatDate(summary.latest.issued_on, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
            </p>
          ) : <p className="text-sm text-ink/50">{t('recipient.no_latest')}</p>}
        </div>
        {!readOnly && meta.can_issue && (
          <ui.PrimaryButton onClick={() => { setIssued(null); setIssuing(true) }}><ui.Icon name="certificate" className="size-4" />{t('actions.issue')}</ui.PrimaryButton>
        )}
      </section>

      {readOnly && data.length > 0 && <p className="text-sm text-ink/55">{t('recipient.read_only_hint')}</p>}
      {issued && <ui.Notice>{issued}</ui.Notice>}
      {actions.notice && <ui.Notice tone={actions.notice.tone}>{actions.notice.text}</ui.Notice>}

      {data.length === 0 ? (
        <ui.EmptyCard size="sm" icon="certificate" title={t('recipient.empty')} body={t('recipient.empty_body')} />
      ) : (
        <ul className="grid gap-4 *:min-w-0 sm:grid-cols-2 xl:grid-cols-3">
          {data.map((c) => {
            const revoked = c.status === 'revoked'
            return (
              <li key={c.id} className={`flex flex-col overflow-hidden rounded-2xl border bg-white shadow-sm ${revoked ? 'border-danger/25' : c.status === 'draft' ? 'border-dashed border-gold-500/50' : 'border-ink/8'}`}>
                <button type="button" onClick={() => actions.run('view', c)} disabled={!c.pdf_url} className="block p-3 pb-0 disabled:cursor-default" aria-label={`${t('actions.view')} — ${c.certificate_no}`}>
                  <CertificateThumb c={c} className={revoked ? 'grayscale' : ''} />
                </button>
                <div className="flex flex-1 flex-col gap-1.5 p-4 pt-3">
                  <div className="flex items-start justify-between gap-2">
                    <span className="text-xs font-medium text-gold-700">{c.type_label}</span>
                    <StatusBadge c={c} />
                  </div>
                  <p dir="auto" className={`font-semibold leading-snug text-ink ${revoked ? 'line-through decoration-danger/60' : ''}`}>
                    {c.title}{c.achievement && !c.title.includes(c.achievement) && <> — {c.achievement}</>}
                  </p>
                  {c.grade_label && <p className="text-sm text-ink/70">{t('detail.grade')}: <span className="font-medium text-ink">{c.grade_label}</span></p>}
                  <div className="text-sm text-ink/70"><IssuedDate c={c} /></div>
                  <p className="text-xs text-ink/45">{t('table.serial')}: <span className="font-mono tabular-nums">{c.certificate_no}</span></p>
                  {revoked && <p className="text-xs font-medium text-danger">{t('recipient.revoked_note')}{c.revoke_reason && <>: <span dir="auto">{c.revoke_reason}</span></>}</p>}
                  <div className="mt-auto border-t border-ink/6 pt-2.5">
                    <ActionButtons c={c} run={actions.run} busy={actions.busy} readOnly={readOnly} exclude={readOnly ? [] : ['edit', 'delete', 'revoke', 'approve']} />
                  </div>
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {actions.element}
      {issuing && <IssueCertificateDialog recipient={recipient} onClose={() => setIssuing(false)} onDone={(m) => { setIssuing(false); setIssued(m) }} />}
    </div>
  )
}

function SummaryStat({ value, label, tone }: { value: string; label: string; tone?: 'gold' }) {
  return (
    <div>
      <p className={`text-2xl font-semibold tabular-nums ${tone === 'gold' ? 'text-gold-700' : 'text-ink'}`}>{value}</p>
      <p className="text-xs text-ink/55">{label}</p>
    </div>
  )
}
