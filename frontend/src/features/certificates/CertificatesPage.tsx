import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { certificatesApi, type Certificate, type CertificateStatus } from '../../api/certificates'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { EmptyState, PageBand } from '../../components/ornaments'
import { ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { ActionButtons, IssueCertificateDialog, useCertificateActions, useCertificateOptions } from './CertificateDialogs'
import { IssuedDate, StatusBadge, useInvalidateCertificates } from './shared'
import TemplatesPanel from './TemplatesPanel'

type StatusTab = CertificateStatus | 'all'
const STATUS_TABS: StatusTab[] = ['draft', 'approved', 'revoked', 'all']

export default function CertificatesPage() {
  const { t } = useTranslation('certificates')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [issuing, setIssuing] = useState(false)
  const [issued, setIssued] = useState<string | null>(null)
  const view = params.get('view') === 'templates' && can('certificates.templates') ? 'templates' : 'list'

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')}
        actions={can('certificates.issue') && view === 'list' && (
          <button type="button" onClick={() => { setIssued(null); setIssuing(true) }} className="inline-flex items-center gap-1.5 rounded-xl bg-white/90 px-3 py-2 text-sm font-semibold text-brand-800 hover:bg-white">
            <Icon name="certificate" className="size-4" />{t('actions.issue')}
          </button>
        )} />
      {can('certificates.templates') && (
        <Segmented name="cert-view" label={t('title')} value={view}
          options={[{ value: 'list', label: t('tabs.list') }, { value: 'templates', label: t('tabs.templates') }]}
          onChange={(v) => { const next = new URLSearchParams(params); if (v === 'templates') next.set('view', v); else next.delete('view'); setParams(next, { replace: true }) }} />
      )}
      {issued && <Notice>{issued}</Notice>}
      {view === 'templates' ? <TemplatesPanel /> : <CertificateList />}
      {issuing && <IssueCertificateDialog onClose={() => setIssuing(false)} onDone={(m) => { setIssuing(false); setIssued(m) }} />}
    </div>
  )
}

