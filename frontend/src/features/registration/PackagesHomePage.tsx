import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { packagesApi, requestsApi, type Package, type RegistrationRequest } from '../../api/registration'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { EmptyState, PageBand } from '../../components/ornaments'
import { Badge, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, TextArea, type Tone } from '../../components/ui'
import { formatDate, formatMoney, formatNumber } from '../../lib/format'
import { GENDER_TONE } from '../lessons/LessonsHomePage'
import PackageFormDialog from './PackageFormDialog'

const STATUS_TONE: Record<string, Tone> = { pending: 'gold', accepted: 'brand', waitlist: 'info', rejected: 'danger', open: 'brand', draft: 'muted', closed: 'muted' }

export default function PackagesHomePage() {
  const { t } = useTranslation('registration')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'requests' || !can('packages.view') ? 'requests' : 'packages'

  return (
    <div className="mx-auto max-w-7xl space-y-5">
      <PageBand title={t('admin.title')} subtitle={t('admin.subtitle')}
        actions={<a href="/register" target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-xl bg-white/90 px-3 py-2 text-sm font-medium text-brand-800 hover:bg-white"><Icon name="packages" className="size-4" />{t('admin.open_public')}</a>} />
      <Segmented name="pkg-tab" label={t('admin.title')} value={tab}
        options={[...(can('packages.view') ? [{ value: 'packages' as const, label: t('admin.tabs.packages') }] : []), ...(can('registrations.view') ? [{ value: 'requests' as const, label: t('admin.tabs.requests') }] : [])]}
        onChange={(v) => setParams({ tab: v }, { replace: true })} />
      {tab === 'packages' ? <Packages /> : <Requests />}
    </div>
  )
}

function Packages() {
  const { t, i18n } = useTranslation('registration')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const [, setParams] = useSearchParams()
  const q = useQuery({ queryKey: ['packages', locale], queryFn: () => packagesApi.list() })
  const [edit, setEdit] = useState<Package | 'new' | null>(null)
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-4">
      {can('packages.manage') && <div className="flex justify-end"><PrimaryButton onClick={() => setEdit('new')}>+ {t('admin.new_package')}</PrimaryButton></div>}
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="packages" title={t('admin.empty_packages')} /></div>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {q.data.data.map((p) => {
            const pct = Math.min(100, Math.round((p.seats_taken * 100) / Math.max(1, p.seats)))
            return (
              <li key={p.id} className="flex flex-col rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
                <div className="flex items-start justify-between gap-2">
                  <p dir="auto" className="font-semibold text-ink">{p.name}</p>
                  <div className="flex gap-1"><Badge tone={GENDER_TONE[p.gender]}>{t(`gender.${p.gender}`)}</Badge><Badge tone={STATUS_TONE[p.status] ?? 'muted'}>{p.status_label}</Badge></div>
                </div>
                <p className="mt-1 text-sm text-ink/60">{t('public.ages', { min: n(p.min_age), max: n(p.max_age) })} · {p.price_fils > 0 ? formatMoney(p.price_fils, locale) : t('public.free')}</p>
                <p className="text-sm text-ink/60">{p.days.map((d) => tl(`days.${d}`)).join(locale === 'ar' ? '، ' : ', ')} · {formatDate(p.start_date, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                <div className="mt-3">
                  <div className="flex justify-between text-xs text-ink/60"><span>{t('admin.seats', { taken: n(p.seats_taken), seats: n(p.seats) })}</span>{p.is_full && <span className="font-medium text-gold-700">{t('admin.full_error')}</span>}</div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-ink/8" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}><div className={`h-full rounded-full ${p.is_full ? 'bg-gold-500' : 'bg-brand-600'}`} style={{ width: `${pct}%` }} /></div>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                  {(p.pending_count ?? 0) > 0 && <button type="button" onClick={() => setParams({ tab: 'requests', status: 'pending', package_id: String(p.id) })}><Badge tone="gold">{t('admin.pending', { n: n(p.pending_count ?? 0) })}</Badge></button>}
                  {(p.waitlist_count ?? 0) > 0 && <button type="button" onClick={() => setParams({ tab: 'requests', status: 'waitlist', package_id: String(p.id) })}><Badge tone="info">{t('admin.waitlist', { n: n(p.waitlist_count ?? 0) })}</Badge></button>}
                  {can('packages.manage') && <SecondaryButton className="ms-auto" onClick={() => setEdit(p)}><Icon name="edit" className="size-4" />{tl('detail.edit')}</SecondaryButton>}
                </div>
              </li>
            )
          })}
        </ul>
      )}
      {edit && <PackageFormDialog pkg={edit === 'new' ? undefined : edit} onClose={() => setEdit(null)} />}
    </div>
  )
}

