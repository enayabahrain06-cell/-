import { useCallback, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  CertificatesPage, IssueCertificateDialog, TemplatesPanel, availableActions, useCertificateActions, useCertificateOptions, useCertificates,
  type ActionKind,
} from '@ahl/certificates-react'
import Icon from '../../components/Icon'
import { Khatam } from '../../components/ornaments'
import BottomSheet from '../../components/mobile/BottomSheet'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import MobileToast from '../../components/mobile/Toast'
import { Chip, ChipRow, MCard, MEmpty, MList, MListSkeleton, MSearch, MSelect, Pill, Skeleton, M_BTN_PRIMARY, M_BTN_SECONDARY, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatNumber } from '../../lib/format'

/**
 * /certificates. The page itself comes from @ahl/certificates-react; below lg this app draws its own mobile screen
 * (mobile-redesign-spec.md §6.14) from the package's API, hooks and dialogs, and the package page stays for lg+.
 */
export default function CertificatesHome() {
  return (
    <>
      <MobileCertificates />
      <div className="hidden lg:block"><CertificatesPage /></div>
    </>
  )
}

type StatusTab = 'draft' | 'approved' | 'revoked' | 'all'
const STATUS_TABS: StatusTab[] = ['draft', 'approved', 'revoked', 'all']
const STATUS_TONE: Record<string, PillTone> = { draft: 'warn', approved: 'ok', revoked: 'err' }
const ACTION_ICON: Record<ActionKind, string> = { view: 'eye', approve: 'check', edit: 'edit', delete: 'trash', revoke: 'ban', send: 'messages', download: 'download', print: 'printer', share: 'share' }

function MobileCertificates() {
  const { t } = useTranslation('certificates')
  const { can } = useCertificates()
  const [params] = useSearchParams()
  // Template editing (?view=templates): the package panel under the mobile header.
  if (params.get('view') === 'templates' && can('certificates.templates')) {
    return (
      <div className="space-y-4 lg:hidden">
        <MobilePage title={t('tabs.templates')} back="/certificates" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/certificates' }, { label: t('tabs.templates') }]} />
        <TemplatesPanel />
      </div>
    )
  }
  return <CertificateList />
}

