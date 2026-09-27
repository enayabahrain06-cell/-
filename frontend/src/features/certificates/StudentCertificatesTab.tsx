import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { certificatesApi } from '../../api/certificates'
import type { StudentSummary } from '../../api/students'
import Icon from '../../components/Icon'
import { ErrorState, LoadingState, Notice, PrimaryButton, EmptyCard, SURFACE } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { ActionButtons, IssueCertificateDialog, useCertificateActions } from './CertificateDialogs'
import { CertificateThumb, IssuedDate, StatusBadge } from './shared'

/**
 * Student profile "Certificates" tab, also used on the family home.
 * Staff see drafts and can issue; the student and guardian get a read-only list (download + WhatsApp share).
 */
export default function StudentCertificatesTab({ studentId, student }: { studentId: number; student?: StudentSummary | null }) {
  const { t, i18n } = useTranslation('certificates')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['student-certificates', studentId, locale], queryFn: () => certificatesApi.forStudent(studentId) })
  const actions = useCertificateActions()
  const [issuing, setIssuing] = useState(false)
  const [issued, setIssued] = useState<string | null>(null)
  const n = (v: number) => formatNumber(v, locale)

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  const { data, summary, meta } = q.data
  const readOnly = meta.read_only
  // The issue dialog needs the student card; the list payload carries it on every certificate.
  const card = student ?? data[0]?.student ?? null

  return (
    <div className="space-y-4">
      <section aria-label={t('student.title')} className={`${SURFACE} flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4`}>
        <SummaryStat value={n(summary.total)} label={t('student.total')} />
        <SummaryStat value={n(summary.memorization)} label={t('student.memorization')} />
        <SummaryStat value={n(summary.excellence)} label={t('student.excellence')} />
        {summary.drafts !== null && summary.drafts > 0 && <SummaryStat value={n(summary.drafts)} label={t('student.drafts')} tone="gold" />}
        <div className="min-w-0 flex-1 border-ink/8 sm:border-s sm:ps-6">
          <p className="text-xs text-ink/55">{t('student.latest')}</p>
          {summary.latest ? (
            <p className="truncate text-sm font-medium text-ink">
              <span dir="auto">{summary.latest.title}</span>
              {summary.latest.achievement && <span dir="auto" className="text-ink/60"> — {summary.latest.achievement}</span>}
              {summary.latest.issued_on && <span className="ms-2 text-xs font-normal text-ink/50">{formatDate(summary.latest.issued_on, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
            </p>
          ) : <p className="text-sm text-ink/50">{t('student.no_latest')}</p>}
        </div>
        {!readOnly && meta.can_issue && card && (
          <PrimaryButton onClick={() => { setIssued(null); setIssuing(true) }}><Icon name="certificate" className="size-4" />{t('actions.issue')}</PrimaryButton>
        )}
      </section>

      {readOnly && data.length > 0 && <p className="text-sm text-ink/55">{t('student.read_only_hint')}</p>}
      {issued && <Notice>{issued}</Notice>}
      {actions.notice && <Notice tone={actions.notice.tone}>{actions.notice.text}</Notice>}

      {data.length === 0 ? (
        <EmptyCard size="sm" icon="certificate" title={t('student.empty')} body={t('student.empty_body')} />
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
                  {revoked && <p className="text-xs font-medium text-danger">{t('student.revoked_note')}{c.revoke_reason && <>: <span dir="auto">{c.revoke_reason}</span></>}</p>}
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
      {issuing && card && <IssueCertificateDialog student={card} onClose={() => setIssuing(false)} onDone={(m) => { setIssuing(false); setIssued(m) }} />}
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
