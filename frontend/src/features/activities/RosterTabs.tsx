import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { activitiesApi, type Activity, type MoneyStatus, type Roster, type RosterRow } from '../../api/activities'
import { parseApiError } from '../../api/client'
import { dashboardPanelsApi } from '../../api/dashboard-panels'
import type { StudentSummary } from '../../api/students'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, FilterBar, IconButton, LoadingState, Notice, PrimaryButton, SearchInput, SURFACE, TABLE_HEAD, TableWrap, TextInput, inputClass } from '../../components/ui'
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'
import { QueryError, todayIso } from '../attendanceFollowup/shared'
import type { CrudNotice } from '../common/crud'
import { RecordPaymentDialog } from '../payments/PaymentDialogs'
import { Amount, bhd, MoneyBadge, REG_TONE, Stat, StudentCell } from './shared'

const TH = 'px-3 py-2 text-start font-medium'
const TD = 'px-3 py-2'
const MONEY: MoneyStatus[] = ['unpaid', 'partial', 'paid', 'none']

/** One roster query per activity feeds every follow-up tab. */
function useRoster(activity: Activity) {
  return useQuery({ queryKey: ['activity-roster', activity.id], queryFn: () => activitiesApi.roster(activity.id) })
}

function useRefresh() {
  const qc = useQueryClient()
  return () => ['activity-roster', 'activities', 'invoices', 'payments', 'student-wallet'].forEach((k) => void qc.invalidateQueries({ queryKey: [k] }))
}

const errorText = (e: unknown) => { const p = parseApiError(e); return Object.values(p.fields)[0]?.[0] ?? p.message }

/** Loading, error and empty states around a roster tab. */
function RosterFrame({ activity, children }: { activity: Activity; children: (r: Roster) => ReactNode }) {
  const { t } = useTranslation('activities')
  const q = useRoster(activity)
  if (q.isLoading) return <LoadingState />
  if (q.isError) return <QueryError error={q.error} onRetry={() => void q.refetch()} />
  if (!q.data) return null
  if (q.data.data.filter((r) => r.status === 'registered').length === 0) return <EmptyCard icon="students" title={t('no_registrations')} />
  return <>{children(q.data)}</>
}

function Table({ min, head, children }: { min: string; head: ReactNode; children: ReactNode }) {
  return (
    <TableWrap surface>
      <table className={`relative w-full ${min} text-sm`}>
        <thead className={TABLE_HEAD}><tr>{head}</tr></thead>
        <tbody className="divide-y divide-ink/6">{children}</tbody>
      </table>
    </TableWrap>
  )
}

function useSearch(rows: RosterRow[]) {
  const [search, setSearch] = useState('')
  const s = search.trim()
  return { search, setSearch, filtered: rows.filter((r) => !s || r.student.full_name.includes(s) || r.student.student_no.includes(s)) }
}

/** الطلبة المسجلين في البرامج: status, fees, book, attendance rate and evaluation of every student. */
export function StudentsTab({ activity }: { activity: Activity }) {
  return <RosterFrame activity={activity}>{(r) => <StudentsTable r={r} activity={activity} />}</RosterFrame>
}

