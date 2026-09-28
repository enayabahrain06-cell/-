import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { METHODS, paymentsApi, saveBlob, type FinanceReport } from '../../api/payments'
import { packagesApi } from '../../api/registration'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { EmptyState, PageBand } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, TableWrap, TextArea, TextInput, type Tone, SURFACE, EmptyCard, FilterBar, ROW_MAIN } from '../../components/ui'
import { formatDate, formatMoney, formatNumber } from '../../lib/format'
import { AdjustDialog, InvoiceDialog, RecordPaymentDialog, RefundDialog } from './PaymentDialogs'

type Tab = 'payments' | 'invoices' | 'refunds' | 'report'
const monthStart = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toLocaleDateString('en-CA') }
const today = () => new Date().toLocaleDateString('en-CA')

export default function PaymentsHomePage() {
  const { t } = useTranslation('payments')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs: Tab[] = ['payments', 'invoices', 'refunds', ...(can('reports.view') || can('wallets.view') ? ['report' as const] : [])]
  const tab = (tabs as string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'payments'
  const [dialog, setDialog] = useState<'pay' | 'invoice' | 'refund' | 'adjust' | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const done = (m?: string) => { setDialog(null); setNotice(m ?? null) }

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')}
        actions={<div className="flex flex-wrap gap-2">
          {can('payments.record') && (
            <button type="button" onClick={() => setDialog('pay')} className="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-brand-800 shadow-sm hover:bg-white/90">
              + {t('record')}
            </button>
          )}
        </div>} />
      <div className="flex flex-wrap items-center gap-2">
        <Segmented name="pay-tab" label={t('title')} value={tab} options={tabs.map((k) => ({ value: k, label: t(`tabs.${k}`) }))} onChange={(v) => setParams({ tab: v }, { replace: true })} />
        <div className="ms-auto flex flex-wrap gap-2">
          {can('payments.record') && <SecondaryButton onClick={() => setDialog('invoice')}>{t('new_invoice')}</SecondaryButton>}
          {can('refunds.manage') && <SecondaryButton onClick={() => setDialog('refund')}>{t('new_refund')}</SecondaryButton>}
          {can('wallets.adjust') && <SecondaryButton onClick={() => setDialog('adjust')}>{t('adjust')}</SecondaryButton>}
        </div>
      </div>
      {notice && <Notice>{notice}</Notice>}
      {tab === 'payments' && <Payments />}
      {tab === 'invoices' && <Invoices />}
      {tab === 'refunds' && <Refunds />}
      {tab === 'report' && <Report />}
      {dialog === 'pay' && <RecordPaymentDialog onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'invoice' && <InvoiceDialog onClose={() => setDialog(null)} onDone={() => done()} />}
      {dialog === 'refund' && <RefundDialog onClose={() => setDialog(null)} onDone={() => done()} />}
      {dialog === 'adjust' && <AdjustDialog onClose={() => setDialog(null)} onDone={() => done()} />}
    </div>
  )
}

function Period({ from, to, onChange, children }: { from: string; to: string; onChange: (from: string, to: string) => void; children?: React.ReactNode }) {
  const { t } = useTranslation('payments')
  return (
    <FilterBar>
      <TextInput className="sm:w-40" label={t('from')} type="date" value={from} onChange={(e) => onChange(e.target.value, to)} />
      <TextInput className="sm:w-40" label={t('to')} type="date" value={to} onChange={(e) => onChange(from, e.target.value)} />
      {children}
    </FilterBar>
  )
}

