import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { paymentsApi, saveBlob, type InvoiceRow, type PaymentRow } from '../../api/payments'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import MPager from '../../components/mobile/MPager'
import MobileToast from '../../components/mobile/Toast'
import { Chip, ChipRow, MCard, MEmpty, MList, MListSkeleton, MRow, MSegmented, Pill, Skeleton, M_BTN_PRIMARY, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../lib/format'
import { useEmbed, useOwnParam } from '../../app/embed'

/**
 * Payments & wallets below lg (mobile-redesign-spec.md §6.21). The dialogs and their form logic stay in
 * PaymentsHomePage; the lists use the same query keys as the desktop tabs, so the two layouts share one cache.
 */

type View = 'recent' | 'overdue' | 'wallets'
type Dialog = 'pay' | 'invoice' | 'refund' | 'adjust'
const monthStart = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toLocaleDateString('en-CA') }
const today = () => new Date().toLocaleDateString('en-CA')
const yearStart = () => new Date(new Date().getFullYear(), 0, 1).toLocaleDateString('en-CA')
const monthKey = (offset = 0) => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth() + offset, 15).toLocaleDateString('en-CA').slice(0, 7) }

/** `?tab=` is shared with desktop: payments → recent, invoices → overdue; wallets is the mobile-only view. */
const VIEW_OF_TAB: Record<string, View> = { invoices: 'overdue', wallets: 'wallets' }
/** Inside a nav_v2 tab the mode picks the view: الفواتير → overdue, التقرير → wallets (the mobile balances list). */
const HOSTED_VIEW: Record<string, View> = { invoices: 'overdue', report: 'wallets' }
const TAB_OF_VIEW: Record<View, string> = { recent: 'payments', overdue: 'invoices', wallets: 'wallets' }

export default function MobilePayments({ onDialog, notice, onNoticeDone }: { onDialog: (d: Dialog) => void; notice: string | null; onNoticeDone: () => void }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const [menu, setMenu] = useState(false)
  const finance = can('reports.view') || can('wallets.view')
  const views: View[] = ['recent', 'overdue', ...(finance ? ['wallets' as const] : [])]
  const fromUrl = (host ? HOSTED_VIEW : VIEW_OF_TAB)[ownTab ?? ''] ?? 'recent'
  const view: View = views.includes(fromUrl) ? fromUrl : 'recent'
  const setView = (v: View) => setParams({ tab: TAB_OF_VIEW[v] }, { replace: true })

  // Same key as FinanceOverview (this year), so the desktop overview and these KPIs are one request.
  const fParams = { from: yearStart(), to: today() }
  const fin = useQuery({ queryKey: ['finance', fParams, locale], queryFn: () => paymentsApi.finance(fParams), enabled: finance })

  const extra = [can('payments.record') && 'invoice', can('refunds.manage') && 'refund', can('wallets.adjust') && 'adjust'].filter(Boolean) as Dialog[]
  const EXTRA_LABEL: Record<string, string> = { invoice: t('new_invoice'), refund: t('new_refund'), adjust: t('adjust') }
  const EXTRA_ICON: Record<string, string> = { invoice: 'table', refund: 'refresh', adjust: 'edit' }

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage title={t('title')} back="/" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title') }]}
        actions={extra.length > 0 ? <HeaderAction icon="more" label={t('mobile.more_actions')} onClick={() => setMenu(true)} /> : undefined} />
      <MobileToast message={notice} onDone={onNoticeDone} />

      {finance && <Kpis loading={fin.isLoading} data={fin.data?.data} onOverdue={() => setView('overdue')} />}

      {!host && <MSegmented label={t('title')} value={view} onChange={setView} options={views.map((v) => ({ value: v, label: t(`mobile.views.${v}`) }))} />}

      {view === 'recent' && <Recent />}
      {view === 'overdue' && <Overdue />}
      {view === 'wallets' && (
        fin.isLoading ? <MListSkeleton rows={5} /> : !fin.data?.data.outstanding.length ? <MCard><MEmpty icon="wallet" text={t('mobile.no_balances')} /></MCard> : (
          <MList label={t('mobile.views.wallets')}>
            {fin.data.data.outstanding.map((s) => (
              <MRow key={s.student_id} to={`/students/${s.student_id}?tab=wallet`} title={s.full_name}
                caption={<span dir="ltr" className="tabular-nums">{s.student_no}</span>}
                trailing={<span className="shrink-0 text-[15px] font-semibold tabular-nums text-danger">{formatMoney(Math.abs(s.balance_fils), locale)}</span>} />
            ))}
          </MList>
        )
      )}

      {view === 'recent' && <p className="flex items-start gap-2 text-[13px] text-ink/65"><Icon name="table" className="mt-0.5 size-4 shrink-0" />{t('mobile.receipt_note')}</p>}

      {can('payments.record') && (
        <StickyActionBar>
          <button type="button" onClick={() => onDialog('pay')} className={`${M_BTN_PRIMARY} flex-1`}><Icon name="plus" className="size-5" />{t('record')}</button>
        </StickyActionBar>
      )}

      <BottomSheet open={menu} onClose={() => setMenu(false)} title={t('mobile.more_actions')}>
        <MList>
          {extra.map((d) => (
            <li key={d}>
              <button type="button" onClick={() => { setMenu(false); onDialog(d) }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink">
                <Icon name={EXTRA_ICON[d]} className="size-5 text-brand-700" />{EXTRA_LABEL[d]}
              </button>
            </li>
          ))}
        </MList>
      </BottomSheet>
    </div>
  )
}

