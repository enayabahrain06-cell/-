import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { TeacherRow } from '../../api/teachers'
import { Fab } from '../../components/mobile/ActionBars'
import { Chip, ChipRow, MAvatar, MCard, MEmpty, MSearch, Pill, Skeleton, M_BTN_SECONDARY, M_CARD } from '../../components/mobile/atoms'
import Icon from '../../components/Icon'
import { formatNumber, formatPercent } from '../../lib/format'
import { initialOf } from './initial'

/** Teachers list below lg (mobile-redesign-spec.md §6.10). Query, debounced search and URL filters stay in TeachersList. */
export default function MobileTeachers({ rows, total, loading, error, onRetry, search, onSearch, filters, onFilter, bothTracks, hasFilters, onClear, canCreate, onCreate, pagination }: {
  rows: TeacherRow[] | undefined; total: number | undefined; loading: boolean; error: boolean; onRetry: () => void
  search: string; onSearch: (v: string) => void
  filters: { gender?: string; active?: '1' | '0' }; onFilter: (k: 'gender' | 'active', v: string | null) => void
  bothTracks: boolean; hasFilters: boolean; onClear: () => void
  canCreate: boolean; onCreate: () => void; pagination: ReactNode
}) {
  const { t, i18n } = useTranslation('teachers')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-3 lg:hidden">
      <MSearch label={t('search')} value={search} onChange={onSearch} />
      <ChipRow label={t('filters.label')}>
        {bothTracks && <>
          <Chip active={!filters.gender} onClick={() => onFilter('gender', null)}>{t('filters.all_tracks')}{total !== undefined && !filters.gender && <span className="tabular-nums">{n(total)}</span>}</Chip>
          <Chip active={filters.gender === 'male'} onClick={() => onFilter('gender', 'male')}>{t('filters.boys')}</Chip>
          <Chip active={filters.gender === 'female'} onClick={() => onFilter('gender', 'female')}>{t('filters.girls')}</Chip>
        </>}
        <Chip active={filters.active !== '0'} onClick={() => onFilter('active', null)}>{t('filters.active')}</Chip>
        <Chip active={filters.active === '0'} onClick={() => onFilter('active', '0')}>{t('filters.inactive')}</Chip>
      </ChipRow>

      {loading ? <TeachersSkeleton /> : error || !rows ? (
        <MCard><MEmpty icon="alert" text={t('error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('mobile.retry')}</button>} /></MCard>
      ) : rows.length === 0 ? (
        <MCard><MEmpty icon="teachers" text={hasFilters ? t('empty_filtered') : t('empty')}
          action={hasFilters ? <button type="button" onClick={onClear} className={M_BTN_SECONDARY}>{t('filters.clear')}</button> : undefined} /></MCard>
      ) : (
        <ul className="space-y-3">
          {rows.map((r) => (
            <li key={r.id}>
              <Link to={`/teachers?teacher=${r.id}`} className={`${M_CARD} block p-4 active:bg-brand-50/60 ${r.is_active ? '' : 'opacity-75'}`}>
                <div className="flex items-center gap-3">
                  <MAvatar name={initialOf(r.name)} src={r.photo_url} size={44} />
                  <div className="min-w-0 flex-1">
                    <p title={r.name} className="truncate text-[15px] font-semibold text-ink"><bdi>{r.name}</bdi></p>
                    <p className="mt-0.5 flex min-w-0 items-center gap-2 text-[13px] text-ink/65">
                      <span className="truncate tabular-nums">{t('card_line', { circles: n(r.active_circles), students: n(r.active_students) })}</span>
                      {!r.is_active ? <Pill>{t('inactive')}</Pill> : r.active_circles > 0 ? <Pill tone="ok">{t('active')}</Pill> : <Pill tone="warn">{t('no_circles')}</Pill>}
                    </p>
                  </div>
                  <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
                </div>
                {/* This month: students' attendance, evaluations recorded, average score. */}
                <dl className="mt-3 grid grid-cols-3 gap-2 border-t border-ink/10 pt-3 text-center">
                  <Stat label={t('mobile.attendance')} value={formatPercent(r.month.attendance_rate, locale)} />
                  <Stat label={t('mobile.evaluations')} value={n(r.month.evaluations)} />
                  <Stat label={t('mobile.avg')} value={r.month.avg_evaluation === null ? '—' : formatNumber(r.month.avg_evaluation, locale, { maximumFractionDigits: 1 })} />
                </dl>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {pagination}
      {canCreate && <Fab label={t('mobile.new_teacher')} onClick={onCreate} />}
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-xs text-ink/65">{label}</dt>
      <dd className="mt-0.5 truncate text-base font-semibold tabular-nums text-ink">{value}</dd>
    </div>
  )
}

function TeachersSkeleton() {
  return (
    <ul aria-hidden className="space-y-3">
      {[0, 1, 2, 3].map((k) => (
        <li key={k} className={`${M_CARD} p-4`}>
          <div className="flex items-center gap-3"><Skeleton className="size-11 rounded-full" /><span className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/2" /></span></div>
          <div className="mt-3 grid grid-cols-3 gap-2 border-t border-ink/10 pt-3">{[0, 1, 2].map((j) => <Skeleton key={j} className="h-9" />)}</div>
        </li>
      ))}
    </ul>
  )
}