function StudentsTable({ r, activity }: { r: Roster; activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const locale = i18n.language
  const { filtered, search, setSearch } = useSearch(r.data)
  const money = r.totals.money !== null
  return (
    <div className="space-y-4">
      <div className="grid gap-3 *:min-w-0 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label={t('registered')} value={formatNumber(r.totals.registered, locale)} />
        <Stat label={t('waitlist')} value={formatNumber(r.totals.waitlist, locale)} />
        {activity.has_book && <Stat label={t('book.delivered')} value={formatNumber(r.totals.delivered, locale)} />}
        <Stat label={t('evaluation.evaluated')} value={formatNumber(r.totals.evaluated, locale)} />
      </div>
      <FilterBar label={t('filters')}><SearchInput label={t('search')} className="sm:w-64" value={search} onChange={(e) => setSearch(e.target.value)} /></FilterBar>
      <Table min="min-w-[52rem]" head={<>
        <th scope="col" className={TH}>{t('student')}</th>
        <th scope="col" className={TH}>{t('class')}</th>
        <th scope="col" className={TH}>{t('fields.status')}</th>
        {money && <th scope="col" className={TH}>{t('money.fees')}</th>}
        {activity.has_book && <th scope="col" className={TH}>{t('book.title')}</th>}
        <th scope="col" className={TH}>{t('attendance.rate')}</th>
        <th scope="col" className={TH}>{t('evaluation.score')}</th>
      </>}>
        {filtered.map((x) => (
          <tr key={x.id}>
            <td className={TD}><StudentCell s={x.student} /></td>
            <td className={`${TD} text-ink/70`}>{x.class ? <><span className="block">{x.class.name}</span>{x.class.level && <span className="block text-xs text-ink/50">{x.class.level}</span>}</> : '—'}</td>
            <td className={TD}><Badge tone={REG_TONE[x.status]}>{t(`reg_status.${x.status}`)}</Badge></td>
            {money && <td className={TD}>{x.money && <MoneyBadge status={x.money.status} />}</td>}
            {activity.has_book && <td className={TD}>{x.book ? <Badge tone="brand">{t('book.delivered')}</Badge> : <Badge>{t('book.not_delivered')}</Badge>}</td>}
            <td className={`${TD} tabular-nums`}>{formatPercent(x.attendance.rate, locale)}</td>
            <td className={`${TD} tabular-nums`}>{typeof x.evaluation?.score === 'number' ? formatNumber(x.evaluation.score, locale) : '—'}{x.evaluation?.grade && <span dir="auto" className="ms-1 text-xs text-ink/55">{x.evaluation.grade}</span>}</td>
          </tr>
        ))}
      </Table>
    </div>
  )
}

/** دفع رسوم البرنامج / دفع رسوم الرحلة: the unpaid fee and book invoices, each paid through the payments dialog. */
export function FeePaymentTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  const refresh = useRefresh()
  const [paying, setPaying] = useState<{ s: StudentSummary; amount: string } | null>(null)
  const [notice, setNotice] = useState<CrudNotice>(null)
  return (
    <RosterFrame activity={activity}>
      {(r) => {
        const open = r.data.filter((x) => x.status === 'registered' && (x.money?.remaining_fils ?? 0) > 0)
        return (
          <div className="space-y-4">
            {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
            {open.length === 0 ? <EmptyCard icon="payments" title={t('money.nothing_due')} /> : (
              <Table min="min-w-[44rem]" head={<>
                <th scope="col" className={TH}>{t('student')}</th>
                <th scope="col" className={TH}>{t('money.fee_invoice')}</th>
                {activity.has_book && <th scope="col" className={TH}>{t('money.book_invoice')}</th>}
                <th scope="col" className="px-3 py-2 text-end font-medium">{t('money.remaining')}</th>
                <th scope="col" className="px-3 py-2"><span className="sr-only">{t('actions')}</span></th>
              </>}>
                {open.map((x) => (
                  <tr key={x.id}>
                    <td className={TD}><StudentCell s={x.student} /></td>
                    <td className={TD}>{x.fee_invoice ? <><span className="block text-xs tabular-nums text-ink/50">{x.fee_invoice.invoice_no}</span><Amount total={x.fee_invoice.amount_fils} remaining={x.fee_invoice.remaining_fils} /></> : '—'}</td>
                    {activity.has_book && <td className={TD}>{x.book_invoice ? <><span className="block text-xs tabular-nums text-ink/50">{x.book_invoice.invoice_no}</span><Amount total={x.book_invoice.amount_fils} remaining={x.book_invoice.remaining_fils} /></> : '—'}</td>}
                    <td className={`${TD} text-end font-semibold tabular-nums text-danger`}>{formatMoney(x.money!.remaining_fils, l)}</td>
                    <td className={`${TD} text-end`}>
                      <PrimaryButton className="py-1.5" onClick={() => setPaying({ s: x.student, amount: bhd(x.money!.remaining_fils) })}>{t('money.pay')}</PrimaryButton>
                    </td>
                  </tr>
                ))}
              </Table>
            )}
            <p className="text-xs text-ink/50">{t('money.settles_note')}</p>
            {paying && <RecordPaymentDialog initial={paying.s} initialAmount={paying.amount} onClose={() => setPaying(null)}
              onDone={(m) => { setPaying(null); setNotice({ tone: 'success', text: m }); refresh() }} />}
          </div>
        )
      }}
    </RosterFrame>
  )
}

/** متابعة رسوم البرنامج / الرحلة: paid, partial or unpaid per registered student, totals and the fee reminder. */
export function FeeFollowupTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  const [status, setStatus] = useState<MoneyStatus | ''>('')
  const [notice, setNotice] = useState<CrudNotice>(null)
  const remind = useMutation({
    mutationFn: (id: number) => dashboardPanelsApi.remind(id),
    onSuccess: (r) => setNotice({ tone: 'success', text: r.message }),
    onError: (e) => setNotice({ tone: 'error', text: errorText(e) }),
  })
  return (
    <RosterFrame activity={activity}>
      {(r) => {
        const all = r.data.filter((x) => x.status === 'registered')
        const rows = all.filter((x) => !status || x.money?.status === status)
        const m = r.totals.money
        return (
          <div className="space-y-4">
            {m && (
              <div className="grid gap-3 *:min-w-0 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label={t('registered')} value={formatNumber(all.length, l)} />
                <Stat label={t('money.total')} value={formatMoney(m.total_fils, l)} />
                <Stat label={t('money.paid_total')} value={formatMoney(m.paid_fils, l)} />
                <Stat label={t('money.remaining')} value={formatMoney(m.remaining_fils, l)} danger={m.remaining_fils > 0} />
              </div>
            )}
            <FilterBar label={t('filters')}>
              <SelectField label={t('money.status')} hideLabel className="sm:w-52" value={status} onChange={(e) => setStatus(e.target.value as MoneyStatus | '')}
                options={[{ value: '', label: t('money.all') }, ...MONEY.map((s) => ({ value: s, label: `${t(`money.${s}`)} (${formatNumber(m?.by_status[s] ?? 0, l)})` }))]} />
            </FilterBar>
            {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
            {rows.length === 0 ? <EmptyCard icon="wallet" title={t('money.empty')} /> : (
              <Table min="min-w-[44rem]" head={<>
                <th scope="col" className={TH}>{t('student')}</th>
                <th scope="col" className="px-3 py-2 text-end font-medium">{t('money.total')}</th>
                <th scope="col" className="px-3 py-2 text-end font-medium">{t('money.paid_total')}</th>
                <th scope="col" className="px-3 py-2 text-end font-medium">{t('money.remaining')}</th>
                <th scope="col" className={TH}>{t('money.status')}</th>
                <th scope="col" className="px-3 py-2"><span className="sr-only">{t('actions')}</span></th>
              </>}>
                {rows.map((x) => x.money && (
                  <tr key={x.id}>
                    <td className={TD}><StudentCell s={x.student} /></td>
                    <td className={`${TD} text-end tabular-nums`}>{formatMoney(x.money.total_fils, l)}</td>
                    <td className={`${TD} text-end tabular-nums`}>{formatMoney(x.money.paid_fils, l)}</td>
                    <td className={`${TD} text-end tabular-nums ${x.money.remaining_fils > 0 ? 'font-semibold text-danger' : ''}`}>{formatMoney(x.money.remaining_fils, l)}</td>
                    <td className={TD}><MoneyBadge status={x.money.status} />{x.money.overdue && <Badge tone="danger" className="ms-1">{t('money.overdue')}</Badge>}</td>
                    <td className={`${TD} text-end`}>
                      {r.can_remind && x.money.remaining_fils > 0 && <IconButton icon="bell" label={t('money.remind')} disabled={remind.isPending} onClick={() => remind.mutate(x.student.id)} />}
                    </td>
                  </tr>
                ))}
              </Table>
            )}
          </div>
        )
      }}
    </RosterFrame>
  )
}

/** تسليم كتاب البرنامج: hand the book to registered students in bulk; the price is billed at delivery. */
export function BookDeliveryTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  const refresh = useRefresh()
  const [picked, setPicked] = useState<number[]>([])
  const [charge, setCharge] = useState(true)
  const [on, setOn] = useState(todayIso())
  const [notice, setNotice] = useState<CrudNotice>(null)
  const deliver = useMutation({
    mutationFn: () => activitiesApi.deliverBook(activity.id, { student_ids: picked, charge, delivered_at: on }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); setPicked([]); refresh() },
    onError: (e) => setNotice({ tone: 'error', text: errorText(e) }),
  })
  const undo = useMutation({
    mutationFn: (id: number) => activitiesApi.undoBook(activity.id, id),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); refresh() },
    onError: (e) => setNotice({ tone: 'error', text: errorText(e) }),
  })
  if (!activity.has_book) return <EmptyCard icon="lessons" title={t('book.none')} />

  return (
    <RosterFrame activity={activity}>
      {(r) => {
        const rows = r.data.filter((x) => x.status === 'registered')
        const open = rows.filter((x) => !x.book).map((x) => x.student.id)
        const all = open.length > 0 && open.every((id) => picked.includes(id))
        return (
          <div className="space-y-4">
            <section className={`${SURFACE} flex flex-wrap items-end gap-3 p-4`} aria-label={t('book.title')}>
              <p className="min-w-0 flex-[1_1_14rem] text-sm text-ink/70">
                <bdi className="font-semibold text-ink">{activity.book_title || activity.name}</bdi>
                {activity.book_price_fils > 0 && <span className="block tabular-nums">{formatMoney(activity.book_price_fils, l)}</span>}
              </p>
              <TextInput label={t('book.delivered_at')} type="date" className="w-40" value={on} onChange={(e) => setOn(e.target.value)} />
              {activity.book_price_fils > 0 && (
                <label className="flex min-h-10 items-center gap-2 text-sm text-ink/80">
                  <input type="checkbox" className="size-4 accent-brand-700" checked={charge} onChange={(e) => setCharge(e.target.checked)} />{t('book.charge')}
                </label>
              )}
              <PrimaryButton disabled={picked.length === 0} loading={deliver.isPending} onClick={() => deliver.mutate()}>{t('book.deliver', { n: formatNumber(picked.length, l) })}</PrimaryButton>
            </section>
            {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
            <Table min="min-w-[40rem]" head={<>
              <th scope="col" className="w-10 px-3 py-2">
                <input type="checkbox" aria-label={t('select_all')} className="size-4 accent-brand-700" disabled={open.length === 0} checked={all} onChange={() => setPicked(all ? [] : open)} />
              </th>
              <th scope="col" className={TH}>{t('student')}</th>
              <th scope="col" className={TH}>{t('book.title')}</th>
              <th scope="col" className="px-3 py-2"><span className="sr-only">{t('actions')}</span></th>
            </>}>
              {rows.map((x) => (
                <tr key={x.id}>
                  <td className={TD}>{!x.book && <input type="checkbox" aria-label={x.student.full_name} className="size-4 accent-brand-700" checked={picked.includes(x.student.id)}
                    onChange={() => setPicked(picked.includes(x.student.id) ? picked.filter((p) => p !== x.student.id) : [...picked, x.student.id])} />}</td>
                  <td className={TD}><StudentCell s={x.student} /></td>
                  <td className={TD}>{x.book ? <><Badge tone="brand">{t('book.delivered')}</Badge><span className="ms-2 text-xs tabular-nums text-ink/55">{x.book.delivered_at && formatDate(x.book.delivered_at, l)}</span></> : <Badge>{t('book.not_delivered')}</Badge>}</td>
                  <td className={`${TD} text-end`}>{x.book && <IconButton icon="refresh" label={t('book.undo')} disabled={undo.isPending} onClick={() => { if (window.confirm(t('book.undo_confirm'))) undo.mutate(x.book!.id) }} />}</td>
                </tr>
              ))}
            </Table>
          </div>
        )
      }}
    </RosterFrame>
  )
}