function Payments() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const [from, setFrom] = useState(monthStart())
  const [to, setTo] = useState(today())
  const [method, setMethod] = useState('')
  const [page, setPage] = useState(1)
  const [msg, setMsg] = useState<string | null>(null)
  const q = useQuery({ queryKey: ['payments', from, to, method, page], queryFn: () => paymentsApi.list({ from, to, method: method || undefined, page, per_page: 20 }), placeholderData: keepPreviousData })
  const resend = useMutation({ mutationFn: (id: number) => paymentsApi.resendReceipt(id), onSuccess: () => setMsg(t('resent')) })

  return (
    <div className="space-y-4">
      <Period from={from} to={to} onChange={(f, tt) => { setFrom(f); setTo(tt); setPage(1) }}>
        <SelectField className="w-44" label={t('form.method')} value={method} onChange={(e) => { setMethod(e.target.value); setPage(1) }} options={[{ value: '', label: t('all_methods') }, ...METHODS.map((m) => ({ value: m, label: t(`methods.${m}`) }))]} />
      </Period>
      {msg && <Notice>{msg}</Notice>}
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="payments" title={t('empty')} />
      ) : (
        <>
          <ul className={`${SURFACE} divide-y divide-ink/6`}>
            {q.data.data.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
                <span className="w-24 text-ink/60">{formatDate(p.paid_at, locale, { day: 'numeric', month: 'short' })}</span>
                <span className={ROW_MAIN}>
                  {p.student ? <Link to={`/students/${p.student.id}?tab=wallet`} dir="auto" className="font-medium text-ink hover:text-brand-700">{p.student.full_name}</Link> : '—'}
                  <span className="block text-xs text-ink/50"><span dir="ltr" className="font-mono">{p.receipt_no}</span>{p.received_by && <> · {t('received_by', { name: p.received_by })}</>}</span>
                  {p.allocations && p.allocations.length > 0 && <span className="block text-xs text-ink/50">{t('settled', { list: p.allocations.map((a) => a.invoice_no).join(', ') })}</span>}
                </span>
                <Badge tone="muted">{t(`methods.${p.method}`)}</Badge>
                <span className="w-28 text-end font-semibold tabular-nums text-brand-700">{formatMoney(p.amount_fils, locale)}</span>
                <span className="flex gap-1">
                  <button type="button" className="rounded-lg p-1.5 text-ink/60 hover:bg-ink/5" title={t('receipt')} aria-label={t('receipt')} onClick={async () => saveBlob(await paymentsApi.receiptPdf(p.id), `${p.receipt_no}.pdf`, true)}><Icon name="table" className="size-4" /></button>
                  <button type="button" className="rounded-lg p-1.5 text-ink/60 hover:bg-ink/5" title={t('resend')} aria-label={t('resend')} onClick={() => resend.mutate(p.id)}><Icon name="messages" className="size-4" /></button>
                </span>
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />
        </>
      )}
    </div>
  )
}

const INV_TONE: Record<string, Tone> = { open: 'gold', partial: 'info', paid: 'brand', cancelled: 'muted' }

