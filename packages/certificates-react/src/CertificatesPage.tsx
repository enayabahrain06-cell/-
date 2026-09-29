import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useCertificates, useCertT } from './context'
import { ActionButtons, IssueCertificateDialog, useCertificateActions } from './dialogs'
import { IssuedDate, StatusBadge, useCertificateOptions, useInvalidateCertificates } from './shared'
import TemplatesPanel from './TemplatesPanel'
import type { Certificate, CertificateStatus } from './types'

type StatusTab = CertificateStatus | 'all'
const STATUS_TABS: StatusTab[] = ['draft', 'approved', 'revoked', 'all']

/** The certificates section: list with approval (default) and, for template managers, the template editor (?view=templates). */
export default function CertificatesPage() {
  const { t } = useCertT()
  const { ui, can } = useCertificates()
  const [params, setParams] = useSearchParams()
  const [issuing, setIssuing] = useState(false)
  const [issued, setIssued] = useState<string | null>(null)
  const view = params.get('view') === 'templates' && can('certificates.templates') ? 'templates' : 'list'

  return (
    <div className="space-y-5">
      <ui.PageHeader title={t('title')} subtitle={t('subtitle')}
        actions={can('certificates.issue') && view === 'list' && (
          <button type="button" onClick={() => { setIssued(null); setIssuing(true) }} className={ui.classes.headerAction}>
            <ui.Icon name="certificate" className="size-4" />{t('actions.issue')}
          </button>
        )} />
      {can('certificates.templates') && (
        <ui.Segmented name="cert-view" label={t('title')} value={view}
          options={[{ value: 'list', label: t('tabs.list') }, { value: 'templates', label: t('tabs.templates') }]}
          onChange={(v) => { const next = new URLSearchParams(params); if (v === 'templates') next.set('view', v); else next.delete('view'); setParams(next, { replace: true }) }} />
      )}
      {issued && <ui.Notice>{issued}</ui.Notice>}
      {view === 'templates' ? <TemplatesPanel /> : <CertificateList />}
      {issuing && <IssueCertificateDialog onClose={() => setIssuing(false)} onDone={(m) => { setIssuing(false); setIssued(m) }} />}
    </div>
  )
}