/** متابعة تسليم كتاب البرنامج: delivered or not, and the paid state of the book invoice. */
export function BookFollowupTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  const [filter, setFilter] = useState<'' | 'yes' | 'no'>('')
  if (!activity.has_book) return <EmptyCard icon="lessons" title={t('book.none')} />
  return (
    <RosterFrame activity={activity}>
      {(r) => {
        const all = r.data.filter((x) => x.status === 'registered')
        const rows = all.filter((x) => !filter || (filter === 'yes') === !!x.book)
        const money = r.totals.money !== null
        return (
          <div className="space-y-4">
            <div className="grid gap-3 *:min-w-0 sm:grid-cols-3">
              <Stat label={t('registered')} value={formatNumber(all.length, l)} />
              <Stat label={t('book.delivered')} value={formatNumber(r.totals.delivered, l)} />
              <Stat label={t('book.not_delivered')} value={formatNumber(all.length - r.totals.delivered, l)} danger={all.length > r.totals.delivered} />
            </div>
            <FilterBar label={t('filters')}>
              <SelectField label={t('book.title')} hideLabel className="sm:w-52" value={filter} onChange={(e) => setFilter(e.target.value as '' | 'yes' | 'no')}
                options={[{ value: '', label: t('money.all') }, { value: 'yes', label: t('book.delivered') }, { value: 'no', label: t('book.not_delivered') }]} />
            </FilterBar>
            <Table min="min-w-[40rem]" head={<>
              <th scope="col" className={TH}>{t('student')}</th>
              <th scope="col" className={TH}>{t('book.title')}</th>
              <th scope="col" className={TH}>{t('book.delivered_by')}</th>
              {money && <th scope="col" className={TH}>{t('money.book_invoice')}</th>}
            </>}>
              {rows.map((x) => (
                <tr key={x.id}>
                  <td className={TD}><StudentCell s={x.student} /></td>
                  <td className={TD}>{x.book ? <><Badge tone="brand">{t('book.delivered')}</Badge><span className="ms-2 text-xs tabular-nums text-ink/55">{x.book.delivered_at && formatDate(x.book.delivered_at, l)}</span></> : <Badge>{t('book.not_delivered')}</Badge>}</td>
                  <td className={`${TD} text-ink/70`}>{x.book?.delivered_by ?? '—'}</td>
                  {money && <td className={TD}>{x.book_invoice ? <><MoneyBadge status={x.book_invoice.status === 'open' ? 'unpaid' : x.book_invoice.status === 'cancelled' ? 'none' : x.book_invoice.status} /><span className="ms-2 text-xs tabular-nums text-ink/55">{formatMoney(x.book_invoice.amount_fils, l)}</span></> : '—'}</td>}
                </tr>
              ))}
            </Table>
          </div>
        )
      }}
    </RosterFrame>
  )
}

