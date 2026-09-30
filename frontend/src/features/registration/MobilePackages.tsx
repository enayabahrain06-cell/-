import { useCallback, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { Package, RegistrationRequest } from '../../api/registration'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import MobileToast from '../../components/mobile/Toast'
import { Chip, ChipRow, MAvatar, MCard, MEmpty, MSearch, MSegmented, MSelect, Pill, Skeleton, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatNumber, formatPercent } from '../../lib/format'

/**
 * Packages and registration requests below lg (mobile-redesign-spec.md §6.19, §6.20). The queries, mutations and
 * dialogs stay in PackagesHomePage; these components only lay them out for phones.
 */

const REQUEST_PILL: Record<string, PillTone> = { pending: 'warn', pending_lottery: 'warn', waitlist: 'info', accepted: 'ok', enrolled: 'ok', rejected: 'err' }
const REQUEST_STATUSES = ['pending', 'waitlist', 'pending_lottery', 'enrolled', 'rejected', 'all'] as const

/** Page header (title + open the public form) and the tab switch. */
export function MobilePackagesHeader({ tab, tabs, onTab }: { tab: 'packages' | 'requests'; tabs: { value: 'packages' | 'requests'; label: string }[]; onTab: (v: 'packages' | 'requests') => void }) {
  const { t } = useTranslation('registration')
  return (
    <>
      <MobilePage title={t('admin.title')} back="/" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('admin.title') }]}
        actions={<HeaderAction icon="share" label={t('admin.open_public')} onClick={() => window.open('/register', '_blank', 'noreferrer')} />} />
      {tabs.length > 1 && <div className="lg:hidden"><MSegmented label={t('admin.title')} value={tab} onChange={onTab} options={tabs} /></div>}
    </>
  )
}

/** Card-shaped skeletons while a list loads. */
export function CardSkeletons({ rows = 3 }: { rows?: number }) {
  return (
    <ul aria-hidden className="space-y-3">
      {Array.from({ length: rows }, (_, i) => (
        <li key={i} className={`${M_CARD} space-y-3 p-4`}>
          <div className="flex items-center gap-3"><Skeleton className="size-10 rounded-full" /><span className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/2" /></span></div>
          <Skeleton className="h-11 w-full" />
        </li>
      ))}
    </ul>
  )
}

/** Whole days since a date (Bahrain calendar days are close enough for "how old is this request"). */
function daysSince(iso: string) {
  return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000))
}

