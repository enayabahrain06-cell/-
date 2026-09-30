import { useCallback, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { packagesApi, publicApi, requestsApi, type Package, type RegistrationRequest } from '../../api/registration'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { PageBand } from '../../components/ornaments'
import { Badge, buttonClass, ErrorState, FilterBar, LoadingState, Modal, Notice, PrimaryButton, SearchInput, SecondaryButton, Segmented, TextArea, type Tone, SURFACE, EmptyCard, ROW_MAIN } from '../../components/ui'
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../lib/format'
import { GENDER_TONE } from '../lessons/LessonsHomePage'
import PackageFormDialog from './PackageFormDialog'
import CardApplyDialog from '../enrollment/CardApplyDialog'
import { MobilePackagesHeader, MobileRequests } from './MobilePackages'
import { MobilePackageList } from './MobilePackageList'
import MobileToast from '../../components/mobile/Toast'
import { useEmbed, useOwnParam } from '../../app/embed'

/** Outcomes the server treats as final (RegistrationStatus::isDecided); accept, waitlist and reject are refused for them. */
const DECIDED: string[] = ['accepted', 'enrolled', 'pending_lottery']
const STATUS_TONE: Record<string, Tone> = { pending: 'gold', accepted: 'brand', enrolled: 'brand', pending_lottery: 'gold', waitlist: 'info', rejected: 'danger', open: 'brand', draft: 'muted', closed: 'muted' }

export default function PackagesHomePage() {
  const { t } = useTranslation('registration')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tab = ownTab === 'requests' || !can('packages.view') ? 'requests' : 'packages'
  const tabs = [...(can('packages.view') ? [{ value: 'packages' as const, label: t('admin.tabs.packages') }] : []), ...(can('registrations.view') ? [{ value: 'requests' as const, label: t('admin.tabs.requests') }] : [])]
  const onTab = (v: 'packages' | 'requests') => setParams({ tab: v }, { replace: true })

  return (
    <div className="space-y-5">
      {/* Below lg: page header + segmented tabs (MobilePackages); the band and desktop tabs stay as they are. */}
      <MobilePackagesHeader tab={tab} tabs={host ? [] : tabs} onTab={onTab} />
      <div className="hidden lg:block">
        <PageBand title={t('admin.title')} subtitle={t('admin.subtitle')}
          actions={<a href="/register" target="_blank" rel="noreferrer" className={buttonClass('onDeep')}><Icon name="packages" className="size-4" />{t('admin.open_public')}</a>} />
      </div>
      {!host && <div className="hidden lg:block">
        <Segmented name="pkg-tab" label={t('admin.title')} value={tab} options={tabs} onChange={onTab} />
      </div>}
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
  // Mobile card switch: the same package update the edit dialog sends, with only the status.
  const qc = useQueryClient()
  const [toast, setToast] = useState<string | null>(null)
  const clearToast = useCallback(() => setToast(null), [])
  const toggle = useMutation({
    mutationFn: ({ p, open }: { p: Package; open: boolean }) => packagesApi.update(p.id, { status: open ? 'open' : 'closed' }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['packages'] }),
    onError: (e) => setToast(parseApiError(e).message),
  })

  return (
    <div className="space-y-4">
      <MobilePackageList packages={q.data?.data} loading={q.isLoading} error={q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        canManage={can('packages.manage')} onEdit={setEdit} onCreate={() => setEdit('new')}
        onToggle={(p, open) => toggle.mutate({ p, open })} toggling={toggle.isPending ? toggle.variables?.p.id ?? null : null}
        onRequests={(p, status) => setParams({ tab: 'requests', status, package_id: String(p.id) })} />
      <MobileToast message={toast} tone="error" onDone={clearToast} />
      <div className="hidden space-y-4 lg:block">
      {can('packages.manage') && <div className="flex justify-end"><PrimaryButton onClick={() => setEdit('new')}>+ {t('admin.new_package')}</PrimaryButton></div>}
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="packages" title={t('admin.empty_packages')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {q.data.data.map((p) => {
            const pct = Math.min(100, Math.round((p.seats_taken * 100) / Math.max(1, p.seats)))
            return (
              <li key={p.id} className={`${SURFACE} flex flex-col p-4`}>
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
      </div>
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
  // Old links may still say ?status=accepted; the server calls that outcome enrolled.
  const rawStatus = params.get('status') ?? 'pending'
  const status = rawStatus === 'accepted' ? 'enrolled' : rawStatus
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

  // Accepting needs a circle (or the lottery path) and lets staff confirm the level, so it opens a dialog.
  const [accepting, setAccepting] = useState<RegistrationRequest | null>(null)
  // Read the ID card for a request and let staff apply the card's details (and optionally its photo).
  const [carding, setCarding] = useState<RegistrationRequest | null>(null)
  const waitlist = useMutation({ mutationFn: (id: number) => requestsApi.waitlist(id), onSuccess: refresh, onError: onErr })
  const reject = useMutation({ mutationFn: () => requestsApi.reject(rejecting!.id!, reason), onSuccess: () => { setRejecting(null); setReason(''); refresh() }, onError: onErr })
  const manage = can('registrations.manage')
  const pagination = q.data && q.data.data.length > 0 ? <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set('page', String(p))} /> : null

  return (
    <div className="space-y-4">
      <MobileRequests rows={q.data?.data} total={q.data?.meta.total} loading={q.isLoading} error={q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : null}
        status={status} filters={filters} packages={packages.data?.data ?? []} manage={manage} decided={DECIDED} onSet={set}
        onAccept={setAccepting} onWaitlist={(r) => waitlist.mutate(r.id!)} onReject={setRejecting} onCard={setCarding} onBulk={() => setBulk(true)}
        notice={notice} pagination={pagination} />
      <div className="hidden space-y-4 lg:block">
      <FilterBar>
        <Segmented name="req-status" label={t('admin.tabs.requests')} value={status} size="sm"
          options={(['pending', 'waitlist', 'pending_lottery', 'enrolled', 'rejected', 'all'] as const).map((s) => ({ value: s, label: t(`admin.status_filter.${s}`) }))}
          onChange={(v) => set('status', v)} />
        <SelectField label={t('admin.all_packages')} hideLabel className="sm:w-56" value={filters.package_id ?? ''} onChange={(e) => set('package_id', e.target.value)}
          options={[{ value: '', label: t('admin.all_packages') }, ...(packages.data?.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} />
        <SearchInput className="sm:min-w-48 sm:flex-1" label={t('admin.search')} defaultValue={filters.search} onKeyDown={(e) => e.key === 'Enter' && set('search', (e.target as HTMLInputElement).value)} />
        {manage && <SecondaryButton onClick={() => setBulk(true)}><Icon name="check" className="size-4" />{t('admin.bulk_accept')}</SecondaryButton>}
      </FilterBar>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="packages" title={t('admin.empty_requests')} />
      ) : (
        <>
          <ul className="space-y-3">
            {q.data.data.map((r) => (
              <li key={r.request_no} className={`${SURFACE} p-4`}>
                <div className="flex flex-wrap items-start gap-3">
                  <div className={ROW_MAIN}>
                    <div className="flex flex-wrap items-center gap-2">
                      <p dir="auto" className="font-semibold text-ink">{r.full_name}</p>
                      <Badge tone={STATUS_TONE[r.status]}>{t(`status.${r.status}`)}{r.status === 'waitlist' && r.waitlist_position ? ` ${t('admin.position', { n: n(r.waitlist_position) })}` : ''}</Badge>
                      <Badge tone={GENDER_TONE[r.gender]}>{t(`gender.${r.gender}`)}</Badge>
                      {r.has_photo && <Badge tone="info"><Icon name="camera" className="size-3.5" />{t('admin.photo')}</Badge>}
                    </div>
                    <p className="mt-1 text-sm text-ink/60">
                      <span className="font-mono tabular-nums" dir="ltr">{r.request_no}</span>{r.cpr && <> · {t('admin.cpr')}: <span className="tabular-nums" dir="ltr">{r.cpr}</span></>} · <span dir="auto">{r.package?.name}</span> · {t('admin.age')}: {n(r.age_at_start)} · {r.memorization_level_label}
                    </p>
                    <p className="text-sm text-ink/55">{t('admin.guardian')}: <span dir="auto">{r.guardian_name}</span> · <span dir="ltr" className="tabular-nums">{r.guardian_phone}</span> · {formatDate(r.created_at, locale, { day: 'numeric', month: 'short' })}</p>
                    {r.reason && <p dir="auto" className="mt-1 text-sm text-danger">{r.reason}</p>}
                    <PlacementLine r={r} locale={locale} />
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {r.student && <Link to={`/students/${r.student.id}`} className="rounded-xl border border-ink/12 px-3 py-2 text-sm text-ink/80 hover:bg-ink/5">{t('admin.student_link')}</Link>}
                    {manage && !DECIDED.includes(r.status) && (
                      <>
                        <SecondaryButton onClick={() => setCarding(r)}><Icon name="students" className="size-4" />{t('read', { ns: 'idCard' })}</SecondaryButton>
                        <PrimaryButton onClick={() => setAccepting(r)}>{t('admin.accept')}</PrimaryButton>
                        {r.status !== 'waitlist' && <SecondaryButton onClick={() => waitlist.mutate(r.id!)}>{t('admin.waitlist_action')}</SecondaryButton>}
                        {r.status !== 'rejected' && <SecondaryButton className="text-danger" onClick={() => setRejecting(r)}>{t('admin.reject')}</SecondaryButton>}
                      </>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
          {pagination}
        </>
      )}
      </div>

      {rejecting && (
        <Modal title={`${t('admin.reject')}: ${rejecting.full_name}`} onClose={() => setRejecting(null)}
          footer={<><SecondaryButton onClick={() => setRejecting(null)}>{t('form.cancel')}</SecondaryButton><PrimaryButton tone="danger" disabled={reason.trim().length < 3} loading={reject.isPending} onClick={() => reject.mutate()}>{t('admin.confirm_reject')}</PrimaryButton></>}>
          <TextArea label={t('admin.reject_reason')} value={reason} onChange={(e) => setReason(e.target.value)} dir="auto" />
        </Modal>
      )}
      {accepting && <AcceptDialog request={accepting} onClose={() => setAccepting(null)} onDone={() => { setAccepting(null); setNotice({ tone: 'success', text: t('admin.accepted_ok') }); refresh() }} />}
      {carding && (
        <CardApplyDialog title={t('apply_title', { ns: 'idCard', name: carding.full_name })} allowPhoto
          current={{ full_name: carding.full_name, birth_date: carding.birth_date?.slice(0, 10) ?? '', gender: carding.gender, cpr: carding.cpr ?? '', address: carding.address ?? '' }}
          onClose={() => setCarding(null)}
          onApply={async (patch, photo) => {
            const { gender, ...rest } = patch
            if (Object.keys(patch).length) await requestsApi.update(carding.id!, { ...rest, ...(gender ? { gender } : {}) })
            if (photo) await requestsApi.photo(carding.id!, photo)
            setCarding(null)
            setNotice({ tone: 'success', text: t('applied', { ns: 'idCard', name: patch.full_name ?? carding.full_name }) })
            refresh()
          }} />
      )}
      {bulk && <BulkAccept packages={packages.data?.data ?? []} onClose={() => setBulk(false)} onDone={(m) => { setBulk(false); setNotice({ tone: 'success', text: m }); refresh() }} />}
    </div>
  )
}

/** Placement result and levels on a request row: score, recommended level, and the level staff confirmed. */
function PlacementLine({ r, locale }: { r: RegistrationRequest; locale: string }) {
  const { t } = useTranslation('registration')
  const n = (v: number) => formatNumber(v, locale)
  const p = r.placement
  if (!p && !r.final_level) return null
  return (
    <div className="mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-page/70 px-3 py-2 text-sm">
      {p && (
        <>
          <span className="inline-flex items-center gap-1.5 font-medium text-ink"><Icon name="exams" className="size-4 text-ink/45" />{t('admin.placement.score', { percent: formatPercent(p.percent, locale) })}</span>
          <span className="tabular-nums text-ink/60">{t('admin.placement.correct', { correct: n(p.correct), total: n(p.total_questions) })}</span>
          {p.attempt_no && <span className="text-ink/50">· {t('admin.placement.attempt', { n: n(p.attempt_no) })}</span>}
          {p.recommended_level_label && <Badge tone="brand">{t('admin.placement.recommended', { level: p.recommended_level_label })}</Badge>}
        </>
      )}
      {r.final_level_label && (
        <Badge tone="info"><Icon name="check" className="size-3.5" />{t('admin.placement.final', { level: r.final_level_label })}{r.level_confirmed_by ? ` · ${r.level_confirmed_by}` : ''}</Badge>
      )}
    </div>
  )
}

/**
 * Accept one request: pick the circle (the matcher's recommendation is preselected) or take the lottery path,
 * and confirm the final level. The level starts at the placement recommendation, else what the family declared.
 */
function AcceptDialog({ request, onClose, onDone }: { request: RegistrationRequest; onClose: () => void; onDone: () => void }) {
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const circles = useQuery({ queryKey: ['registration-circles', request.id], queryFn: () => requestsApi.circles(request.id!) })
  const settings = useQuery({ queryKey: ['public-settings', locale], queryFn: publicApi.settings, staleTime: 5 * 60_000 })
  const [choice, setChoice] = useState<number | 'lottery' | null>(null)
  const [level, setLevel] = useState(request.recommended_level ?? request.memorization_level)
  const [error, setError] = useState<string | null>(null)
  // Accepting needs a circle of this request's package.
  const rows = (circles.data?.data ?? []).filter((c) => !c.package || c.package.id === request.package?.id)
  const selected = choice ?? circles.data?.recommended_id ?? null
  const run = useMutation({
    mutationFn: () => requestsApi.accept(request.id!, selected === 'lottery' ? { lottery: true, final_level: level } : { lesson_id: selected as number, final_level: level }),
    onSuccess: onDone,
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })
  const levels = settings.data?.memorization_levels ?? []

  return (
    <Modal wide title={t('admin.accept_title', { name: request.full_name })} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton disabled={selected === null || !level} loading={run.isPending} onClick={() => run.mutate()}>{t('admin.accept')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}

      <fieldset className="min-w-0 space-y-2">
        <legend className="mb-1 text-sm font-semibold text-ink">{t('admin.accept_circle')}</legend>
        {circles.isLoading ? <LoadingState /> : circles.isError ? <ErrorState onRetry={() => void circles.refetch()} /> : (
          <ul className="space-y-2">
            {rows.map((c) => (
              <li key={c.id}>
                <label className={`flex cursor-pointer flex-wrap items-center gap-3 rounded-xl border p-3 text-sm transition ${selected === c.id ? 'border-brand-600 bg-brand-50' : 'border-ink/12 hover:bg-ink/5'} ${c.fits ? '' : 'opacity-70'}`}>
                  <input type="radio" name="accept-circle" className="size-4 accent-brand-600" checked={selected === c.id} onChange={() => setChoice(c.id)} />
                  <span className="min-w-0 flex-1">
                    <span dir="auto" className="block font-medium text-ink">{c.name}{c.id === circles.data?.recommended_id && <Badge tone="brand" className="ms-2">{t('admin.recommended_circle')}</Badge>}</span>
                    <span className="block text-xs text-ink/55"><span dir="auto">{c.teacher ?? '—'}</span> · <span className="whitespace-nowrap tabular-nums" dir="ltr">{c.start_time}–{c.end_time}</span>{c.age_group ? <> · <span dir="auto">{c.age_group.name}</span></> : null}</span>
                    {!c.fits && c.reason_label && <span className="block text-xs text-gold-700">{c.reason_label}</span>}
                  </span>
                  <Badge tone={c.free_seats > 0 ? 'muted' : 'danger'}>{t('admin.free_seats', { n: n(c.free_seats) })}</Badge>
                </label>
              </li>
            ))}
            <li>
              <label className={`flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm transition ${selected === 'lottery' ? 'border-brand-600 bg-brand-50' : 'border-ink/12 hover:bg-ink/5'}`}>
                <input type="radio" name="accept-circle" className="size-4 accent-brand-600" checked={selected === 'lottery'} onChange={() => setChoice('lottery')} />
                <span className="min-w-0 flex-1"><span className="block font-medium text-ink">{t('admin.lottery_path')}</span><span className="block text-xs text-ink/55">{t('admin.lottery_path_hint')}</span></span>
              </label>
            </li>
          </ul>
        )}
        {!circles.isLoading && rows.length === 0 && <p className="text-sm text-gold-700">{t('admin.no_circles')}</p>}
      </fieldset>

      <div className="space-y-2 border-t border-ink/6 pt-4">
        <SelectField label={t('admin.final_level')} value={level} onChange={(e) => setLevel(e.target.value)} options={levels.length ? levels : [{ value: level, label: level }]} />
        <dl className="grid gap-2 text-sm sm:grid-cols-2">
          <div className="rounded-lg bg-page/70 px-3 py-2"><dt className="text-xs text-ink/50">{t('admin.recommended_level')}</dt><dd className="font-medium text-ink">{request.recommended_level_label ?? t('admin.no_placement')}{request.placement && <span className="ms-1 text-xs font-normal text-ink/55">(<bdi>{formatPercent(request.placement.percent, locale)}</bdi>)</span>}</dd></div>
          <div className="rounded-lg bg-page/70 px-3 py-2"><dt className="text-xs text-ink/50">{t('admin.declared_level')}</dt><dd className="font-medium text-ink">{request.memorization_level_label}</dd></div>
        </dl>
        <p className="text-xs text-ink/55">{t('admin.final_level_hint')}</p>
      </div>
    </Modal>
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