function Requests() {
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? 'pending'
  const filters = { status: status === 'all' ? undefined : status, package_id: params.get('package_id') ?? undefined, search: params.get('search') ?? undefined, page: Number(params.get('page') ?? 1), per_page: 20 }
  const q = useQuery({ queryKey: ['registrations', filters, locale], queryFn: () => requestsApi.list(filters), placeholderData: keepPreviousData })
  const packages = useQuery({ queryKey: ['packages', locale], queryFn: () => packagesApi.list() })
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [rejecting, setRejecting] = useState<RegistrationRequest | null>(null)
  const [reason, setReason] = useState('')
  const [bulk, setBulk] = useState(false)
  const set = (k: string, v: string) => { const n = new URLSearchParams(params); n.set('tab', 'requests'); if (v) n.set(k, v); else n.delete(k); if (k !== 'page') n.delete('page'); setParams(n, { replace: true }) }
  const n = (v: number) => formatNumber(v, locale)
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['registrations'] }); void qc.invalidateQueries({ queryKey: ['packages'] }); void qc.invalidateQueries({ queryKey: ['dashboard'] }) }
  const onErr = (e: unknown) => setNotice({ tone: 'error', text: parseApiError(e).message })

  const accept = useMutation({ mutationFn: (r: { id: number; force?: boolean }) => requestsApi.accept(r.id, r.force), onSuccess: () => { setNotice({ tone: 'success', text: t('admin.accepted_ok') }); refresh() }, onError: onErr })
  const waitlist = useMutation({ mutationFn: (id: number) => requestsApi.waitlist(id), onSuccess: refresh, onError: onErr })
  const reject = useMutation({ mutationFn: () => requestsApi.reject(rejecting!.id!, reason), onSuccess: () => { setRejecting(null); setReason(''); refresh() }, onError: onErr })
  const manage = can('registrations.manage')

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
        <Segmented name="req-status" label={t('admin.tabs.requests')} value={status} size="sm"
          options={(['pending', 'waitlist', 'accepted', 'rejected', 'all'] as const).map((s) => ({ value: s, label: t(`admin.status_filter.${s}`) }))}
          onChange={(v) => set('status', v)} />
        <SelectField label={t('admin.all_packages')} hideLabel className="w-56" value={filters.package_id ?? ''} onChange={(e) => set('package_id', e.target.value)}
          options={[{ value: '', label: t('admin.all_packages') }, ...(packages.data?.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} />
        <input type="search" defaultValue={filters.search} placeholder={t('admin.search')} aria-label={t('admin.search')} onKeyDown={(e) => e.key === 'Enter' && set('search', (e.target as HTMLInputElement).value)}
          className="min-w-48 flex-1 rounded-xl border border-ink/15 px-3 py-2 text-sm shadow-sm" />
        {manage && <SecondaryButton onClick={() => setBulk(true)}><Icon name="check" className="size-4" />{t('admin.bulk_accept')}</SecondaryButton>}
      </div>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="packages" title={t('admin.empty_requests')} /></div>
      ) : (
        <>
          <ul className="space-y-3">
            {q.data.data.map((r) => (
              <li key={r.request_no} className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
                <div className="flex flex-wrap items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p dir="auto" className="font-semibold text-ink">{r.full_name}</p>
                      <Badge tone={STATUS_TONE[r.status]}>{t(`status.${r.status}`)}{r.status === 'waitlist' && r.waitlist_position ? ` ${t('admin.position', { n: n(r.waitlist_position) })}` : ''}</Badge>
                      <Badge tone={GENDER_TONE[r.gender]}>{t(`gender.${r.gender}`)}</Badge>
                      {r.has_photo && <Badge tone="info"><Icon name="camera" className="size-3.5" />{t('admin.photo')}</Badge>}
                    </div>
                    <p className="mt-1 text-sm text-ink/60">
                      <span className="font-mono tabular-nums" dir="ltr">{r.request_no}</span> · <span dir="auto">{r.package?.name}</span> · {t('admin.age')}: {n(r.age_at_start)} · {r.memorization_level_label}
                    </p>
                    <p className="text-sm text-ink/55">{t('admin.guardian')}: <span dir="auto">{r.guardian_name}</span> · <span dir="ltr" className="tabular-nums">{r.guardian_phone}</span> · {formatDate(r.created_at, locale, { day: 'numeric', month: 'short' })}</p>
                    {r.reason && <p dir="auto" className="mt-1 text-sm text-danger">{r.reason}</p>}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {r.student && <Link to={`/students/${r.student.id}`} className="rounded-xl border border-ink/12 px-3 py-2 text-sm text-ink/80 hover:bg-ink/5">{t('admin.student_link')}</Link>}
                    {manage && r.status !== 'accepted' && (
                      <>
                        <PrimaryButton loading={accept.isPending && accept.variables?.id === r.id} onClick={() => accept.mutate({ id: r.id! })}>{t('admin.accept')}</PrimaryButton>
                        {r.status !== 'waitlist' && <SecondaryButton onClick={() => waitlist.mutate(r.id!)}>{t('admin.waitlist_action')}</SecondaryButton>}
                        {r.status !== 'rejected' && <SecondaryButton className="text-danger" onClick={() => setRejecting(r)}>{t('admin.reject')}</SecondaryButton>}
                      </>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set('page', String(p))} />
        </>
      )}

      {rejecting && (
        <Modal title={`${t('admin.reject')}: ${rejecting.full_name}`} onClose={() => setRejecting(null)}
          footer={<><SecondaryButton onClick={() => setRejecting(null)}>{t('form.cancel')}</SecondaryButton><PrimaryButton tone="danger" disabled={reason.trim().length < 3} loading={reject.isPending} onClick={() => reject.mutate()}>{t('admin.confirm_reject')}</PrimaryButton></>}>
          <TextArea label={t('admin.reject_reason')} value={reason} onChange={(e) => setReason(e.target.value)} dir="auto" />
        </Modal>
      )}
      {bulk && <BulkAccept packages={packages.data?.data ?? []} onClose={() => setBulk(false)} onDone={(m) => { setBulk(false); setNotice({ tone: 'success', text: m }); refresh() }} />}
    </div>
  )
}

function BulkAccept({ packages, onClose, onDone }: { packages: Package[]; onClose: () => void; onDone: (m: string) => void }) {
  const { t, i18n } = useTranslation('registration')
  const n = (v: number) => formatNumber(v, i18n.language)
  const [packageId, setPackageId] = useState(packages[0]?.id ?? 0)
  const [includeWait, setIncludeWait] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const run = useMutation({
    mutationFn: () => requestsApi.bulkAccept({ package_id: packageId, statuses: includeWait ? ['pending', 'waitlist'] : ['pending'] }),
    onSuccess: (r) => onDone(t('admin.bulk_result', { accepted: n(r.accepted.length), skipped: n(r.skipped.length), left: n(r.seats_left) })),
    onError: (e) => setError(parseApiError(e).message),
  })
  return (
    <Modal title={t('admin.bulk_title')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={!packageId} loading={run.isPending} onClick={() => run.mutate()}>{t('admin.bulk_accept')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      <p className="text-sm text-ink/65">{t('admin.bulk_body')}</p>
      <SelectField label={t('track.package')} value={packageId} onChange={(e) => setPackageId(Number(e.target.value))} options={packages.map((p) => ({ value: String(p.id), label: `${p.name} (${n(p.seats_left)})` }))} />
      <label className="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" className="size-4 accent-brand-600" checked={includeWait} onChange={(e) => setIncludeWait(e.target.checked)} />{t('admin.include_waitlist')}</label>
    </Modal>
  )
}