/** §6.19 request cards: status chips, avatar + name, facts, age-of-request pill, placement line, actions. */
export function MobileRequests({ rows, total, loading, error, status, filters, packages, manage, decided, onSet, onAccept, onWaitlist, onReject, onCard, onBulk, notice, pagination }: {
  rows: RegistrationRequest[] | undefined; total: number | undefined; loading: boolean; error: ReactNode
  status: string; filters: { package_id?: string; search?: string }; packages: Package[]; manage: boolean; decided: string[]
  onSet: (k: string, v: string) => void
  onAccept: (r: RegistrationRequest) => void; onWaitlist: (r: RegistrationRequest) => void; onReject: (r: RegistrationRequest) => void; onCard: (r: RegistrationRequest) => void; onBulk: () => void
  notice: { tone: 'success' | 'error'; text: string } | null; pagination: ReactNode
}) {
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [search, setSearch] = useState(filters.search ?? '')
  const [sheet, setSheet] = useState(false)
  const [dismissed, setDismissed] = useState<object | null>(null)
  const dismiss = useCallback(() => setDismissed(notice), [notice])

  return (
    <div className="space-y-3 lg:hidden">
      <div className="flex gap-2">
        <form className="min-w-0 flex-1" onSubmit={(e) => { e.preventDefault(); onSet('search', search.trim()) }}>
          <MSearch label={t('admin.search')} value={search} onChange={(v) => { setSearch(v); if (!v) onSet('search', '') }} />
        </form>
        <button type="button" onClick={() => setSheet(true)} aria-label={t('mobile.filters')} title={t('mobile.filters')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="filter" className="size-5" />
          {filters.package_id && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>
      <ChipRow label={t('admin.tabs.requests')}>
        {REQUEST_STATUSES.map((s) => (
          <Chip key={s} active={status === s} onClick={() => onSet('status', s)}>
            {t(`admin.status_filter.${s}`)}{status === s && total !== undefined && <> {n(total)}</>}
          </Chip>
        ))}
      </ChipRow>

      {loading ? <CardSkeletons /> : error ? error : !rows || rows.length === 0 ? (
        <MCard><MEmpty icon="packages" text={t('admin.empty_requests')} action={status !== 'all' || filters.package_id || filters.search
          ? <button type="button" onClick={() => { setSearch(''); onSet('status', 'all') }} className={M_BTN_SECONDARY}>{t('mobile.show_all')}</button> : undefined} /></MCard>
      ) : (
        <ul aria-label={t('admin.tabs.requests')} className="space-y-3">
          {rows.map((r) => {
            const days = daysSince(r.created_at)
            const open = manage && !decided.includes(r.status)
            return (
              <li key={r.request_no} className={`${M_CARD} space-y-3 p-4`}>
                <div className="flex items-start gap-3">
                  <MAvatar name={r.full_name} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[15px] font-semibold text-ink"><bdi>{r.full_name}</bdi></p>
                    <p className="mt-0.5 text-[13px] text-ink/65">
                      <span className="tabular-nums">{t('mobile.age', { n: n(r.age_at_start) })}</span> · {t(`gender.${r.gender}`)} · {r.memorization_level_label}{r.guardian_name && <> · <bdi>{r.guardian_name}</bdi></>}
                    </p>
                    <p className="text-[13px] text-ink/65"><span dir="ltr" className="font-mono tabular-nums">{r.request_no}</span>{r.guardian_phone && <> · <span dir="ltr" className="tabular-nums">{r.guardian_phone}</span></>}</p>
                  </div>
                  <div className="shrink-0">
                    <Pill tone={REQUEST_PILL[r.status] ?? 'neutral'}>{t(`status.${r.status}`)}{r.status === 'waitlist' && r.waitlist_position ? ` ${t('admin.position', { n: n(r.waitlist_position) })}` : ''}</Pill>
                  </div>
                </div>

                <div className="space-y-1 rounded-ctl bg-page px-3 py-2 text-[13px] text-ink/75">
                  <p className="flex items-center gap-1.5"><Icon name="packages" className="size-4 shrink-0 text-ink/65" /><bdi className="min-w-0 truncate">{r.package?.name}</bdi><span className="ms-auto shrink-0"><Pill tone={r.status === 'pending' && days > 7 ? 'warn' : 'neutral'}>{days === 0 ? t('mobile.today') : t('mobile.days_ago', { count: days, n: n(days) })}</Pill></span></p>
                  {r.placement && (
                    <p className="flex flex-wrap items-center gap-x-1.5"><Icon name="exams" className="size-4 shrink-0 text-ink/65" />
                      <span className="tabular-nums">{t('admin.placement.score', { percent: formatPercent(r.placement.percent, locale) })}</span>
                      {r.placement.recommended_level_label && <span className="font-semibold text-brand-700">· {t('admin.placement.recommended', { level: r.placement.recommended_level_label })}</span>}
                    </p>
                  )}
                  {r.final_level_label && <p className="text-info">{t('admin.placement.final', { level: r.final_level_label })}</p>}
                  {!r.has_photo && open && <p className="text-ink/65">{t('admin.no_photo')}</p>}
                </div>
                {r.reason && <p dir="auto" className="rounded-ctl bg-danger/10 px-3 py-2 text-[13px] text-danger">{r.reason}</p>}

                {(open || r.student) && (
                  <div className="flex items-center gap-2">
                    {r.student && <Link to={`/students/${r.student.id}`} className={`${M_BTN_SECONDARY} flex-1`}>{t('admin.student_link')}</Link>}
                    {open && (
                      <>
                        {/* Tinted, not bg-brand-700: the screen keeps one primary (spec §5), and a list has many cards. */}
                        <button type="button" onClick={() => onAccept(r)} title={t('mobile.accept')} className="inline-flex min-h-11 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-ctl border border-brand-700/25 bg-brand-50 px-2 text-[15px] font-semibold text-brand-700">
                          <Icon name="check" className="size-4 shrink-0 max-[359px]:hidden" /><span className="truncate">{t('admin.accept')}</span>
                        </button>
                        {r.status !== 'waitlist' && (
                          <button type="button" onClick={() => onWaitlist(r)} aria-label={t('admin.waitlist_action')} title={t('admin.waitlist_action')}
                            className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700"><Icon name="clock" className="size-5" /></button>
                        )}
                        <button type="button" onClick={() => onCard(r)} aria-label={t('read', { ns: 'idCard' })} title={t('read', { ns: 'idCard' })}
                          className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700"><Icon name="students" className="size-5" /></button>
                        {r.status !== 'rejected' && (
                          <button type="button" onClick={() => onReject(r)} aria-label={t('admin.reject')} title={t('admin.reject')}
                            className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-danger/25 bg-white text-danger"><Icon name="ban" className="size-5" /></button>
                        )}
                      </>
                    )}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {pagination}

      {/* Its own dismissal: the desktop notice (same state) must not vanish after the toast's 4 seconds. */}
      <MobileToast message={notice && notice !== dismissed ? notice.text : null} tone={notice?.tone === 'error' ? 'error' : 'ok'} onDone={dismiss} />

      <BottomSheet open={sheet} onClose={() => setSheet(false)} title={t('mobile.filters')}
        footer={<>
          <button type="button" onClick={() => { onSet('package_id', ''); setSheet(false) }} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.clear')}</button>
          <button type="button" onClick={() => setSheet(false)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>
        </>}>
        <div className="space-y-4">
          <MSelect label={t('track.package')} value={filters.package_id ?? ''} onChange={(v) => onSet('package_id', v)}
            options={[{ value: '', label: t('admin.all_packages') }, ...packages.map((p) => ({ value: String(p.id), label: p.name }))]} />
          {manage && (
            <button type="button" onClick={() => { setSheet(false); onBulk() }} className={`${M_BTN_SECONDARY} w-full`}>
              <Icon name="check" className="size-4" />{t('admin.bulk_accept')}
            </button>
          )}
        </div>
      </BottomSheet>
    </div>
  )
}
