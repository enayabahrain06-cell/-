import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { dashboardPanelsApi, type OverdueStudent } from '../../api/dashboard-panels'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { EmptyState } from '../../components/ornaments'
import { formatMoney, formatNumber, formatPercent } from '../../lib/format'
import { PanelCard, PanelError, PanelSkeleton, StatTile } from './PanelParts'
import { useDashboardFilters } from './useDashboardFilters'

/** Fees & dues (الرسوم والمستحقات). Only for users with wallet access. */
export default function FeesCard() {
  const { can } = useAuth()
  if (!can('wallets.view')) return null
  return <FeesCardBody />
}

function FeesCardBody() {
  const { t, i18n } = useTranslation('dashboard-panels')
  const locale = i18n.language
  const filters = useDashboardFilters()
  const q = useQuery({ queryKey: ['dashboard', 'fees', filters, locale], queryFn: () => dashboardPanelsApi.fees(filters), refetchInterval: 5 * 60_000 })
  const m = (f: number) => formatMoney(f, locale)
  const n = (v: number) => formatNumber(v, locale)
  const d = q.data
  const nothing = d && d.collected.this_month_fils === 0 && d.collected.last_month_fils === 0 && d.overdue.invoices === 0 && d.due_this_week.invoices === 0

  const change = d?.collected.change_percent
  const changeHint = d
    ? change === null || change === undefined
      ? t('fees.last_month', { amount: m(d.collected.last_month_fils) })
      : (
        <>
          <span className={change >= 0 ? 'text-brand-700' : 'text-danger'}>
            {change >= 0 ? '▲' : '▼'} {formatPercent(Math.abs(change), locale)}
          </span>{' '}
          {t('fees.vs_last_month')}
        </>
      )
    : undefined

  return (
    <PanelCard id="fees-title" title={t('fees.title')} icon="payments" footer={{ to: '/payments', label: t('fees.link') }}>
      {q.isLoading ? (
        <PanelSkeleton />
      ) : q.isError || !d ? (
        <PanelError onRetry={() => void q.refetch()} />
      ) : nothing ? (
        <EmptyState size="sm" icon="payments" title={t('fees.empty')} body={t('fees.empty_body')} />
      ) : (
        <div className="space-y-4 p-4">
          <div className="grid grid-cols-1 gap-2 @sm:grid-cols-3">
            <StatTile label={t('fees.collected')} value={m(d.collected.this_month_fils)} hint={changeHint} />
            <StatTile
              label={t('fees.overdue')}
              value={m(d.overdue.amount_fils)}
              tone="danger"
              hint={t('fees.students', { n: n(d.overdue.students) })}
            />
            <StatTile label={t('fees.due_week')} value={m(d.due_this_week.amount_fils)} hint={t('fees.invoices', { n: n(d.due_this_week.invoices) })} />
          </div>

          <div>
            <h3 className="mb-1.5 text-sm font-semibold text-ink">{t('fees.top_title')}</h3>
            {d.top_overdue.length === 0 ? (
              <p className="rounded-xl bg-brand-50 px-3 py-3 text-sm text-brand-700">{t('fees.none_overdue')}</p>
            ) : (
              <ul className="divide-y divide-ink/6">
                {d.top_overdue.map((s) => <OverdueRow key={s.student_id} s={s} locale={locale} canRemind={d.can_remind} />)}
              </ul>
            )}
          </div>
        </div>
      )}
    </PanelCard>
  )
}

function OverdueRow({ s, locale, canRemind }: { s: OverdueStudent; locale: string; canRemind: boolean }) {
  const { t } = useTranslation('dashboard-panels')
  const { can } = useAuth()
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)
  const remind = useMutation({
    mutationFn: () => dashboardPanelsApi.remind(s.student_id),
    onSuccess: (r) => setResult({ ok: true, message: r.message }),
    onError: (e) => {
      const err = parseApiError(e)
      setResult({ ok: false, message: err.fields.student?.[0] ?? err.message })
    },
  })
  const sent = result?.ok === true

  return (
    <li className="py-2.5">
      <div className="flex items-center gap-3">
        <div className="min-w-0 flex-1">
          {can('students.view') ? (
            <Link to={`/students/${s.student_id}?tab=wallet`} dir="auto" className="block truncate text-start font-medium text-ink hover:text-brand-700">{s.name}</Link>
          ) : (
            <p dir="auto" className="truncate text-start font-medium text-ink">{s.name}</p>
          )}
          <p className="text-xs text-ink/55">{t('fees.days_overdue', { count: s.days_overdue, n: formatNumber(s.days_overdue, locale) })}</p>
        </div>
        <span className="shrink-0 text-sm font-semibold tabular-nums text-danger">{formatMoney(s.outstanding_fils, locale)}</span>
        {canRemind && (
          <button
            type="button"
            onClick={() => remind.mutate()}
            disabled={!s.has_phone || remind.isPending || sent}
            title={!s.has_phone ? t('fees.no_phone') : undefined}
            className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-ink/10 bg-white px-3 py-1.5 text-xs font-medium text-ink/75 hover:bg-ink/5 disabled:cursor-not-allowed disabled:opacity-55"
          >
            <Icon name={sent ? 'check' : 'messages'} className="size-3.5" />
            {remind.isPending ? t('fees.reminding') : sent ? t('fees.reminded') : t('fees.remind')}
          </button>
        )}
      </div>
      {result && (
        <p role="status" className={`mt-1 text-xs ${result.ok ? 'text-brand-700' : 'text-danger'}`}>{result.message}</p>
      )}
    </li>
  )
}
