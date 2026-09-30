import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { paymentsApi, saveBlob, type InvoiceRow } from '../../api/payments'
import { portalApi } from '../../api/portal'
import Icon from '../../components/Icon'
import { MEmpty, MList, MListSkeleton, MSegmented, Pill, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatMoney } from '../../lib/format'
import PortalLayout from './PortalLayout'
import { firstName, useChildFilter, usePortalOverview, usePortalRole } from './hooks'
import { ChildChips, KpiTile, PortalError } from './shared'

const INVOICE_TONE: Record<InvoiceRow['status'], PillTone> = { paid: 'ok', partial: 'warn', open: 'neutral', cancelled: 'neutral' }

/** Invoices and payments of the family, read-only (/my-invoices). Receipts download as the authenticated PDF. */
export default function InvoicesPage() {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const role = usePortalRole()
  const overview = usePortalOverview()
  const cards = overview.data?.students ?? []
  const { selected, select } = useChildFilter(cards)
  const [tab, setTab] = useState<'invoices' | 'payments'>('invoices')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<number | null>(null)
  const q = useQuery({ queryKey: ['portal-wallet', locale], queryFn: portalApi.wallet })

  const wallets = (q.data ?? []).filter((w) => selected === null || w.student.id === selected)
  const many = (q.data?.length ?? 0) > 1
  const outstanding = wallets.reduce((sum, w) => sum + w.outstanding_fils, 0)
  const invoices = wallets.flatMap((w) => w.invoices.map((i) => ({ ...i, who: w.student.full_name }))).sort((a, b) => b.due_date.localeCompare(a.due_date))
  const payments = wallets.flatMap((w) => w.payments.map((p) => ({ ...p, who: w.student.full_name }))).sort((a, b) => b.paid_at.localeCompare(a.paid_at))
  const lastPayment = payments[0]

  const receipt = async (id: number, no: string) => {
    setError(null)
    setBusy(id)
    try {
      saveBlob(await paymentsApi.receiptPdf(id), `${no}.pdf`, true)
    } catch (e) {
      setError(parseApiError(e).message)
    } finally {
      setBusy(null)
    }
  }

  const title = t('invoices.title')
  const body = (
    <div className="space-y-5">
      <ChildChips cards={cards} selected={selected} onSelect={select} />
      <div className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
        <KpiTile compact label={t('invoices.outstanding')} value={q.data ? formatMoney(outstanding, locale) : '—'} />
        <KpiTile compact label={t('invoices.last_payment')} value={lastPayment ? formatMoney(lastPayment.amount_fils, locale) : '—'}
          sub={lastPayment ? formatDate(lastPayment.paid_at, locale, { day: 'numeric', month: 'short', year: 'numeric' }) : undefined} />
      </div>

      <MSegmented label={title} value={tab} onChange={setTab}
        options={[{ value: 'invoices', label: t('invoices.tab_invoices') }, { value: 'payments', label: t('invoices.tab_payments') }]} />

      {error && <p role="alert" className="rounded-ctl bg-danger/10 px-3 py-2 text-[13px] text-danger">{error}</p>}

      {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading ? <MListSkeleton rows={4} /> : tab === 'invoices' ? (
        invoices.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="wallet" text={t('invoices.empty')} /></div> : (
          <MList label={t('invoices.tab_invoices')}>
            {invoices.map((i) => (
              <li key={i.id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] font-semibold text-ink" dir="auto">{i.description}</span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                    {many && <><bdi>{firstName(i.who)}</bdi> · </>}
                    <span dir="ltr">{i.invoice_no}</span> · <span className="tabular-nums">{t('invoices.due_on', { date: formatDate(i.due_date, locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}</span>
                  </span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-1">
                  <span className="text-[15px] font-semibold tabular-nums text-ink">{formatMoney(i.status === 'partial' ? i.outstanding_fils : i.amount_fils, locale)}</span>
                  <Pill tone={i.is_overdue ? 'err' : INVOICE_TONE[i.status]}>{i.is_overdue ? t('invoices.overdue') : i.status_label}</Pill>
                </span>
              </li>
            ))}
          </MList>
        )
      ) : payments.length === 0 ? <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="wallet" text={t('invoices.no_payments')} /></div> : (
        <MList label={t('invoices.tab_payments')}>
          {payments.map((p) => (
            <li key={p.id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[15px] font-semibold tabular-nums text-ink">{formatMoney(p.amount_fils, locale)}</span>
                <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                  {many && <><bdi>{firstName(p.who)}</bdi> · </>}
                  <span className="tabular-nums">{formatDate(p.paid_at, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span> · {p.method_label} · <span dir="ltr">{p.receipt_no}</span>
                </span>
              </span>
              <button type="button" onClick={() => void receipt(p.id, p.receipt_no)} disabled={busy === p.id}
                aria-label={t('invoices.receipt', { no: p.receipt_no })} title={t('invoices.receipt', { no: p.receipt_no })}
                className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700 disabled:opacity-60">
                <Icon name="download" className="size-5" />
              </button>
            </li>
          ))}
        </MList>
      )}
      <p className="text-[13px] text-ink/65">{t('invoices.read_only')}</p>
    </div>
  )

  // Guardians reach invoices from the bottom nav (a root tab); a student reaches them from the account page.
  return role === 'guardian'
    ? <PortalLayout><h1 className="mb-4 font-display text-[22px] leading-7 text-brand-900 lg:text-3xl">{title}</h1>{body}</PortalLayout>
    : <PortalLayout title={title} back="/my-account" breadcrumb={[{ label: t('tabs.account'), to: '/my-account' }, { label: title }]}>{body}</PortalLayout>
}