function CertificateList() {
  const { t, i18n } = useTranslation('certificates')
  const locale = i18n.language
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
  const q = useQuery({ queryKey: ['certificates', 'list', filters, locale], queryFn: () => certificatesApi.list(filters), placeholderData: keepPreviousData })

  useEffect(() => { setSelected(new Set()) }, [params])

  const rows = q.data?.data ?? []
  const approvable = rows.filter((c) => c.can?.approve)
  const selectedIds = [...selected].filter((id) => approvable.some((c) => c.id === id))
  const counts = q.data?.meta.status_counts
  const total = counts ? counts.draft + counts.approved + counts.revoked : null

  const bulk = useMutation({
    mutationFn: () => certificatesApi.approveMany(selectedIds),
    onSuccess: (r) => { invalidate(); setSelected(new Set()); actions.setNotice({ tone: r.approved ? 'success' : 'error', text: r.approved ? t('messages.approved_many', { count: r.approved }) : t('messages.approved_none') }) },
    onError: (e) => actions.setNotice({ tone: 'error', text: parseApiError(e).message }),
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

      <div className="grid gap-3 rounded-2xl border border-ink/8 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto] lg:items-end">
        <TextInput type="search" label={t('filters.search')} placeholder={t('filters.search_placeholder')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <SelectField label={t('filters.type')} value={type} onChange={(e) => set({ type: e.target.value })} options={[{ value: '', label: t('filters.all_types') }, ...(options.data?.types ?? [])]} />
        <TextInput type="date" label={t('filters.from')} value={from} max={to || undefined} onChange={(e) => set({ from: e.target.value })} dir="ltr" />
        <TextInput type="date" label={t('filters.to')} value={to} min={from || undefined} onChange={(e) => set({ to: e.target.value })} dir="ltr" />
        {(type || from || to || filters.search) ? (
          <SecondaryButton onClick={() => { setSearch(''); set({ type: '', from: '', to: '', search: '' }) }}>{t('filters.clear')}</SecondaryButton>
        ) : <span className="hidden lg:block" />}
      </div>

      {actions.notice && <Notice tone={actions.notice.tone}>{actions.notice.text}</Notice>}

      {approvable.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-gold-500/25 bg-gold-500/5 px-4 py-2.5">
          <label className="inline-flex items-center gap-2 text-sm text-ink/75">
            <input type="checkbox" className="size-4 accent-brand-700" checked={allChecked}
              onChange={() => setSelected(allChecked ? new Set() : new Set(approvable.map((c) => c.id)))} />
            {t('table.select_all')}
          </label>
          {selectedIds.length > 0 && <span className="text-sm text-ink/60">{t('selected', { count: selectedIds.length })}</span>}
          <PrimaryButton className="ms-auto" disabled={selectedIds.length === 0} loading={bulk.isPending}
            onClick={() => { if (window.confirm(t('confirm.approve_many', { count: selectedIds.length }))) bulk.mutate() }}>
            <Icon name="check" className="size-4" />{t('actions.approve_selected', { count: selectedIds.length })}
          </PrimaryButton>
        </div>
      )}

      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : rows.length === 0 ? (
        <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="certificate" title={t('empty')} body={t('empty_body')} /></div>
      ) : (
        <>
          {/* Wide screens: table */}
          <div className="hidden overflow-x-auto rounded-2xl border border-ink/8 bg-white shadow-sm lg:block">
            <table className="w-full text-sm">
              <thead className="bg-ink/[0.03] text-start text-xs text-ink/55">
                <tr>
                  <th scope="col" className="w-10 px-3 py-2.5"><span className="sr-only">{t('table.select_all')}</span></th>
                  <th scope="col" className="px-3 py-2.5 text-start font-medium">{t('table.student')}</th>
                  <th scope="col" className="px-3 py-2.5 text-start font-medium">{t('table.certificate')}</th>
                  <th scope="col" className="px-3 py-2.5 text-start font-medium">{t('table.grade')}</th>
                  <th scope="col" className="px-3 py-2.5 text-start font-medium">{t('table.date')}</th>
                  <th scope="col" className="px-3 py-2.5 text-start font-medium">{t('table.status')}</th>
                  <th scope="col" className="px-3 py-2.5 text-end font-medium">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {rows.map((c) => (
                  <tr key={c.id} className={`align-top ${selected.has(c.id) ? 'bg-brand-50/40' : 'hover:bg-ink/[0.015]'}`}>
                    <td className="px-3 py-3">
                      {c.can?.approve && <input type="checkbox" className="mt-1 size-4 accent-brand-700" checked={selected.has(c.id)} onChange={() => toggle(c.id)} aria-label={t('table.select', { name: c.student?.full_name ?? c.certificate_no })} />}
                    </td>
                    <td className="px-3 py-3"><StudentCell c={c} /></td>
                    <td className="px-3 py-3">
                      <button type="button" onClick={() => actions.run('view', c)} className="text-start hover:underline">
                        <span dir="auto" className={`block font-medium text-ink ${c.status === 'revoked' ? 'line-through decoration-danger/50' : ''}`}>{c.title}</span>
                        <span dir="auto" className="block text-xs text-ink/60">{c.achievement}</span>
                      </button>
                      <span className="mt-0.5 block text-xs text-ink/45"><span className="font-mono tabular-nums">{c.certificate_no}</span> · {c.type_label} · {c.source_label}</span>
                    </td>
                    <td className="px-3 py-3 text-ink/80">{c.grade_label ?? '—'}</td>
                    <td className="px-3 py-3 text-ink/80"><IssuedDate c={c} short /></td>
                    <td className="px-3 py-3"><StatusBadge c={c} /></td>
                    <td className="px-3 py-2"><div className="flex justify-end"><ActionButtons c={c} run={actions.run} busy={actions.busy} compact /></div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Phones and tablets: cards */}
          <ul className="grid gap-3 sm:grid-cols-2 lg:hidden">
            {rows.map((c) => (
              <li key={c.id} className={`rounded-2xl border bg-white p-4 shadow-sm ${selected.has(c.id) ? 'border-brand-600/40' : 'border-ink/8'}`}>
                <div className="flex items-start gap-3">
                  {c.can?.approve && <input type="checkbox" className="mt-2 size-4 accent-brand-700" checked={selected.has(c.id)} onChange={() => toggle(c.id)} aria-label={t('table.select', { name: c.student?.full_name ?? c.certificate_no })} />}
                  <div className="min-w-0 flex-1"><StudentCell c={c} /></div>
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

          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set({ page: String(p) })} />
        </>
      )}

      {actions.element}
    </div>
  )
}

function StudentCell({ c }: { c: Certificate }) {
  const s = c.student
  if (!s) return <span className="text-ink/50">—</span>
  return (
    <Link to={`/students/${s.id}?tab=certificates`} className="flex min-w-0 items-center gap-2.5 hover:underline">
      <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
      <span className="min-w-0">
        <span dir="auto" className="block truncate font-medium text-ink">{s.full_name}</span>
        <span className="block text-xs tabular-nums text-ink/50">{s.student_no}</span>
      </span>
    </Link>
  )
}