type Draft = { score: string; grade: string; notes: string }

/** تقييم طلبة البرامج: a score out of 100, a grade word and notes per registered student. */
export function EvaluationTab({ activity }: { activity: Activity }) {
  return <RosterFrame activity={activity}>{(r) => <EvaluationForm activity={activity} roster={r} />}</RosterFrame>
}

function EvaluationForm({ activity, roster }: { activity: Activity; roster: Roster }) {
  const { t } = useTranslation('activities')
  const refresh = useRefresh()
  const rows = roster.data.filter((x) => x.status === 'registered')
  const [drafts, setDrafts] = useState<Record<number, Draft>>(() => Object.fromEntries(rows.map((x) => [x.student.id, {
    score: x.evaluation?.score === null || x.evaluation?.score === undefined ? '' : String(x.evaluation.score), grade: x.evaluation?.grade ?? '', notes: x.evaluation?.notes ?? '',
  }])))
  const [notice, setNotice] = useState<CrudNotice>(null)
  const patch = (id: number, p: Partial<Draft>) => setDrafts({ ...drafts, [id]: { ...drafts[id], ...p } })
  const bad = (v: string) => v !== '' && !(/^\d+$/.test(toLatinDigits(v)) && Number(toLatinDigits(v)) <= 100)
  const invalid = Object.values(drafts).some((d) => bad(d.score))
  const save = useMutation({
    mutationFn: () => activitiesApi.saveEvaluations(activity.id, rows.map((x) => {
      const d = drafts[x.student.id]
      return { student_id: x.student.id, score: d.score === '' ? null : Number(toLatinDigits(d.score)), grade: d.grade || null, notes: d.notes || null }
    })),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); refresh() },
    onError: (e) => setNotice({ tone: 'error', text: errorText(e) }),
  })

  return (
    <div className="space-y-4">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <section className={SURFACE} aria-label={t('evaluation.title')}>
        <ul className="divide-y divide-ink/6">
          {rows.map((x) => {
            const d = drafts[x.student.id]
            return (
              <li key={x.id} className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                <div className="min-w-0 flex-[1_1_12rem]"><StudentCell s={x.student} /></div>
                <label className="sr-only" htmlFor={`score-${x.id}`}>{t('evaluation.score')}</label>
                <input id={`score-${x.id}`} inputMode="numeric" dir="ltr" placeholder={t('evaluation.out_of')} aria-invalid={bad(d.score)} value={d.score}
                  onChange={(e) => patch(x.student.id, { score: e.target.value })} className={inputClass('md', 'w-24 text-sm tabular-nums')} />
                <label className="sr-only" htmlFor={`grade-${x.id}`}>{t('evaluation.grade')}</label>
                <input id={`grade-${x.id}`} dir="auto" placeholder={t('evaluation.grade')} value={d.grade} onChange={(e) => patch(x.student.id, { grade: e.target.value })} className={inputClass('md', 'w-32 text-sm')} />
                <label className="sr-only" htmlFor={`notes-${x.id}`}>{t('evaluation.notes')}</label>
                <input id={`notes-${x.id}`} dir="auto" placeholder={t('evaluation.notes')} value={d.notes} onChange={(e) => patch(x.student.id, { notes: e.target.value })} className={inputClass('md', 'min-w-0 flex-[1_1_12rem] text-sm')} />
              </li>
            )
          })}
        </ul>
        <div className="flex flex-wrap items-center gap-3 border-t border-ink/6 px-4 py-3 sm:px-5">
          {invalid && <span className="text-sm text-danger">{t('evaluation.invalid')}</span>}
          <PrimaryButton className="ms-auto min-w-36" loading={save.isPending} disabled={invalid} onClick={() => save.mutate()}>{t('save')}</PrimaryButton>
        </div>
      </section>
    </div>
  )
}