function Kpis({ loading, data, onOverdue }: { loading: boolean; data?: { totals: { outstanding: number; students_due: number }; by_month: { period: string; collected: number }[] }; onOverdue: () => void }) {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  if (loading || !data) {
    return <div aria-hidden className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">{[0, 1].map((i) => <div key={i} className={`${M_CARD} space-y-2 p-4`}><Skeleton className="h-3 w-2/3" /><Skeleton className="h-7 w-3/4" /><Skeleton className="h-3 w-1/2" /></div>)}</div>
  }
  const cur = data.by_month.find((m) => m.period === monthKey())?.collected ?? 0
  const prev = data.by_month.find((m) => m.period === monthKey(-1))?.collected ?? 0
  const delta = prev > 0 ? Math.round(((cur - prev) / prev) * 100) : null
  return (
    <div className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
      <div className={`${M_CARD} min-w-0 p-4`}>
        <p className="flex items-start gap-1.5 text-xs font-semibold text-ink/65"><Icon name="payments" className="size-4 shrink-0 text-brand-700" /><span>{t('mobile.collected_month')}</span></p>
        <p className="mt-1.5 truncate text-xl font-semibold tabular-nums text-ink">{formatMoney(cur, locale)}</p>
        <p className="mt-0.5 text-xs tabular-nums text-ink/65">
          {delta === null ? t('mobile.no_prev') : t(delta >= 0 ? 'mobile.delta_up' : 'mobile.delta_down', { n: formatPercent(Math.abs(delta), locale) })}
        </p>
      </div>
      <button type="button" onClick={onOverdue} className="min-w-0 rounded-card border border-gold-500/30 bg-gold-500/12 p-4 text-start shadow-card">
        <p className="flex items-start gap-1.5 text-xs font-semibold text-gold-700"><Icon name="alert" className="size-4 shrink-0" /><span>{t('report.outstanding')}</span></p>
        <p className="mt-1.5 truncate text-xl font-semibold tabular-nums text-ink">{formatMoney(data.totals.outstanding, locale)}</p>
        <p className="mt-0.5 flex items-center gap-1 text-xs tabular-nums text-gold-700">
          <span className="truncate">{t('mobile.students_due', { n: formatNumber(data.totals.students_due, locale) })}</span>
          <Icon name="chevron" className="size-3.5 shrink-0 rtl:rotate-180" />
        </p>
      </button>
    </div>
  )
}

