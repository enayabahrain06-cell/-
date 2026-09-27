import { Fragment, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { auditApi, type AuditRow } from '../../api/audit'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { EmptyState, PageBand } from '../../components/ornaments'
import { ErrorState, LoadingState, SecondaryButton, SURFACE, TABLE_HEAD, TableWrap, TextInput } from '../../components/ui'
import { formatDate } from '../../lib/format'

const KEYS = ['action', 'user_id', 'from', 'to', 'page'] as const

/** Audit log viewer: filter by area, staff member and dates; expand a row to see before/after values. */
export default function AuditLogPage() {
  const { t, i18n } = useTranslation('audit')
  const locale = i18n.language
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(KEYS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof KEYS)[number], string>
  const setFilter = (k: (typeof KEYS)[number], v: string) => {
    const next = new URLSearchParams(params)
    if (v) next.set(k, v); else next.delete(k)
    if (k !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }
  const options = useQuery({ queryKey: ['audit-options'], queryFn: auditApi.options, staleTime: 60_000 })
  const q = useQuery({ queryKey: ['audit', f, locale], queryFn: () => auditApi.list({ ...f, page: Number(f.page || 1) }), placeholderData: keepPreviousData })
  const [open, setOpen] = useState<number | null>(null)
  const filtered = KEYS.some((k) => k !== 'page' && f[k])

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')} />
      <section aria-label={t('title')} className={`${SURFACE} grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5`}>
        <SelectField label={t('group')} value={f.action} onChange={(e) => setFilter('action', e.target.value)}
          options={[{ value: '', label: t('all_groups') }, ...(options.data?.groups ?? []).map((g) => ({ value: g.value, label: g.label }))]} />
        <SelectField label={t('user')} value={f.user_id} onChange={(e) => setFilter('user_id', e.target.value)}
          options={[{ value: '', label: t('all_users') }, ...(options.data?.users ?? []).map((u) => ({ value: String(u.id), label: u.name }))]} />
        <TextInput type="date" label={t('from')} value={f.from} onChange={(e) => setFilter('from', e.target.value)} />
        <TextInput type="date" label={t('to')} value={f.to} onChange={(e) => setFilter('to', e.target.value)} />
        <div className="flex items-end">{filtered && <SecondaryButton className="w-full" onClick={() => setParams({}, { replace: true })}>{t('clear')}</SecondaryButton>}</div>
      </section>

      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <div className={SURFACE}><EmptyState icon="eye" title={t('empty')} body={t('empty_body')} /></div>
      ) : (
        <>
          <TableWrap surface>
            <table className="w-full min-w-[40rem] text-sm">
              <thead className={TABLE_HEAD}>
                <tr>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('when')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('user')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('action')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('subject')}</th>
                  <th scope="col" className="px-4 py-3 text-end font-medium">{t('details')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {q.data.data.map((r) => (
                  <Fragment key={r.id}>
                    <tr className="hover:bg-brand-50/40">
                      <td className="whitespace-nowrap px-4 py-3 tabular-nums text-ink/70">{formatDate(r.created_at, locale, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</td>
                      <td className="px-4 py-3 text-ink"><span dir="auto">{r.user?.name ?? t('system')}</span></td>
                      <td className="px-4 py-3 text-ink">{r.action_label}<span dir="ltr" className="block text-xs text-ink/40">{r.action}</span></td>
                      <td dir="ltr" className="px-4 py-3 text-start text-xs tabular-nums text-ink/60">{r.subject} #{r.subject_id}</td>
                      <td className="px-4 py-3 text-end">
                        <button type="button" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}
                          className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-ink/5 focus-visible:outline-2 focus-visible:outline-brand-500">
                          {open === r.id ? t('hide') : t('show')} <Icon name="chevron" className={`size-3.5 ${open === r.id ? '-rotate-90' : 'rotate-90'}`} />
                        </button>
                      </td>
                    </tr>
                    {open === r.id && <tr><td colSpan={5} className="bg-page/50 px-4 py-3"><Diff row={r} /></td></tr>}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </TableWrap>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => setFilter('page', String(p))} />
        </>
      )}
    </div>
  )
}

function show(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  return typeof v === 'object' ? JSON.stringify(v) : String(v)
}

function Diff({ row }: { row: AuditRow }) {
  const { t } = useTranslation('audit')
  const keys = Array.from(new Set([...Object.keys(row.old_values), ...Object.keys(row.new_values)]))
  if (keys.length === 0) return <p className="text-sm text-ink/55">{t('no_changes')}</p>
  return (
    <table className="w-full text-xs">
      <thead className="text-ink/55"><tr><th className="py-1 text-start font-medium">{t('field')}</th><th className="py-1 text-start font-medium">{t('before')}</th><th className="py-1 text-start font-medium">{t('after')}</th></tr></thead>
      <tbody>
        {keys.map((k) => {
          const before = show(row.old_values[k])
          const after = show(row.new_values[k])
          return (
            <tr key={k} className={before !== after ? 'text-ink' : 'text-ink/50'}>
              <td dir="ltr" className="py-1 pe-3 text-start font-mono">{k}</td>
              <td dir="auto" className="break-all py-1 pe-3">{before}</td>
              <td dir="auto" className="break-all py-1 font-medium">{after}</td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