function CertificateList() {
  const { t, i18n } = useTranslation('certificates')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const { api, can } = useCertificates()
  const options = useCertificateOptions()
  const actions = useCertificateActions()
  const [params, setParams] = useSearchParams()
  const [issuing, setIssuing] = useState(false)
  const [filterSheet, setFilterSheet] = useState(false)
  const [actionSheet, setActionSheet] = useState(false)
  const [picked, setPicked] = useState<number | null>(null)
  const [toast, setToast] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null)
  const clearToast = useCallback(() => setToast(null), [])

  // Same URL params and query key as the package list, so both variants share one cache entry.
  const status = (STATUS_TABS as string[]).includes(params.get('status') ?? '') ? (params.get('status') as StatusTab) : 'draft'
  const type = params.get('type') ?? ''
  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  const page = Number(params.get('page') ?? 1) || 1
  const [search, setSearch] = useState(params.get('search') ?? '')
  const set = (patch: Record<string, string>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) { if (v) next.set(k, v); else next.delete(k) }
    if (!('page' in patch)) next.delete('page')
    setParams(next, { replace: true })
  }
  const filters = { status: status === 'all' ? '' as const : status, type, from, to, search: params.get('search') ?? '', page, per_page: 25 }
  const q = useQuery({ queryKey: ['certificates', 'list', filters, locale], queryFn: () => api.list(filters), placeholderData: keepPreviousData })

  // Action results (approve, send, delete…) from the package hook show as the 4-second toast until it times out.
  const [seen, setSeen] = useState<object | null>(null)
  const notice = actions.notice && actions.notice !== seen ? actions.notice : null
  const shown = notice ? { tone: notice.tone === 'error' ? 'error' as const : 'ok' as const, text: notice.text } : toast
  const clearShown = useCallback(() => (notice ? setSeen(notice) : clearToast()), [notice, clearToast])

  const rows = q.data?.data ?? []
  const counts = q.data?.meta.status_counts
  const selected = rows.find((c) => c.id === picked) ?? rows[0]
  const can3 = selected ? availableActions(selected) : []
  const printKind: ActionKind | null = can3.includes('print') ? 'print' : can3.includes('download') ? 'download' : can3.includes('view') ? 'view' : null
  const others = can3.filter((k) => k !== printKind && k !== 'send')
  const filtered = !!(type || from || to)

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage title={t('title')} back="/" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title') }]}
        actions={can('certificates.templates') ? <HeaderAction icon="settings" label={t('tabs.templates')} to="/certificates?view=templates" /> : undefined} />


      {/* Live preview of the selected certificate: A-landscape frame, gold inner border, corner star. */}
      {q.isLoading ? <Skeleton className="aspect-[297/210] w-full rounded-card" /> : selected && (
        <section aria-label={t('mobile.preview')} className="space-y-3">
          <div className="relative aspect-[297/210] overflow-hidden rounded-card bg-deep p-2.5 shadow-card">
            <div className="flex h-full flex-col items-center justify-center rounded-[8px] border border-gold-500/60 px-4 text-center">
              <p className="text-xs font-semibold text-gold-300">{selected.type_label}</p>
              <p className="mt-1 line-clamp-2 font-display text-[22px] leading-8 text-gold-300"><bdi>{selected.title}</bdi></p>
              <p className="mt-1 truncate font-display text-xl leading-8 text-white"><bdi>{selected.recipient?.name ?? '—'}</bdi></p>
              <p className="mt-1 line-clamp-2 text-xs text-white/80">
                <bdi>{selected.achievement}</bdi>{selected.issued_on && <> · <span className="tabular-nums">{formatDate(selected.issued_on, locale)}</span></>}
              </p>
            </div>
            <Khatam className="pointer-events-none absolute -end-4 -top-4 size-20 text-gold-500 opacity-10" />
            <span className="absolute start-3 top-3"><Pill tone={STATUS_TONE[selected.status] ?? 'neutral'}>{selected.status_label}</Pill></span>
          </div>
          <div className="grid grid-cols-3 gap-2">
            <button type="button" disabled={!printKind} onClick={() => printKind && actions.run(printKind, selected)} className={`${M_BTN_SECONDARY} flex-col gap-1 px-2 py-2 text-[13px]`}>
              <Icon name="printer" className="size-5" />{t('mobile.print')}
            </button>
            <button type="button" disabled={!can3.includes('send') || actions.busy === `send-${selected.id}`} onClick={() => actions.run('send', selected)} className={`${M_BTN_SECONDARY} flex-col gap-1 px-2 py-2 text-[13px]`}>
              <Icon name="messages" className="size-5" />{t('actions.resend_short')}
            </button>
            {can('certificates.templates')
              ? <Link to="/certificates?view=templates" className={`${M_BTN_SECONDARY} flex-col gap-1 px-2 py-2 text-[13px]`}><Icon name="certificate" className="size-5" />{t('mobile.template')}</Link>
              : <button type="button" disabled={others.length === 0} onClick={() => setActionSheet(true)} className={`${M_BTN_SECONDARY} flex-col gap-1 px-2 py-2 text-[13px]`}><Icon name="more" className="size-5" />{t('mobile.more_actions')}</button>}
          </div>
          {can('certificates.templates') && others.length > 0 && (
            <button type="button" onClick={() => setActionSheet(true)} className="-my-1 inline-flex min-h-11 items-center gap-1.5 text-[13px] font-semibold text-info">
              <Icon name="more" className="size-4" />{t('mobile.more_actions')}
            </button>
          )}
        </section>
      )}

      <div className="flex gap-2">
        <form className="min-w-0 flex-1" onSubmit={(e) => { e.preventDefault(); set({ search: search.trim() }) }}>
          <MSearch label={t('filters.search')} value={search} onChange={(v) => { setSearch(v); if (!v) set({ search: '' }) }} />
        </form>
        <button type="button" onClick={() => setFilterSheet(true)} aria-label={t('mobile.filters')} title={t('mobile.filters')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="filter" className="size-5" />
          {filtered && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>
      <ChipRow label={t('table.status')}>
        {STATUS_TABS.map((k) => {
          const count = k === 'all' ? (counts ? counts.draft + counts.approved + counts.revoked : undefined) : counts?.[k]
          return (
            <Chip key={k} active={status === k} onClick={() => set({ status: k === 'draft' ? '' : k })}>
              {t(`status_tabs.${k}`)}{count !== undefined && <span className="tabular-nums">{n(count)}</span>}
            </Chip>
          )
        })}
      </ChipRow>

      {q.isLoading ? <MListSkeleton rows={4} /> : q.isError || !q.data ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={() => void q.refetch()} className={M_BTN_SECONDARY}>{t('actions.retry')}</button>} /></MCard>
      ) : rows.length === 0 ? (
        <MCard><MEmpty icon="certificate" text={t('empty')} /></MCard>
      ) : (
        <MList label={t('title')}>
          {rows.map((c) => (
            <li key={c.id}>
              <button type="button" aria-pressed={selected?.id === c.id} onClick={() => { setPicked(c.id); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
                className={`flex min-h-16 w-full items-center gap-3 px-4 py-2.5 text-start ${selected?.id === c.id ? 'bg-brand-50/60' : ''}`}>
                <span aria-hidden className="inline-grid size-10 shrink-0 place-items-center rounded-full bg-gold-500/12 text-gold-700"><Icon name="certificate" className="size-5" /></span>
                <span className="min-w-0 flex-1">
                  <span className={`block truncate text-[15px] font-semibold text-ink ${c.status === 'revoked' ? 'line-through decoration-danger/50' : ''}`}><bdi>{c.title}</bdi></span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                    <bdi>{c.recipient?.name ?? '—'}</bdi>{c.issued_on && <> · <span className="tabular-nums">{formatDate(c.issued_on, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span></>}{c.issued_by && <> · <bdi>{c.issued_by}</bdi></>}
                  </span>
                </span>
                <Pill tone={STATUS_TONE[c.status] ?? 'neutral'}>{c.status_label}</Pill>
              </button>
            </li>
          ))}
        </MList>
      )}
      {q.data && q.data.meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-3 text-[13px] text-ink/65">
          <button type="button" disabled={page <= 1} onClick={() => set({ page: String(page - 1) })} className={`${M_BTN_SECONDARY} px-3 text-[13px]`}>{t('mobile.prev')}</button>
          <span className="tabular-nums">{t('mobile.page', { page: n(page), last: n(q.data.meta.last_page) })}</span>
          <button type="button" disabled={page >= q.data.meta.last_page} onClick={() => set({ page: String(page + 1) })} className={`${M_BTN_SECONDARY} px-3 text-[13px]`}>{t('mobile.next')}</button>
        </div>
      )}

      {can('certificates.issue') && (
        <StickyActionBar>
          <button type="button" onClick={() => setIssuing(true)} className={`${M_BTN_PRIMARY} flex-1`}><Icon name="certificate" className="size-5" />{t('mobile.issue_new')}</button>
        </StickyActionBar>
      )}

      <BottomSheet open={filterSheet} onClose={() => setFilterSheet(false)} title={t('mobile.filters')}
        footer={<>
          {filtered && <button type="button" onClick={() => set({ type: '', from: '', to: '' })} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('filters.clear')}</button>}
          <button type="button" onClick={() => setFilterSheet(false)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>
        </>}>
        <div className="space-y-4">
          <MSelect label={t('filters.type')} value={type} onChange={(v) => set({ type: v })} options={[{ value: '', label: t('filters.all_types') }, ...(options.data?.types ?? [])]} />
          <div className="grid grid-cols-2 gap-3">
            {([['from', from], ['to', to]] as const).map(([k, v]) => (
              <label key={k} className="block min-w-0">
                <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{t(`filters.${k}`)}</span>
                <input type="date" dir="ltr" value={v} min={k === 'to' ? from || undefined : undefined} max={k === 'from' ? to || undefined : undefined} onChange={(e) => set({ [k]: e.target.value })}
                  className="h-12 w-full min-w-0 rounded-md border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
              </label>
            ))}
          </div>
        </div>
      </BottomSheet>

      <BottomSheet open={actionSheet && !!selected} onClose={() => setActionSheet(false)} title={t('mobile.more_actions')}>
        {selected && (
          <MList>
            {others.map((k) => (
              <li key={k}>
                <button type="button" onClick={() => { setActionSheet(false); actions.run(k, selected) }}
                  className={`flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] ${k === 'delete' || k === 'revoke' ? 'text-danger' : 'text-ink'}`}>
                  <Icon name={ACTION_ICON[k]} className={`size-5 ${k === 'delete' || k === 'revoke' ? '' : 'text-brand-700'}`} />{t(`actions.${k}`)}
                </button>
              </li>
            ))}
          </MList>
        )}
      </BottomSheet>

      {issuing && <IssueCertificateDialog onClose={() => setIssuing(false)} onDone={(m) => { setIssuing(false); setToast({ tone: 'ok', text: m }) }} />}
      {actions.element}
      <MobileToast message={shown?.text ?? null} tone={shown?.tone} onDone={clearShown} />
    </div>
  )
}

