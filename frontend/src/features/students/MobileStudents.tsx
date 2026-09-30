import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { StudentFilters, StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import { Fab } from '../../components/mobile/ActionBars'
import { Chip, ChipRow, MAvatar, MCard, MEmpty, MListSkeleton, MSearch, MSelect, Pill, M_BTN_SECONDARY } from '../../components/mobile/atoms'
import { formatNumber } from '../../lib/format'
import AddToCircleDialog from './AddToCircleDialog'

type Key = 'search' | 'gender' | 'status' | 'lesson_id' | 'juz' | 'age' | 'due' | 'sort' | 'page'

/** Students list below lg (mobile-redesign-spec.md §6.8). Same URL filters and query as the desktop list. */
export default function MobileStudents({ rows, total, loading, filters, ageValue, ageOptions, circleOptions, bothTracks, search, onSearch, setFilter, onAllChips, onClear, pagination }: {
  rows: StudentSummary[] | undefined; total: number | undefined; loading: boolean; filters: StudentFilters; ageValue: string
  ageOptions: { value: string; label: string }[]; circleOptions: { value: string; label: string }[]; bothTracks: boolean
  search: string; onSearch: (v: string) => void; setFilter: (k: Key, v: string | null) => void; onAllChips: () => void; onClear: () => void; pagination: ReactNode
}) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const { can } = useAuth()
  const n = (v: number) => formatNumber(v, locale)
  const [sheet, setSheet] = useState<'sort' | 'filter' | null>(null)
  const [placing, setPlacing] = useState<StudentSummary | null>(null)
  const canPlace = can('enrollment.quick')
  const noneActive = !filters.lesson_id && !filters.due
  const sheetFilters = [filters.gender, filters.status, filters.juz, ageValue, filters.lesson_id && filters.lesson_id !== 'none' ? filters.lesson_id : ''].filter(Boolean).length
  const icon = 'relative inline-grid size-11 shrink-0 place-items-center rounded-ctl text-brand-900'

  return (
    <div className="space-y-3 lg:hidden">
      <div className="flex items-center gap-1">
        <h1 className="flex min-w-0 flex-1 items-baseline gap-1.5 text-brand-900">
          <span className="font-display text-[22px] leading-normal">{t('title')}</span>
          {total !== undefined && <span className="shrink-0 text-base tabular-nums text-ink/65">({n(total)})</span>}
        </h1>
        <button type="button" onClick={() => setSheet('sort')} aria-label={t('mobile.sort')} title={t('mobile.sort')} className={icon}><Icon name="sort" className="size-[22px]" /></button>
        <button type="button" onClick={() => setSheet('filter')} aria-label={t('mobile.filters')} title={t('mobile.filters')} className={icon}>
          <Icon name="filter" className="size-[22px]" />
          {sheetFilters > 0 && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>
      <MSearch label={t('search')} value={search} onChange={onSearch} />
      <ChipRow label={t('mobile.filters')}>
        <Chip active={noneActive} onClick={onAllChips}>{t('mobile.all')}</Chip>
        <Chip active={filters.lesson_id === 'none'} onClick={() => setFilter('lesson_id', filters.lesson_id === 'none' ? null : 'none')}>{t('filters.without_package')}</Chip>
        <Chip active={!!filters.due} onClick={() => setFilter('due', filters.due ? null : '1')}>{t('mobile.dues')}</Chip>
      </ChipRow>

      {loading ? <MListSkeleton rows={6} /> : !rows || rows.length === 0 ? (
        <MCard><MEmpty icon="students" text={t('empty')} action={sheetFilters > 0 || !noneActive || filters.search ? <button type="button" onClick={onClear} className={M_BTN_SECONDARY}>{t('mobile.clear')}</button> : undefined} /></MCard>
      ) : (
        <ul aria-label={t('title')} className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
          {rows.map((s) => (
            <li key={s.id} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
              <Link to={`/students/${s.id}`} className="flex min-w-0 flex-1 items-center gap-3">
                <MAvatar name={s.full_name} src={s.photo_url} />
                <span className="min-w-0 flex-1">
                  <span title={s.full_name} className="block truncate text-[15px] font-semibold text-ink"><bdi>{s.full_name}</bdi></span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                    {s.circle ? <bdi>{s.circle.name}</bdi> : <span className="font-semibold text-danger">{t('no_circle')}</span>}
                    {s.progress.juz ? <> · {t('mobile.juz', { n: n(s.progress.juz) })}</> : null}
                  </span>
                </span>
              </Link>
              {s.is_due ? <Pill tone="err">{t('due_badge')}</Pill> : !s.circle && canPlace ? (
                <button type="button" onClick={() => setPlacing(s)} className="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-ctl border border-ink/10 bg-white px-2.5 text-[13px] font-semibold text-brand-700">
                  <Icon name="enroll" className="size-4" />{t('no_circle_action')}
                </button>
              ) : null}
              <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
            </li>
          ))}
        </ul>
      )}
      {rows && total !== undefined && rows.length > 0 && <p className="text-center text-[13px] tabular-nums text-ink/65">{t('mobile.showing', { n: n(rows.length), total: n(total) })}</p>}
      {pagination}
      {can('enrollment.quick') && <Fab label={t('mobile.new')} to="/enrollment" />}

      <BottomSheet open={sheet === 'sort'} onClose={() => setSheet(null)} title={t('mobile.sort')}>
        <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white">
          {(['', 'memorized', 'age', 'student_no', 'newest'] as const).map((v) => {
            const on = (filters.sort ?? '') === v
            return (
              <li key={v || 'name'}>
                <button type="button" aria-pressed={on} onClick={() => { setFilter('sort', v || null); setSheet(null) }}
                  className={`flex min-h-[52px] w-full items-center justify-between gap-3 px-4 text-[15px] ${on ? 'font-semibold text-brand-700' : 'text-ink'}`}>
                  {t(`filters.sort_${v === '' ? 'name' : v === 'student_no' ? 'no' : v}`)}
                  {on && <Icon name="check" className="size-5" />}
                </button>
              </li>
            )
          })}
        </ul>
      </BottomSheet>

      <BottomSheet open={sheet === 'filter'} onClose={() => setSheet(null)} title={t('mobile.filters')}
        footer={<>
          <button type="button" onClick={() => { onClear(); setSheet(null) }} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.clear')}</button>
          <button type="button" onClick={() => setSheet(null)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>
        </>}>
        <div className="space-y-4">
          {bothTracks && <MSelect label={t('filters.gender')} value={filters.gender ?? ''} onChange={(v) => setFilter('gender', v)}
            options={[{ value: '', label: t('filters.all_tracks') }, { value: 'male', label: t('filters.boys') }, { value: 'female', label: t('filters.girls') }]} />}
          <MSelect label={t('filters.circle')} value={filters.lesson_id ?? ''} onChange={(v) => setFilter('lesson_id', v)} options={circleOptions} />
          <MSelect label={t('filters.juz')} value={filters.juz ?? ''} onChange={(v) => setFilter('juz', v)}
            options={[{ value: '', label: t('filters.all_juz') }, { value: '0', label: t('filters.not_started') }, ...Array.from({ length: 30 }, (_, i) => 30 - i).map((j) => ({ value: String(j), label: t('filters.juz_n', { n: n(j) }) }))]} />
          <MSelect label={t('filters.age')} value={ageValue} onChange={(v) => setFilter('age', v)} options={ageOptions} />
          <MSelect label={t('filters.status')} value={filters.status ?? ''} onChange={(v) => setFilter('status', v)}
            options={[{ value: '', label: t('filters.all_statuses') }, ...['active', 'inactive', 'suspended', 'graduated'].map((s) => ({ value: s, label: t(`status.${s}`) }))]} />
        </div>
      </BottomSheet>

      {placing && <AddToCircleDialog student={placing} onClose={() => setPlacing(null)} />}
    </div>
  )
}
