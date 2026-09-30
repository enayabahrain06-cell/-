import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { dashboardPanelsApi } from '../../api/dashboard-panels'
import { paymentFollowupApi, type FeeStatus } from '../../api/paymentFollowup'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Notice, SearchInput, SURFACE, TABLE_HEAD, TableWrap, type Tone } from '../../components/ui'
import { formatMoney, formatNumber } from '../../lib/format'
import { RecordPaymentDialog } from './PaymentDialogs'

const STATUSES: FeeStatus[] = ['unpaid', 'partial', 'paid', 'none']
const TONE: Record<FeeStatus, Tone> = { paid: 'brand', partial: 'gold', unpaid: 'danger', none: 'muted' }

/** متابعة الدفع: every student with a class this term, their term invoices, what is paid and what remains. */
export default function PaymentFollowupPage() {
  const { t, i18n } = useTranslation('paymentFollowup')
  const locale = i18n.language
  const { can } = useAuth()
  const q = useQuery({ queryKey: ['payment-followup'], queryFn: () => paymentFollowupApi.list({}) })
  const [lessonId, setLessonId] = useState('')
  const [levelId, setLevelId] = useState('')
  const [status, setStatus] = useState<FeeStatus | ''>('')
  const [search, setSearch] = useState('')
  const [paying, setPaying] = useState<StudentSummary | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const remind = useMutation({
    mutationFn: (id: number) => dashboardPanelsApi.remind(id),
    onSuccess: (r) => setNotice({ tone: 'success', text: r.message }),
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  const all = useMemo(() => q.data?.data ?? [], [q.data])
  const classes = useMemo(() => [...new Map(all.map((r) => [r.lesson.id, r.lesson])).values()].sort((a, b) => a.name.localeCompare(b.name)), [all])
  const levels = useMemo(() => [...new Map(all.filter((r) => r.level).map((r) => [r.level!.id, r.level!])).values()], [all])
  const rows = all.filter((r) => (!lessonId || String(r.lesson.id) === lessonId) && (!levelId || String(r.level?.id ?? '') === levelId) && (!status || r.status === status)
    && (!search.trim() || r.student.full_name.includes(search.trim()) || r.student.student_no.includes(search.trim()) || r.student.guardian_phone.includes(search.trim())))
  const sum = (k: 'total_fils' | 'paid_fils' | 'remaining_fils') => rows.reduce((a, r) => a + r[k], 0)
  const canRecord = (q.data?.can_record ?? false) && can('payments.record')

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.payment_followup')} subtitle={q.data ? t('subtitle', { term: q.data.term.name }) : undefined} />
      </div>
      {q.isLoading ? <LoadingState /> : q.isError ? (
        isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState onRetry={() => void q.refetch()} />
      ) : (
        <>
          <div className="grid gap-3 *:min-w-0 sm:grid-cols-2 xl:grid-cols-4">
            <Stat label={t('stats.students')} value={formatNumber(rows.length, locale)} />
            <Stat label={t('stats.total')} value={formatMoney(sum('total_fils'), locale)} />
            <Stat label={t('stats.paid')} value={formatMoney(sum('paid_fils'), locale)} />
            <Stat label={t('stats.remaining')} value={formatMoney(sum('remaining_fils'), locale)} danger={sum('remaining_fils') > 0} />
          </div>
          <FilterBar label={t('filters')}>
            <SearchInput label={t('search')} className="sm:w-64" value={search} onChange={(e) => setSearch(e.target.value)} />
            <SelectField label={t('class')} hideLabel className="sm:w-52" value={lessonId} onChange={(e) => setLessonId(e.target.value)}
              options={[{ value: '', label: t('all_classes') }, ...classes.map((c) => ({ value: String(c.id), label: c.name }))]} />
            <SelectField label={t('level')} hideLabel className="sm:w-48" value={levelId} onChange={(e) => setLevelId(e.target.value)}
              options={[{ value: '', label: t('all_levels') }, ...levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
            <SelectField label={t('status')} hideLabel className="sm:w-44" value={status} onChange={(e) => setStatus(e.target.value as FeeStatus | '')}
              options={[{ value: '', label: t('all_statuses') }, ...STATUSES.map((s) => ({ value: s, label: t(`status_label.${s}`) }))]} />
          </FilterBar>
          {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
          {rows.length === 0 ? <EmptyCard icon="wallet" title={t('empty')} /> : (
            <TableWrap surface>
              <table className="w-full min-w-[46rem] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
                    <th scope="col" className="px-3 py-2 text-start font-medium">{t('class')}</th>
                    <th scope="col" className="px-3 py-2 text-end font-medium">{t('stats.total')}</th>
                    <th scope="col" className="px-3 py-2 text-end font-medium">{t('stats.paid')}</th>
                    <th scope="col" className="px-3 py-2 text-end font-medium">{t('stats.remaining')}</th>
                    <th scope="col" className="px-3 py-2 text-start font-medium">{t('status')}</th>
                    <th scope="col" className="px-3 py-2"><span className="sr-only">{t('actions')}</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink/6">
                  {rows.map((r) => (
                    <tr key={r.student.id}>
                      <td className="px-3 py-2">
                        <span dir="auto" className="block font-medium text-ink">{r.student.full_name}</span>
                        <span className="block text-xs tabular-nums text-ink/50">{r.student.student_no}</span>
                      </td>
                      <td className="px-3 py-2 text-ink/70"><span className="block">{r.lesson.name}</span>{r.level && <span className="block text-xs text-ink/50">{r.level.name}</span>}</td>
                      <td className="px-3 py-2 text-end tabular-nums">{formatMoney(r.total_fils, locale)}</td>
                      <td className="px-3 py-2 text-end tabular-nums">{formatMoney(r.paid_fils, locale)}</td>
                      <td className={`px-3 py-2 text-end tabular-nums ${r.remaining_fils > 0 ? 'font-semibold text-danger' : ''}`}>{formatMoney(r.remaining_fils, locale)}</td>
                      <td className="px-3 py-2">
                        <Badge tone={TONE[r.status]}>{t(`status_label.${r.status}`)}</Badge>
                        {r.overdue && <Badge tone="danger" className="ms-1">{t('overdue')}</Badge>}
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex justify-end">
                          {canRecord && r.remaining_fils > 0 && <IconButton icon="payments" label={t('record')} onClick={() => setPaying(r.student)} />}
                          {q.data?.can_remind && r.overdue && <IconButton icon="bell" label={t('remind')} disabled={remind.isPending} onClick={() => remind.mutate(r.student.id)} />}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableWrap>
          )}
        </>
      )}
      {paying && <RecordPaymentDialog initial={paying} onClose={() => setPaying(null)} onDone={(m) => { setPaying(null); setNotice({ tone: 'success', text: m }); void q.refetch() }} />}
    </div>
  )
}

function Stat({ label, value, danger = false }: { label: string; value: string; danger?: boolean }) {
  return (
    <div className={`${SURFACE} p-4`}>
      <p className="text-sm text-ink/60">{label}</p>
      <p className={`mt-1 text-xl font-semibold tabular-nums ${danger ? 'text-danger' : 'text-ink'}`}>{value}</p>
    </div>
  )
}
