import { useCallback, useEffect, useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { teachersApi, type TeacherFilters, type TeacherRow } from '../../api/teachers'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { Badge, ErrorState, FilterBar, SearchInput, SURFACE, TABLE_HEAD, TableWrap } from '../../components/ui'
import { EmptyState, PageBand, StarSpinner } from '../../components/ornaments'
import { formatNumber, formatPercent } from '../../lib/format'
import { initialOf } from './initial'
import MobileTeachers from './MobileTeachers'
import UserDialog from '../users/UserDialog'
import MobileToast from '../../components/mobile/Toast'

const FILTER_KEYS = ['search', 'gender', 'active', 'page'] as const

export default function TeachersList() {
  const { t, i18n } = useTranslation('teachers')
  const locale = i18n.language
  const { user, hasRole, can } = useAuth()
  const qc = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [toast, setToast] = useState<string | null>(null)
  const clearToast = useCallback(() => setToast(null), [])
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const filters: TeacherFilters = {
    search: params.get('search') ?? undefined,
    gender: params.get('gender') ?? undefined,
    active: (params.get('active') as '1' | '0' | null) ?? '1',
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

  useEffect(() => {
    const id = setTimeout(() => {
      if ((params.get('search') ?? '') !== search) setFilter('search', search.trim() || null)
    }, 350)
    return () => clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const query = useQuery({ queryKey: ['teachers', locale, filters], queryFn: () => teachersApi.list(filters), placeholderData: keepPreviousData })
  const bothTracks = hasRole('super_admin') || !user?.track || user.track === 'both'
  const hasFilters = ['search', 'gender', 'active'].some((k) => params.get(k))

  const clear = () => { setSearch(''); setParams(new URLSearchParams(), { replace: true }) }

  return (
    <>
    <MobileTeachers rows={query.data?.data} total={query.data?.meta.total} loading={query.isLoading} error={query.isError} onRetry={() => void query.refetch()}
      search={search} onSearch={setSearch} filters={filters} onFilter={setFilter} bothTracks={bothTracks} hasFilters={hasFilters} onClear={clear}
      canCreate={can('users.manage')} onCreate={() => setCreating(true)}
      pagination={query.data ? <Pagination page={query.data.meta.current_page} lastPage={query.data.meta.last_page} total={query.data.meta.total} onPage={(p) => setFilter('page', String(p))} /> : null} />
    {/* Mobile FAB: the users screen's new-account dialog (a teacher is a user with the teacher role). */}
    {creating && <UserDialog user={null} onClose={() => setCreating(false)} onSaved={(m) => { setCreating(false); setToast(m); void qc.invalidateQueries({ queryKey: ['teachers'] }) }} />}
    <MobileToast message={toast} onDone={clearToast} />
    <div className="hidden space-y-5 lg:block">
      <PageBand title={t('title')} subtitle={query.data ? t('subtitle', { n: formatNumber(query.data.meta.total, locale) }) : undefined} />

      <FilterBar layout="grid" label={t('filters.label')} className="sm:grid-cols-2 lg:grid-cols-4">
        <SearchInput id="teacher-search" className="sm:col-span-2" label={t('search')} value={search} onChange={(e) => setSearch(e.target.value)} />
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
          label={t('filters.status')}
          hideLabel
          value={filters.active}
          onChange={(e) => setFilter('active', e.target.value === '1' ? null : e.target.value)}
          options={[{ value: '1', label: t('filters.active') }, { value: '0', label: t('filters.inactive') }]}
        />
        {hasFilters && (
          <div className="flex sm:col-span-2 lg:col-span-4">
            <button
              type="button"
              onClick={() => { setSearch(''); setParams(new URLSearchParams(), { replace: true }) }}
              className="ms-auto text-sm font-medium text-brand-700 hover:underline"
            >
              {t('filters.clear')}
            </button>
          </div>
        )}
      </FilterBar>

      {query.isLoading ? (
        <div className="grid place-items-center py-20"><StarSpinner className="size-10 text-brand-600" /></div>
      ) : query.isError || !query.data ? (
        <ErrorState message={t('error')} onRetry={() => void query.refetch()} />
      ) : query.data.data.length === 0 ? (
        <div className={SURFACE}>
          <EmptyState icon="teachers" title={t('empty')} body={hasFilters ? t('empty_filtered') : t('empty_body')} />
        </div>
      ) : (
        <>
          <TeachersTable rows={query.data.data} locale={locale} />
          <Pagination page={query.data.meta.current_page} lastPage={query.data.meta.last_page} total={query.data.meta.total} onPage={(p) => setFilter('page', String(p))} />
        </>
      )}
    </div>
    </>
  )
}

function Score({ v, locale }: { v: number | null; locale: string }) {
  return v === null ? <span className="text-ink/40">—</span> : <>{formatNumber(v, locale, { maximumFractionDigits: 1 })}</>
}

function StatusBadge({ r }: { r: TeacherRow }) {
  const { t } = useTranslation('teachers')
  if (!r.is_active) return <Badge>{t('inactive')}</Badge>
  return r.active_circles > 0 ? <Badge tone="brand">{t('active')}</Badge> : <Badge tone="gold">{t('no_circles')}</Badge>
}

function TeachersTable({ rows, locale }: { rows: TeacherRow[]; locale: string }) {
  const { t } = useTranslation('teachers')
  const n = (v: number) => formatNumber(v, locale)
  const href = (id: number) => `/teachers?teacher=${id}`

  return (
    <>
      {/* ≥ 640px: table */}
      <TableWrap surface className="hidden sm:block">
        <table className="w-full min-w-[40rem] text-sm">
          <thead className={TABLE_HEAD}>
            <tr>
              <th scope="col" className="px-4 py-3 text-start font-medium">{t('columns.teacher')}</th>
              <th scope="col" className="px-4 py-3 text-end font-medium">{t('columns.circles')}</th>
              <th scope="col" className="px-4 py-3 text-end font-medium">{t('columns.students')}</th>
              <th scope="col" className="hidden px-4 py-3 text-end font-medium md:table-cell">{t('columns.taken_rate')}</th>
              <th scope="col" className="hidden px-4 py-3 text-end font-medium lg:table-cell">{t('columns.avg_evaluation')}</th>
              <th scope="col" className="px-4 py-3 text-start font-medium">{t('columns.status')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink/6">
            {rows.map((r) => (
              <tr key={r.id} className="group hover:bg-brand-50/40">
                <td className="px-4 py-3">
                  <Link to={href(r.id)} className="flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-brand-500">
                    <Avatar name={r.name} initial={initialOf(r.name)} src={r.photo_url} gender={r.gender} size="sm" />
                    <span className="min-w-0">
                      <span dir="auto" className="block truncate font-medium text-ink group-hover:text-brand-700">{r.name}</span>
                      <span dir="auto" className="block truncate text-xs text-ink/50">{r.specialization || t('no_specialization')}</span>
                    </span>
                  </Link>
                </td>
                <td className="px-4 py-3 text-end tabular-nums text-ink/80">{n(r.active_circles)}</td>
                <td className="px-4 py-3 text-end tabular-nums text-ink/80">{n(r.active_students)}</td>
                <td className="hidden px-4 py-3 text-end tabular-nums text-ink/80 md:table-cell">{formatPercent(r.month.taken_rate, locale)}</td>
                <td className="hidden px-4 py-3 text-end tabular-nums text-ink/80 lg:table-cell"><Score v={r.month.avg_evaluation} locale={locale} /></td>
                <td className="px-4 py-3"><StatusBadge r={r} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </TableWrap>

      {/* < 640px: cards */}
      <ul className="space-y-3 sm:hidden">
        {rows.map((r) => (
          <li key={r.id}>
            <Link to={href(r.id)} className={`${SURFACE} block p-4 active:bg-brand-50/50`}>
              <div className="flex items-center gap-3">
                <Avatar name={r.name} initial={initialOf(r.name)} src={r.photo_url} gender={r.gender} size="md" />
                <div className="min-w-0 flex-1">
                  <p dir="auto" className="truncate font-semibold text-ink">{r.name}</p>
                  <p dir="auto" className="truncate text-sm text-ink/55">{r.specialization || t('no_specialization')}</p>
                </div>
                <Icon name="chevron" className="size-4 text-ink/30 rtl:rotate-180" />
              </div>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-ink/6 pt-3 text-sm text-ink/70">
                <span className="tabular-nums">{t('card_line', { circles: n(r.active_circles), students: n(r.active_students) })}</span>
                <StatusBadge r={r} />
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </>
  )
}
