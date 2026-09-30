import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { Package } from '../../api/registration'
import Icon from '../../components/Icon'
import { Fab } from '../../components/mobile/ActionBars'
import { MCard, MEmpty, Pill, M_BTN_SECONDARY, M_CARD, type PillTone } from '../../components/mobile/atoms'
import { formatDate, formatMoney, formatNumber, formatTime } from '../../lib/format'
import { CardSkeletons } from './MobilePackages'

/** Package cards below lg (mobile-redesign-spec.md §6.20). The query, the dialog and the status update stay in PackagesHomePage. */

const GENDER_PILL: Record<string, PillTone> = { male: 'ok', female: 'warn', mixed: 'info' }
const PACKAGE_PILL: Record<string, PillTone> = { open: 'ok', draft: 'neutral', closed: 'neutral', archived: 'neutral' }

/** 44px-tall on/off switch (track 44×26); the status pill beside it carries the state as text. */
function Switch({ checked, label, onChange, disabled }: { checked: boolean; label: string; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} onClick={() => onChange(!checked)}
      className="inline-flex min-h-11 shrink-0 items-center rounded-full disabled:opacity-60">
      <span className={`relative inline-flex h-[26px] w-11 items-center rounded-full transition ${checked ? 'bg-brand-600' : 'bg-ink/20'}`}>
        <span className={`absolute top-[3px] size-5 rounded-full bg-white shadow-card transition-all ${checked ? 'start-[21px]' : 'start-[3px]'}`} />
      </span>
    </button>
  )
}

/** §6.20 package cards: name + open switch, price + period, seats pill, feature chips; closed cards at 75%. */
export function MobilePackageList({ packages, loading, error, canManage, onEdit, onCreate, onToggle, toggling, onRequests }: {
  packages: Package[] | undefined; loading: boolean; error: ReactNode; canManage: boolean
  onEdit: (p: Package) => void; onCreate: () => void; onToggle: (p: Package, open: boolean) => void; toggling: number | null
  onRequests: (p: Package, status: 'pending' | 'waitlist') => void
}) {
  const { t, i18n } = useTranslation('registration')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const sep = locale === 'ar' ? '، ' : ', '

  return (
    <div className="space-y-3 lg:hidden">
      {loading ? <CardSkeletons /> : error ? error : !packages || packages.length === 0 ? (
        <MCard><MEmpty icon="packages" text={t('admin.empty_packages')} action={canManage ? <button type="button" onClick={onCreate} className={M_BTN_SECONDARY}><Icon name="plus" className="size-4" />{t('admin.new_package')}</button> : undefined} /></MCard>
      ) : (
        <ul aria-label={t('admin.tabs.packages')} className="space-y-3">
          {packages.map((p) => {
            const open = p.status === 'open'
            return (
              <li key={p.id} className={`${M_CARD} space-y-3 p-4 ${open ? '' : 'opacity-75'}`}>
                <div className="flex items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[15px] font-semibold text-ink"><bdi>{p.name}</bdi></p>
                    <p className="mt-1 flex flex-wrap items-baseline gap-x-1.5">
                      <span className="text-[22px] font-semibold leading-7 tabular-nums text-ink">{p.price_fils > 0 ? formatMoney(p.price_fils, locale) : t('public.free')}</span>
                    </p>
                    <p className="mt-0.5 text-[13px] text-ink/65">{p.days.map((d) => tl(`days.${d}`)).join(sep)} · <span className="tabular-nums">{formatDate(p.start_date, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span></p>
                  </div>
                  <div className="flex shrink-0 items-center gap-1.5">
                    <Pill tone={PACKAGE_PILL[p.status] ?? 'neutral'}>{t(`form.statuses.${p.status}`, { defaultValue: p.status_label })}</Pill>
                    {canManage && (p.status === 'open' || p.status === 'closed' || p.status === 'draft') && (
                      <Switch checked={open} disabled={toggling === p.id} label={t('mobile.package_open', { name: p.name })} onChange={(v) => onToggle(p, v)} />
                    )}
                  </div>
                </div>

                <div className="flex flex-wrap gap-1.5">
                  <Pill tone={p.is_full ? 'warn' : 'ok'}>{p.is_full ? t('admin.full_error') : t('admin.seats', { taken: n(p.seats_taken), seats: n(p.seats) })}</Pill>
                  <Pill tone={GENDER_PILL[p.gender] ?? 'neutral'}>{t(`gender.${p.gender}`)}</Pill>
                  <Pill>{t('public.ages', { min: n(p.min_age), max: n(p.max_age) })}</Pill>
                  <Pill>{formatTime(p.start_time, locale)}–{formatTime(p.end_time, locale)}</Pill>
                </div>

                {((p.pending_count ?? 0) > 0 || (p.waitlist_count ?? 0) > 0 || canManage) && (
                  <div className="flex flex-wrap items-center gap-2 border-t border-ink/10 pt-3">
                    {(p.pending_count ?? 0) > 0 && (
                      <button type="button" onClick={() => onRequests(p, 'pending')} className="inline-flex min-h-11 items-center"><Pill tone="warn">{t('admin.pending', { n: n(p.pending_count ?? 0) })}</Pill></button>
                    )}
                    {(p.waitlist_count ?? 0) > 0 && (
                      <button type="button" onClick={() => onRequests(p, 'waitlist')} className="inline-flex min-h-11 items-center"><Pill tone="info">{t('admin.waitlist', { n: n(p.waitlist_count ?? 0) })}</Pill></button>
                    )}
                    {canManage && <button type="button" onClick={() => onEdit(p)} className={`${M_BTN_SECONDARY} ms-auto`}><Icon name="edit" className="size-4" />{tl('detail.edit')}</button>}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {canManage && <Fab label={t('admin.new_package')} onClick={onCreate} />}
    </div>
  )
}

