import { useEffect, useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { lessonsApi, studentsApi, type StudentFilters, type StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import { Badge, FilterBar, SearchInput, TableWrap, TABLE_HEAD, SURFACE } from '../../components/ui'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { EmptyState, PageBand, StarSpinner } from '../../components/ornaments'
import { ageFrom, formatMoney, formatNumber } from '../../lib/format'

const FILTER_KEYS = ['search', 'gender', 'status', 'lesson_id', 'juz', 'age', 'due', 'sort', 'page'] as const

/** Age bands for the filter. The URL keeps "min-max" (an open end stays empty); the API gets age_min / age_max. */
const AGE_BANDS: { value: string; min?: number; max?: number }[] = [
  { value: '-6', max: 6 },
  { value: '7-9', min: 7, max: 9 },
  { value: '10-12', min: 10, max: 12 },
  { value: '13-15', min: 13, max: 15 },
  { value: '16-', min: 16 },
]

export default function StudentsListPage() {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const { user, hasRole } = useAuth()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const band = AGE_BANDS.find((b) => b.value === params.get('age'))
  const filters: StudentFilters = {
    search: params.get('search') ?? undefined,
    gender: params.get('gender') ?? undefined,
    status: params.get('status') ?? undefined,
    lesson_id: params.get('lesson_id') ?? undefined,
    juz: params.get('juz') ?? undefined,
    age_min: band?.min?.toString(),
    age_max: band?.max?.toString(),
    due: params.get('due') === '1',
    sort: params.get('sort') ?? undefined,
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const setFilter = (key: (typeof FILTER_KEYS)[number], value: string | null) => {
    const next = new URLSearchParams(params)
    if (value === null || value === '') next.delete(key)
    else next.set(key, value)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }

  // Debounce the search box into the URL.
  useEffect(() => {
    const id = setTimeout(() => {
      if ((params.get('search') ?? '') !== search) setFilter('search', search.trim() || null)
    }, 350)
    return () => clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const query = useQuery({ queryKey: ['students', locale, filters], queryFn: () => studentsApi.list(filters), placeholderData: keepPreviousData })
  const lessons = useQuery({ queryKey: ['lesson-options'], queryFn: lessonsApi.options, staleTime: 5 * 60_000 })

  const bothTracks = hasRole('super_admin') || !user?.track || user.track === 'both'
  const hasFilters = FILTER_KEYS.some((k) => k !== 'page' && k !== 'sort' && params.get(k))
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={query.data ? t('subtitle', { n: n(query.data.meta.total) }) : undefined} />

      {/* Filters: one row above the list */}
      <FilterBar layout="grid" label={t('filters.clear')} className="sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <SearchInput id="student-search" className="sm:col-span-2" label={t('search')} value={search} onChange={(e) => setSearch(e.target.value)} />
        {bothTracks && (
          <SelectField
            label={t('filters.gender')}
            hideLabel
            value={filters.gender ?? ''}
            onChange={(e) => setFilter('gender', e.target.value)}
            options={[{ value: '', label: t('filters.all_tracks') }, { value: 'male', label: t('filters.boys') }, { value: 'female', label: t('filters.girls') }]}
          />
        )}
        <SelectField
          label={t('filters.circle')}
          hideLabel
          value={filters.lesson_id ?? ''}
          onChange={(e) => setFilter('lesson_id', e.target.value)}
          options={[{ value: '', label: t('filters.all_circles') }, { value: 'none', label: t('filters.without_package') }, ...(lessons.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))]}
        />
        <SelectField
          label={t('filters.juz')}
          hideLabel
          value={filters.juz ?? ''}
          onChange={(e) => setFilter('juz', e.target.value)}
          options={[
            { value: '', label: t('filters.all_juz') },
            { value: '0', label: t('filters.not_started') },
            ...Array.from({ length: 30 }, (_, i) => 30 - i).map((j) => ({ value: String(j), label: t('filters.juz_n', { n: n(j) }) })),
          ]}
        />
        <SelectField
          label={t('filters.age')}
          hideLabel
          value={band?.value ?? ''}
          onChange={(e) => setFilter('age', e.target.value)}
          options={[
            { value: '', label: t('filters.all_ages') },
            ...AGE_BANDS.map((b) => ({
              value: b.value,
              label: b.min === undefined ? t('filters.age_under', { n: n(b.max! + 1) }) : b.max === undefined ? t('filters.age_over', { n: n(b.min) }) : t('filters.age_range', { from: n(b.min), to: n(b.max) }),
            })),
          ]}
        />
        <div className="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-3 xl:col-span-6">
          <SelectField
            label={t('filters.status')}
            hideLabel
            className="w-full sm:w-44"
            value={filters.status ?? ''}
            onChange={(e) => setFilter('status', e.target.value)}
            options={[{ value: '', label: t('filters.all_statuses') }, ...['active', 'inactive', 'suspended', 'graduated'].map((s) => ({ value: s, label: t(`status.${s}`) }))]}
          />
          <SelectField
            label={t('filters.sort')}
            hideLabel
            className="w-full sm:w-52"
            value={filters.sort ?? ''}
            onChange={(e) => setFilter('sort', e.target.value)}
            options={[
              { value: '', label: `${t('filters.sort')}: ${t('filters.sort_name')}` },
              { value: 'memorized', label: `${t('filters.sort')}: ${t('filters.sort_memorized')}` },
              { value: 'age', label: `${t('filters.sort')}: ${t('filters.sort_age')}` },
              { value: 'student_no', label: `${t('filters.sort')}: ${t('filters.sort_no')}` },
              { value: 'newest', label: `${t('filters.sort')}: ${t('filters.sort_newest')}` },
            ]}
          />
          <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-ink/75">
            <input type="checkbox" className="size-4 rounded accent-brand-600" checked={filters.due} onChange={(e) => setFilter('due', e.target.checked ? '1' : null)} />
            {t('filters.due')}
          </label>
          {hasFilters && (
            <button
              type="button"
              onClick={() => {
                setSearch('')
                setParams(new URLSearchParams(params.get('sort') ? { sort: params.get('sort')! } : {}), { replace: true })
              }}
              className="ms-auto text-sm font-medium text-brand-700 hover:underline"
            >
              {t('filters.clear')}
            </button>
          )}
        </div>
      </FilterBar>

      {query.isLoading ? (
        <div className="grid place-items-center py-20"><StarSpinner className="size-10 text-brand-600" /></div>
      ) : query.isError || !query.data ? (
        <div role="alert" className="rounded-2xl border border-danger/25 bg-danger/5 p-6 text-center text-danger">
          <p>{t('error')}</p>
          <button type="button" onClick={() => void query.refetch()} className="mt-3 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-ink shadow-sm">{t('retry')}</button>
        </div>
      ) : query.data.data.length === 0 ? (
        <div className={SURFACE}>
          <EmptyState icon="students" title={t('empty')} body={t('empty_body')} />
        </div>
      ) : (
        <>
          <StudentsTable rows={query.data.data} locale={locale} />
          <Pagination page={query.data.meta.current_page} lastPage={query.data.meta.last_page} total={query.data.meta.total} onPage={(p) => setFilter('page', String(p))} />
        </>
      )}
    </div>
  )
}

function Position({ s, locale }: { s: StudentSummary; locale: string }) {
  const { t } = useTranslation('students')
  if (!s.progress.surah) return <span className="text-ink/45">{t('not_started')}</span>
  return (
    <span className="whitespace-nowrap">
      {t('position_short', { surah: s.progress.surah_name, ayah: formatNumber(s.progress.ayah ?? 0, locale), juz: formatNumber(s.progress.juz ?? 0, locale) })}
    </span>
  )
}

function Age({ s, locale }: { s: StudentSummary; locale: string }) {
  const { t } = useTranslation('students')
  const age = ageFrom(s.birth_date)
  return age === null ? <span className="text-ink/45">—</span> : <>{t('profile.age', { age: formatNumber(age, locale) })}</>
}

function Balance({ s, locale }: { s: StudentSummary; locale: string }) {
  const { t } = useTranslation('students')
  const fils = s.balance_fils ?? 0
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <span className={`tabular-nums ${fils < 0 ? 'font-semibold text-danger' : 'text-ink/75'}`}>{formatMoney(fils, locale)}</span>
      {s.is_due && <span className="rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger">{t('due_badge')}</span>}
    </span>
  )
}

function StudentsTable({ rows, locale }: { rows: StudentSummary[]; locale: string }) {
  const { t } = useTranslation('students')

  return (
    <>
      {/* ≥ 640px: table */}
      <TableWrap surface className="hidden sm:block">
        <table className="w-full min-w-[34rem] text-sm">
          <thead className={TABLE_HEAD}>
            <tr>
              <th scope="col" className="px-4 py-3 text-start font-medium">{t('columns.student')}</th>
              <th scope="col" className="px-4 py-3 text-start font-medium">{t('columns.age')}</th>
              <th scope="col" className="px-4 py-3 text-start font-medium">{t('columns.circle')}</th>
              <th scope="col" className="hidden px-4 py-3 text-start font-medium md:table-cell">{t('columns.position')}</th>
              <th scope="col" className="hidden px-4 py-3 text-end font-medium lg:table-cell">{t('columns.memorized')}</th>
              <th scope="col" className="px-4 py-3 text-start font-medium">{t('columns.balance')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink/6">
            {rows.map((s) => (
              <tr key={s.id} className="group hover:bg-brand-50/40">
                <td className="px-4 py-3">
                  <Link to={`/students/${s.id}`} className="flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-brand-500">
                    <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
                    <span className="min-w-0">
                      <span dir="auto" className="block truncate font-medium text-ink group-hover:text-brand-700">{s.full_name}</span>
                      <span className="block text-xs tabular-nums text-ink/50">{s.student_no}{s.status !== 'active' && <> · {t(`status.${s.status}`)}</>}</span>
                    </span>
                  </Link>
                </td>
                <td className="whitespace-nowrap px-4 py-3 tabular-nums text-ink/80"><Age s={s} locale={locale} /></td>
                <td className="px-4 py-3">
                  {s.circle ? (
                    <>
                      <span dir="auto" className="block truncate text-ink/85">{s.circle.name}</span>
                      <span dir="auto" className="block truncate text-xs text-ink/50">{s.circle.teacher}</span>
                    </>
                  ) : (
                    <span className="text-ink/45">{t('no_circle')}</span>
                  )}
                </td>
                <td className="hidden px-4 py-3 text-ink/80 md:table-cell"><Position s={s} locale={locale} /></td>
                <td className="hidden px-4 py-3 text-end tabular-nums text-ink/80 lg:table-cell">{t('ayahs', { n: formatNumber(s.progress.memorized_ayahs, locale) })}</td>
                <td className="px-4 py-3"><Balance s={s} locale={locale} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </TableWrap>

      {/* < 640px: cards */}
      <ul className="space-y-3 sm:hidden">
        {rows.map((s) => (
          <li key={s.id}>
            <Link to={`/students/${s.id}`} className={`${SURFACE} block p-4 active:bg-brand-50/50`}>
              <div className="flex items-center gap-3">
                <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="md" />
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-2">
                    <span dir="auto" className="truncate font-semibold text-ink">{s.full_name}</span>
                    {ageFrom(s.birth_date) !== null && <Badge className="shrink-0 tabular-nums"><Age s={s} locale={locale} /></Badge>}
                  </p>
                  <p dir="auto" className="truncate text-sm text-ink/55">{s.circle?.name ?? t('no_circle')}</p>
                </div>
                <Icon name="chevron" className="size-4 text-ink/30 rtl:rotate-180" />
              </div>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-ink/6 pt-3 text-sm">
                <span className="text-ink/75"><Position s={s} locale={locale} /></span>
                <Balance s={s} locale={locale} />
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </>
  )
}