function Recent() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<PaymentRow | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const from = monthStart()
  const to = today()
  // Same key and parameters as the desktop payments tab's default (this month, all methods).
  const q = useQuery({ queryKey: ['payments', from, to, '', page], queryFn: () => paymentsApi.list({ from, to, method: undefined, page, per_page: 20 }), placeholderData: keepPreviousData })
  const resend = useMutation({ mutationFn: (id: number) => paymentsApi.resendReceipt(id), onSuccess: () => { setOpen(null); setMsg(t('resent')) } })

  if (q.isLoading) return <MListSkeleton rows={6} />
  if (q.isError || !q.data) return <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={() => void q.refetch()} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
  return (
    <div className="space-y-3">
      <MobileToast message={msg} onDone={() => setMsg(null)} />
      {q.data.data.length === 0 ? <MCard><MEmpty icon="payments" text={t('mobile.empty_month')} /></MCard> : (
        <ul aria-label={t('mobile.views.recent')} className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
          {q.data.data.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => setOpen(p)} className="flex min-h-16 w-full items-center gap-3 px-4 py-2.5 text-start active:bg-brand-50/60">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{p.student?.full_name ?? '—'}</bdi></span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                    <span className="tabular-nums">{formatDate(p.paid_at, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</span> · {t(`methods.${p.method}`)}
                    {p.allocations?.[0] && <> · <bdi>{p.allocations[0].description}</bdi></>}
                  </span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-1">
                  <span className="text-[15px] font-semibold tabular-nums text-brand-700">{formatMoney(p.amount_fils, locale)}</span>
                  <Pill tone="ok">{t('mobile.paid')}</Pill>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
      <MPager page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />

      <BottomSheet open={!!open} onClose={() => setOpen(null)} title={open?.student?.full_name ?? t('receipt')}
        footer={open ? <>
          <button type="button" onClick={async () => saveBlob(await paymentsApi.receiptPdf(open.id), `${open.receipt_no}.pdf`, true)} className={`${M_BTN_SECONDARY} h-12 flex-1`}><Icon name="printer" className="size-5" />{t('receipt')}</button>
          <button type="button" disabled={resend.isPending} onClick={() => resend.mutate(open.id)} className={`${M_BTN_SECONDARY} h-12 flex-1`}><Icon name="messages" className="size-5" />{t('mobile.resend')}</button>
        </> : undefined}>
        {open && (
          <dl className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white text-[15px]">
            <Fact label={t('form.amount')}><span className="font-semibold tabular-nums text-brand-700">{formatMoney(open.amount_fils, locale)}</span></Fact>
            <Fact label={t('receipt')}><span dir="ltr" className="font-mono text-[13px]">{open.receipt_no}</span></Fact>
            <Fact label={t('form.method')}>{t(`methods.${open.method}`)}{open.reference && <> · <span dir="ltr">{open.reference}</span></>}</Fact>
            <Fact label={t('form.paid_at')}><span className="tabular-nums">{formatDate(open.paid_at, locale, { day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</span></Fact>
            {open.received_by && <Fact label={t('mobile.received_by')}><bdi>{open.received_by}</bdi></Fact>}
            {open.allocations && open.allocations.length > 0 && <Fact label={t('mobile.settled')}><span dir="ltr" className="text-[13px]">{open.allocations.map((a) => a.invoice_no).join(', ')}</span></Fact>}
            {open.note && <Fact label={t('form.note')}><bdi>{open.note}</bdi></Fact>}
            {open.student && (
              <div className="px-4 py-1">
                <Link to={`/students/${open.student.id}?tab=wallet`} className="flex min-h-11 items-center gap-1 text-[15px] font-semibold text-info">{t('mobile.open_wallet')}<Icon name="chevron" className="size-4 rtl:rotate-180" /></Link>
              </div>
            )}
          </dl>
        )}
      </BottomSheet>
    </div>
  )
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex min-h-12 items-center justify-between gap-3 px-4 py-2">
      <dt className="shrink-0 text-[13px] text-ink/65">{label}</dt>
      <dd className="min-w-0 text-end text-ink">{children}</dd>
    </div>
  )
}

type InvFilter = 'overdue' | 'open' | 'partial' | 'paid' | 'all'
const INV_TONE: Record<InvoiceRow['status'], PillTone> = { open: 'warn', partial: 'info', paid: 'ok', cancelled: 'neutral' }

function Overdue() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const [filter, setFilter] = useState<InvFilter>('overdue')
  const [page, setPage] = useState(1)
  const status = filter === 'overdue' || filter === 'all' ? '' : filter
  const overdue = filter === 'overdue'
  // Same key shape as the desktop invoices tab: ['invoices', status, overdue, page].
  const q = useQuery({ queryKey: ['invoices', status, overdue, page], queryFn: () => paymentsApi.invoices({ status: status || undefined, overdue: overdue || undefined, page, per_page: 20 }), placeholderData: keepPreviousData })
  const pick = (f: InvFilter) => { setFilter(f); setPage(1) }

  return (
    <div className="space-y-3">
      <ChipRow label={t('invoices.all')}>
        {(['overdue', 'open', 'partial', 'paid', 'all'] as const).map((f) => (
          <Chip key={f} active={filter === f} onClick={() => pick(f)}>{t(`mobile.inv.${f}`)}</Chip>
        ))}
      </ChipRow>
      {q.isLoading ? <MListSkeleton rows={6} /> : q.isError || !q.data ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={() => void q.refetch()} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
      ) : q.data.data.length === 0 ? <MCard><MEmpty icon="payments" text={overdue ? t('mobile.no_overdue') : t('empty')} /></MCard> : (
        <MList label={t('tabs.invoices')}>
          {q.data.data.map((inv) => {
            const late = inv.is_overdue && inv.status !== 'paid' && inv.status !== 'cancelled'
            return (
              <MRow key={inv.id} to={inv.student ? `/students/${inv.student.id}?tab=wallet` : undefined}
                title={inv.student?.full_name ?? inv.description}
                caption={<><span className="tabular-nums">{t('invoices.due', { date: formatDate(inv.due_date, locale, { day: 'numeric', month: 'short' }) })}</span>{inv.student && <> · <bdi>{inv.package?.name ?? inv.description}</bdi></>}</>}
                trailing={
                  <span className="flex shrink-0 flex-col items-end gap-1">
                    <span className={`text-[15px] font-semibold tabular-nums ${late ? 'text-danger' : 'text-ink'}`}>{formatMoney(inv.status === 'paid' ? inv.amount_fils : inv.outstanding_fils, locale)}</span>
                    <Pill tone={late ? 'err' : INV_TONE[inv.status]}>{late ? t('invoices.late') : t(`mobile.inv_status.${inv.status}`)}</Pill>
                  </span>
                } />
            )
          })}
        </MList>
      )}
      {q.data && <MPager page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />}
    </div>
  )
}