function CertificateList() {
  const { t, locale } = useCertT()
  const { api, ui, parseError, formatNumber } = useCertificates()
  const [params, setParams] = useSearchParams()
  const options = useCertificateOptions()
  const actions = useCertificateActions()
  const invalidate = useInvalidateCertificates()

  const status = (STATUS_TABS as string[]).includes(params.get('status') ?? '') ? (params.get('status') as StatusTab) : 'draft'
  const type = params.get('type') ?? ''
  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  const page = Number(params.get('page') ?? 1) || 1
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [selected, setSelected] = useState<Set<number>>(new Set())

  const set = (patch: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) { if (v) next.set(k, v); else next.delete(k) }
    if (!('page' in patch)) next.delete('page')
    setParams(next, { replace: true })
  }

  // Debounced search into the URL.
  useEffect(() => {
    const h = setTimeout(() => { if ((params.get('search') ?? '') !== search.trim()) set({ search: search.trim() }) }, 350)
    return () => clearTimeout(h)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const filters = { status: status === 'all' ? '' as const : status, type, from, to, search: params.get('search') ?? '', page, per_page: 25 }
  const q = useQuery({ queryKey: ['certificates', 'list', filters, locale], queryFn: () => api.list(filters), placeholderData: keepPreviousData })

  useEffect(() => { setSelected(new Set()) }, [params])

  const rows = q.data?.data ?? []
  const approvable = rows.filter((c) => c.can?.approve)
  const selectedIds = [...selected].filter((id) => approvable.some((c) => c.id === id))
  const counts = q.data?.meta.status_counts
  const total = counts ? counts.draft + counts.approved + counts.revoked : null

  const bulk = useMutation({
    mutationFn: () => api.approveMany(selectedIds),
    onSuccess: (r) => { invalidate(); setSelected(new Set()); actions.setNotice({ tone: r.approved ? 'success' : 'error', text: r.approved ? t('messages.approved_many', { count: r.approved }) : t('messages.approved_none') }) },
    onError: (e) => actions.setNotice({ tone: 'error', text: parseError(e).message }),
  })

  const toggle = (id: number) => setSelected((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n })
  const allChecked = approvable.length > 0 && approvable.every((c) => selected.has(c.id))
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-4">
      <div role="tablist" aria-label={t('table.status')} className="-mx-4 flex gap-1 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        {STATUS_TABS.map((k) => {
          const count = k === 'all' ? total : counts?.[k]
          const active = status === k
          return (
            <button key={k} type="button" role="tab" aria-selected={active} onClick={() => set({ status: k === 'draft' ? '' : k })}
              className={`inline-flex shrink-0 items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-medium transition ${active ? 'bg-brand-700 text-white shadow-sm' : 'text-ink/65 hover:bg-white hover:text-ink'}`}>
              {t(`status_tabs.${k}`)}
              {count !== undefined && count !== null && (
                <span className={`rounded-full px-1.5 text-xs tabular-nums ${active ? 'bg-white/20' : k === 'draft' && count > 0 ? 'bg-gold-500/15 text-gold-700' : 'bg-ink/6 text-ink/60'}`}>{n(count)}</span>
              )}
            </button>
          )
        })}
      </div>

      <ui.FilterBar layout="grid" label={t('filters.search')} className="sm:grid-cols-2 sm:items-end xl:grid-cols-[2fr_1fr_1fr_1fr_auto]">
        <ui.SearchInput label={t('filters.search')} placeholder={t('filters.search_placeholder')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <ui.SelectField label={t('filters.type')} value={type} onChange={(e) => set({ type: e.target.value })} options={[{ value: '', label: t('filters.all_types') }, ...(options.data?.types ?? [])]} />
        <ui.TextInput type="date" label={t('filters.from')} value={from} max={to || undefined} onChange={(e) => set({ from: e.target.value })} dir="ltr" />
        <ui.TextInput type="date" label={t('filters.to')} value={to} min={from || undefined} onChange={(e) => set({ to: e.target.value })} dir="ltr" />
        {(type || from || to || filters.search) ? (
          <ui.SecondaryButton onClick={() => { setSearch(''); set({ type: '', from: '', to: '', search: '' }) }}>{t('filters.clear')}</ui.SecondaryButton>
        ) : <span className="hidden xl:block" />}
      </ui.FilterBar>

      {actions.notice && <ui.Notice tone={actions.notice.tone}>{actions.notice.text}</ui.Notice>}

      {approvable.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-gold-500/25 bg-gold-500/5 px-4 py-2.5">
          <label className="inline-flex items-center gap-2 text-sm text-ink/75">
            <input type="checkbox" className="size-4 accent-brand-700" checked={allChecked}
              onChange={() => setSelected(allChecked ? new Set() : new Set(approvable.map((c) => c.id)))} />
            {t('table.select_all')}
          </label>
          {selectedIds.length > 0 && <span className="text-sm text-ink/60">{t('selected', { count: selectedIds.length })}</span>}
          <ui.PrimaryButton className="ms-auto" disabled={selectedIds.length === 0} loading={bulk.isPending}
            onClick={() => { if (window.confirm(t('confirm.approve_many', { count: selectedIds.length }))) bulk.mutate() }}>
            <ui.Icon name="check" className="size-4" />{t('actions.approve_selected', { count: selectedIds.length })}
          </ui.PrimaryButton>
        </div>
      )}

      {q.isLoading ? <ui.LoadingState /> : q.isError || !q.data ? <ui.ErrorState onRetry={() => void q.refetch()} /> : rows.length === 0 ? (
        <ui.EmptyCard icon="certificate" title={t('empty')} body={t('empty_body')} />
      ) : (
        <>
          {/* Wide screens: table */}
          <ui.TableWrap surface className="hidden lg:block">
            <table className="w-full text-sm">
              <thead className={ui.classes.tableHead}>
                <tr>
                  <th scope="col" className="w-10 px-4 py-3"><span className="sr-only">{t('table.select_all')}</span></th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('table.recipient')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('table.certificate')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('table.grade')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('table.date')}</th>
                  <th scope="col" className="px-4 py-3 text-start font-medium">{t('table.status')}</th>
                  <th scope="col" className="px-4 py-3 text-end font-medium">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {rows.map((c) => (
                  <tr key={c.id} className={`align-top ${selected.has(c.id) ? 'bg-brand-50/40' : 'hover:bg-ink/[0.015]'}`}>
                    <td className="px-4 py-3">
                      {c.can?.approve && <input type="checkbox" className="mt-1 size-4 accent-brand-700" checked={selected.has(c.id)} onChange={() => toggle(c.id)} aria-label={t('table.select', { name: c.recipient?.name ?? c.certificate_no })} />}
                    </td>
                    <td className="px-4 py-3"><RecipientCell c={c} /></td>
                    <td className="px-4 py-3">
                      <button type="button" onClick={() => actions.run('view', c)} className="text-start hover:underline">
                        <span dir="auto" className={`block font-medium text-ink ${c.status === 'revoked' ? 'line-through decoration-danger/50' : ''}`}>{c.title}</span>
                        <span dir="auto" className="block text-xs text-ink/60">{c.achievement}</span>
                      </button>
                      <span className="mt-0.5 block text-xs text-ink/45"><span className="font-mono tabular-nums">{c.certificate_no}</span> · {c.type_label} · {c.source_label}</span>
                    </td>
                    <td className="px-4 py-3 text-ink/80">{c.grade_label ?? '—'}</td>
                    <td className="px-4 py-3 text-ink/80"><IssuedDate c={c} short /></td>
                    <td className="px-4 py-3"><StatusBadge c={c} /></td>
                    <td className="px-4 py-2.5"><div className="flex justify-end"><ActionButtons c={c} run={actions.run} busy={actions.busy} compact /></div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ui.TableWrap>

          {/* Phones and tablets: cards */}
          <ul className="grid gap-3 *:min-w-0 sm:grid-cols-2 lg:hidden">
            {rows.map((c) => (
              <li key={c.id} className={`rounded-2xl border bg-white p-4 shadow-sm ${selected.has(c.id) ? 'border-brand-600/40' : 'border-ink/8'}`}>
                <div className="flex items-start gap-3">
                  {c.can?.approve && <input type="checkbox" className="mt-2 size-4 accent-brand-700" checked={selected.has(c.id)} onChange={() => toggle(c.id)} aria-label={t('table.select', { name: c.recipient?.name ?? c.certificate_no })} />}
                  <div className="min-w-0 flex-1"><RecipientCell c={c} /></div>
                  <StatusBadge c={c} />
                </div>
                <button type="button" onClick={() => actions.run('view', c)} className="mt-3 block text-start">
                  <span dir="auto" className={`block font-medium text-ink ${c.status === 'revoked' ? 'line-through decoration-danger/50' : ''}`}>{c.title}</span>
                  <span dir="auto" className="block text-sm text-ink/60">{c.achievement}{c.grade_label && <> · {c.grade_label}</>}</span>
                </button>
                <div className="mt-1 flex flex-wrap justify-between gap-2 text-xs text-ink/50">
                  <span className="font-mono tabular-nums">{c.certificate_no} · {c.type_label}</span>
                  <IssuedDate c={c} short />
                </div>
                <div className="mt-3 border-t border-ink/6 pt-2"><ActionButtons c={c} run={actions.run} busy={actions.busy} compact /></div>
              </li>
            ))}
          </ul>

          <ui.Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set({ page: String(p) })} />
        </>
      )}

      {actions.element}
    </div>
  )
}

function RecipientCell({ c }: { c: Certificate }) {
  const { RecipientCard, recipientHref } = useCertificates()
  const r = c.recipient
  if (!r) return <span className="text-ink/50">—</span>
  const href = recipientHref(r)
  return href
    ? <Link to={href} className="block min-w-0 hover:underline"><RecipientCard recipient={r} /></Link>
    : <RecipientCard recipient={r} />
}