/** عرض تقييم طلبة البرامج: the saved evaluations, read-only, with the average. */
export function EvaluationViewTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  return (
    <RosterFrame activity={activity}>
      {(r) => {
        const rows = r.data.filter((x) => x.status === 'registered')
        const scores = rows.map((x) => x.evaluation?.score).filter((s): s is number => typeof s === 'number')
        const avg = scores.length ? scores.reduce((a, b) => a + b, 0) / scores.length : null
        return (
          <div className="space-y-4">
            <div className="grid gap-3 *:min-w-0 sm:grid-cols-3">
              <Stat label={t('registered')} value={formatNumber(rows.length, l)} />
              <Stat label={t('evaluation.evaluated')} value={formatNumber(r.totals.evaluated, l)} />
              <Stat label={t('evaluation.average')} value={avg === null ? '—' : formatNumber(avg, l, { maximumFractionDigits: 1 })} />
            </div>
            <Table min="min-w-[40rem]" head={<>
              <th scope="col" className={TH}>{t('student')}</th>
              <th scope="col" className={TH}>{t('evaluation.score')}</th>
              <th scope="col" className={TH}>{t('evaluation.grade')}</th>
              <th scope="col" className={TH}>{t('evaluation.notes')}</th>
              <th scope="col" className={TH}>{t('evaluation.by')}</th>
            </>}>
              {rows.map((x) => (
                <tr key={x.id}>
                  <td className={TD}><StudentCell s={x.student} /></td>
                  <td className={`${TD} tabular-nums`}>{x.evaluation?.score !== null && x.evaluation?.score !== undefined ? t('evaluation.score_of', { n: formatNumber(x.evaluation.score, l) }) : '—'}</td>
                  <td className={TD}><bdi>{x.evaluation?.grade ?? '—'}</bdi></td>
                  <td className={`${TD} text-ink/70`}><span dir="auto">{x.evaluation?.notes ?? '—'}</span></td>
                  <td className={`${TD} text-ink/60`}>{x.evaluation?.evaluated_by ?? '—'}</td>
                </tr>
              ))}
            </Table>
          </div>
        )
      }}
    </RosterFrame>
  )
}