function Invoices() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const [status, setStatus] = useState('')
  const [overdue, setOverdue] = useState(false)
  const [page, setPage] = useState(1)
  const [cancelling, setCancelling] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const q = useQuery({ queryKey: ['invoices', status, overdue, page], queryFn: () => paymentsApi.invoices({ status: status || undefined, overdue: overdue || undefined, page, per_page: 20 }), placeholderData: keepPreviousData })
  const cancel = useMutation({ mutationFn: () => paymentsApi.cancelInvoice(cancelling!, note), onSuccess: () => { setCancelling(null); setNote(''); void qc.invalidateQueries({ queryKey: ['invoices'] }) } })
  const m = (f: number) => formatMoney(f, locale)

  return (
    <div className="space-y-4">
      <div className={`${SURFACE} flex flex-wrap items-center gap-3 p-4`}>
        <SelectField className="w-48" label={t('invoices.all')} hideLabel value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          options={[{ value: '', label: t('invoices.all') }, ...(['open', 'partial', 'paid', 'cancelled'] as const).map((s) => ({ value: s, label: t(`invoices.status.${s}`) }))]} />
        <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={overdue} onChange={(e) => { setOverdue(e.target.checked); setPage(1) }} />{t('invoices.overdue')}</label>
      </div>
      {q.isLoading ? <LoadingState /> : !q.data?.data.length ? (
        <EmptyCard icon="payments" title={t('empty')} />
      ) : (
        <>
          <ul className={`${SURFACE} divide-y divide-ink/6`}>
            {q.data.data.map((inv) => (
              <li key={inv.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
                <span className={ROW_MAIN}>
                  {inv.student && <Link to={`/students/${inv.student.id}?tab=wallet`} dir="auto" className="font-medium text-ink hover:text-brand-700">{inv.student.full_name}</Link>}
                  <span dir="auto" className="block text-ink/70">{inv.description}</span>
                  <span className="block text-xs text-ink/50"><span dir="ltr" className="font-mono">{inv.invoice_no}</span> · {t('invoices.due', { date: formatDate(inv.due_date, locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}</span>
                </span>
                <Badge tone={inv.is_overdue ? 'danger' : INV_TONE[inv.status]}>{inv.is_overdue ? t('invoices.late') : t(`invoices.status.${inv.status}`)}</Badge>
                <span className="w-40 text-end tabular-nums text-ink/80">{t('invoices.paid_of', { paid: m(inv.paid_fils), amount: m(inv.amount_fils) })}</span>
                {can('wallets.adjust') && inv.status !== 'cancelled' && inv.paid_fils === 0 && <button type="button" className="text-xs text-danger hover:underline" onClick={() => setCancelling(inv.id)}>{t('invoices.cancel')}</button>}
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />
        </>
      )}
      {cancelling && (
        <Modal title={t('invoices.cancel')} onClose={() => setCancelling(null)}
          footer={<><SecondaryButton onClick={() => setCancelling(null)}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={note.length < 3} loading={cancel.isPending} onClick={() => cancel.mutate()}>{t('invoices.cancel')}</PrimaryButton></>}>
          <TextArea label={t('invoices.cancel_note')} value={note} onChange={(e) => setNote(e.target.value)} dir="auto" />
        </Modal>
      )}
    </div>
  )
}

function Refunds() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const [page, setPage] = useState(1)
  const q = useQuery({ queryKey: ['refunds', page], queryFn: () => paymentsApi.refunds({ page, per_page: 20 }) })
  if (q.isLoading) return <LoadingState />
  if (!q.data?.data.length) return <EmptyCard icon="payments" title={t('empty')} />
  return (
    <div className="space-y-3">
      <ul className={`${SURFACE} divide-y divide-ink/6`}>
        {q.data.data.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
            <span className="w-24 text-ink/60">{formatDate(r.paid_at, locale, { day: 'numeric', month: 'short' })}</span>
            <span className={ROW_MAIN}><Link to={`/students/${r.student.id}?tab=wallet`} dir="auto" className="font-medium text-ink hover:text-brand-700">{r.student.full_name}</Link>
              <span dir="auto" className="block text-xs text-ink/50">{r.note}{r.approved_by && <> · {r.approved_by}</>}</span></span>
            <Badge tone="muted">{t(`methods.${r.method}`)}</Badge>
            <span className="w-28 text-end font-semibold tabular-nums text-danger">{formatMoney(-r.amount_fils, locale)}</span>
          </li>
        ))}
      </ul>
      <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />
    </div>
  )
}

/** Two-series grouped bars (validated pair #2E8B57 / #B8872E; legend, tooltip and table view carry identity). */
const SERIES = [{ key: 'collected', color: '#2E8B57' }, { key: 'refunded', color: '#B8872E' }] as const

function Report() {
  const { t, i18n } = useTranslation('payments')
  const locale = i18n.language
  const { hasRole, user } = useAuth()
  const [f, setF] = useState({ from: new Date(new Date().getFullYear(), 0, 1).toLocaleDateString('en-CA'), to: today(), gender: '', package_id: '' })
  const [asTable, setAsTable] = useState(false)
  const packages = useQuery({ queryKey: ['packages', locale], queryFn: () => packagesApi.list() })
  const params = { from: f.from, to: f.to, gender: f.gender || undefined, package_id: f.package_id || undefined }
  const q = useQuery({ queryKey: ['finance', params, locale], queryFn: () => paymentsApi.finance(params), placeholderData: keepPreviousData })
  const both = hasRole('super_admin') || !user?.track || user.track === 'both'
  const m = (v: number) => formatMoney(v, locale)
  const exportAs = async (fmt: 'xlsx' | 'pdf') => saveBlob(await paymentsApi.financeExport(params, fmt), `finance-report.${fmt}`, fmt === 'pdf')

  return (
    <div className="space-y-4">
      <Period from={f.from} to={f.to} onChange={(from, to) => setF({ ...f, from, to })}>
        {both && <SelectField className="w-40" label={t('report.all_tracks')} value={f.gender} onChange={(e) => setF({ ...f, gender: e.target.value })}
          options={[{ value: '', label: t('report.all_tracks') }, { value: 'male', label: t('report.boys') }, { value: 'female', label: t('report.girls') }]} />}
        <SelectField className="w-56" label={t('report.all_packages')} value={f.package_id} onChange={(e) => setF({ ...f, package_id: e.target.value })}
          options={[{ value: '', label: t('report.all_packages') }, ...(packages.data?.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} />
        <div className="ms-auto flex gap-2">
          <SecondaryButton onClick={() => void exportAs('xlsx')}><Icon name="table" className="size-4" />{t('report.export_xlsx')}</SecondaryButton>
          <SecondaryButton onClick={() => void exportAs('pdf')}><Icon name="reports" className="size-4" />{t('report.export_pdf')}</SecondaryButton>
        </div>
      </Period>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : <ReportBody r={q.data} m={m} locale={locale} asTable={asTable} setAsTable={setAsTable} />}
    </div>
  )
}

function ReportBody({ r, m, locale, asTable, setAsTable }: { r: FinanceReport; m: (v: number) => string; locale: string; asTable: boolean; setAsTable: (v: boolean) => void }) {
  const { t } = useTranslation('payments')
  const d = r.data
  const rtl = locale === 'ar'
  const months = rtl ? [...d.by_month].reverse() : d.by_month
  const tiles: [string, string, string?][] = [[t('report.collected'), m(d.totals.collected)], [t('report.refunded'), m(d.totals.refunded)], [t('report.net'), m(d.totals.net)], [t('report.outstanding'), m(d.totals.outstanding), `${t('report.students_due')}: ${formatNumber(d.totals.students_due, locale)}`]]

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {tiles.map(([label, value, hint]) => (
          <div key={label} className={`${SURFACE} p-4`}>
            <p className="text-sm text-ink/60">{label}</p>
            <p className="mt-1 text-xl font-semibold tabular-nums text-ink">{value}</p>
            {hint && <p className="text-xs text-ink/50">{hint}</p>}
          </div>
        ))}
      </div>

      <Card>
        <CardTitle actions={<SecondaryButton onClick={() => setAsTable(!asTable)}><Icon name={asTable ? 'chart' : 'table'} className="size-4" />{asTable ? t('report.show_chart') : t('report.show_table')}</SecondaryButton>}>{t('report.chart_title')}</CardTitle>
        <ul className="mb-2 flex gap-4 text-sm text-ink/70">
          {SERIES.map((s) => <li key={s.key} className="inline-flex items-center gap-1.5"><span aria-hidden className="size-2.5 rounded-sm" style={{ background: s.color }} />{t(`report.${s.key}`)}</li>)}
        </ul>
        {d.by_month.length === 0 ? <EmptyState size="sm" icon="payments" title={t('report.empty')} /> : asTable ? (
          <TableWrap>
            <table className="w-full min-w-[20rem] text-sm">
              <thead><tr className="border-b border-ink/10 text-ink/55"><th className="py-2 text-start font-medium">{t('report.month')}</th><th className="py-2 text-end font-medium">{t('report.collected')}</th><th className="py-2 text-end font-medium">{t('report.refunded')}</th></tr></thead>
              <tbody>{d.by_month.map((x) => <tr key={x.period} className="border-b border-ink/5"><td className="py-2">{formatDate(`${x.period}-15`, locale, { month: 'long', year: 'numeric' })}</td><td className="py-2 text-end tabular-nums">{m(x.collected)}</td><td className="py-2 text-end tabular-nums">{m(x.refunded)}</td></tr>)}</tbody>
            </table>
          </TableWrap>
        ) : (
          <div className="h-64" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={months} barGap={2} barCategoryGap="30%">
                <CartesianGrid vertical={false} stroke="#1B2B28" strokeOpacity={0.07} />
                <XAxis dataKey="period" tickLine={false} axisLine={{ stroke: '#1B2B28', strokeOpacity: 0.15 }} tick={{ fill: '#1B2B28', fillOpacity: 0.55, fontSize: 12 }} tickFormatter={(v: string) => formatDate(`${v}-15`, locale, { month: 'short' })} />
                <YAxis orientation={rtl ? 'right' : 'left'} tickLine={false} axisLine={false} width={56} tick={{ fill: '#1B2B28', fillOpacity: 0.55, fontSize: 12 }} tickFormatter={(v: number) => formatNumber(v / 1000, locale, { maximumFractionDigits: 0 })} />
                <Tooltip cursor={{ fill: '#1B2B28', fillOpacity: 0.05 }} formatter={(v, name) => [m(Number(v)), t(`report.${String(name)}`)]} labelFormatter={(v) => formatDate(`${String(v)}-15`, locale, { month: 'long', year: 'numeric' })} />
                {SERIES.map((s) => <Bar key={s.key} dataKey={s.key} fill={s.color} radius={[4, 4, 0, 0]} isAnimationActive={false} />)}
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </Card>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card>
          <CardTitle>{t('report.by_package')}</CardTitle>
          <TableWrap>
            <table className="w-full min-w-[20rem] text-sm">
              <thead><tr className="border-b border-ink/10 text-ink/55"><th className="py-2 text-start font-medium">{t('report.package')}</th><th className="py-2 text-end font-medium">{t('report.collected')}</th><th className="py-2 text-end font-medium">{t('report.outstanding')}</th></tr></thead>
              <tbody>{d.by_package.map((p) => <tr key={p.name} className="border-b border-ink/5"><td dir="auto" className="py-2">{p.name}</td><td className="py-2 text-end tabular-nums">{m(p.collected)}</td><td className="py-2 text-end tabular-nums">{m(p.outstanding)}</td></tr>)}</tbody>
            </table>
          </TableWrap>
        </Card>
        <Card>
          <CardTitle>{t('report.by_method')}</CardTitle>
          <TableWrap>
            <table className="w-full min-w-[20rem] text-sm">
              <thead><tr className="border-b border-ink/10 text-ink/55"><th className="py-2 text-start font-medium">{t('report.method')}</th><th className="py-2 text-end font-medium">{t('report.count')}</th><th className="py-2 text-end font-medium">{t('report.collected')}</th></tr></thead>
              <tbody>{d.by_method.map((x) => <tr key={x.method} className="border-b border-ink/5"><td className="py-2">{t(`methods.${x.method}`)}</td><td className="py-2 text-end tabular-nums">{formatNumber(x.count, locale)}</td><td className="py-2 text-end tabular-nums">{m(x.amount)}</td></tr>)}</tbody>
            </table>
          </TableWrap>
        </Card>
      </div>

      <Card>
        <CardTitle>{t('report.outstanding_list')}</CardTitle>
        {d.outstanding.length === 0 ? <EmptyState size="sm" icon="check" title={t('report.empty')} /> : (
          <ul className="divide-y divide-ink/6 text-sm">
            {d.outstanding.map((s) => (
              <li key={s.student_id} className="flex items-center gap-3 py-2">
                <Link to={`/students/${s.student_id}?tab=wallet`} dir="auto" className="flex-1 text-ink hover:text-brand-700">{s.full_name}</Link>
                <span className="text-xs tabular-nums text-ink/50">{s.student_no}</span>
                <span className="w-28 text-end font-semibold tabular-nums text-danger">{m(s.balance_fils)}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
