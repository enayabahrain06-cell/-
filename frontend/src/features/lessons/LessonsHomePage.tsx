import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { bookingsApi, hallsApi, lessonsApi, type Conflict, type Hall } from '../../api/lessons'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { PageBand } from '../../components/ornaments'
import { Badge, buttonClass, ErrorState, FilterBar, LoadingState, Notice, PrimaryButton, SecondaryButton, SearchInput, Segmented, type Tone, SURFACE, EmptyCard } from '../../components/ui'
import { formatDate, formatNumber, formatTime } from '../../lib/format'
import { BookingDialog, HallFormDialog } from './HallDialogs'
import LessonFormDialog from './LessonFormDialog'

export const GENDER_TONE: Record<string, Tone> = { male: 'brand', female: 'gold', mixed: 'info', shared: 'muted' }

type Tab = 'circles' | 'halls' | 'bookings'

export default function LessonsHomePage() {
  const { t } = useTranslation('lessons')
  const [params, setParams] = useSearchParams()
  const tab = (['circles', 'halls', 'bookings'] as const).includes(params.get('tab') as Tab) ? (params.get('tab') as Tab) : 'circles'

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')} />
      <Segmented name="lessons-tab" label={t('title')} value={tab}
        options={(['circles', 'halls', 'bookings'] as const).map((k) => ({ value: k, label: t(`tabs.${k}`) }))}
        onChange={(v) => setParams({ tab: v }, { replace: true })} />
      {tab === 'circles' && <Circles />}
      {tab === 'halls' && <Halls />}
      {tab === 'bookings' && <Bookings />}
    </div>
  )
}

function Circles() {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const { can, hasRole, user } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [dialog, setDialog] = useState(false)
  const [conflicts, setConflicts] = useState<Conflict[] | null>(null)
  const filters = { gender: params.get('gender') ?? undefined, status: params.get('status') ?? undefined, search: params.get('search') ?? undefined, page: Number(params.get('page') ?? 1), per_page: 24 }
  const q = useQuery({ queryKey: ['lessons', filters], queryFn: () => lessonsApi.list(filters), placeholderData: keepPreviousData })
  const set = (k: string, v: string) => { const n = new URLSearchParams(params); if (v) n.set(k, v); else n.delete(k); if (k !== 'page') n.delete('page'); setParams(n, { replace: true }) }
  const both = hasRole('super_admin') || !user?.track || user.track === 'both'
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-4">
      <FilterBar>
        <SearchInput id="lesson-search" className="sm:min-w-48 sm:flex-1" label={t('filters.search')} defaultValue={filters.search} onKeyDown={(e) => e.key === 'Enter' && set('search', (e.target as HTMLInputElement).value)}
          onBlur={(e) => set('search', e.target.value)} />
        {both && <SelectField label={t('filters.all_tracks')} hideLabel className="sm:w-44" value={filters.gender ?? ''} onChange={(e) => set('gender', e.target.value)}
          options={[{ value: '', label: t('filters.all_tracks') }, ...(['male', 'female', 'mixed'] as const).map((g) => ({ value: g, label: t(`gender.${g}`) }))]} />}
        <SelectField label={t('filters.all_statuses')} hideLabel className="sm:w-40" value={filters.status ?? ''} onChange={(e) => set('status', e.target.value)}
          options={[{ value: '', label: t('filters.all_statuses') }, ...(['active', 'paused', 'ended'] as const).map((s) => ({ value: s, label: t(`status.${s}`) }))]} />
        {can('lessons.manage') && <PrimaryButton className="sm:ms-auto" onClick={() => setDialog(true)}>+ {t('new_circle')}</PrimaryButton>}
      </FilterBar>

      {conflicts && conflicts.length > 0 && (
        <Notice tone="error"><b>{t('form.conflicts')}.</b> {t('form.conflicts_body')}</Notice>
      )}

      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="lessons" title={t('empty')} />
      ) : (
        <>
          <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
            {q.data.data.map((l) => (
              <li key={l.id}>
                <Link to={`/lessons/${l.id}`} className={`${SURFACE} block h-full p-4 transition hover:border-brand-500/40 hover:shadow`}>
                  <div className="flex items-start justify-between gap-2">
                    <p dir="auto" className="font-semibold text-ink">{l.name}</p>
                    {l.gender && <Badge tone={GENDER_TONE[l.gender]}>{t(`gender.${l.gender}`)}</Badge>}
                  </div>
                  <p dir="auto" className="mt-0.5 text-sm text-ink/55">{l.teacher?.name} · {l.package?.name}</p>
                  <p className="mt-2 text-sm text-ink/70">
                    {l.days.map((d) => t(`days.${d}`)).join(locale === 'ar' ? '، ' : ', ')} · <span className="tabular-nums">{formatTime(l.start_time, locale)}–{formatTime(l.end_time, locale)}</span>
                  </p>
                  <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span className="inline-flex items-center gap-1 text-ink/60"><Icon name="pin" className="size-4" /><span dir="auto">{l.location?.name ?? t('no_hall')}</span></span>
                    <span className="ms-auto tabular-nums text-ink/70">{t('students_of', { n: n(l.student_count), c: n(l.capacity) })}</span>
                    {l.status !== 'active' && <Badge tone="muted">{t(`status.${l.status}`)}</Badge>}
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set('page', String(p))} />
        </>
      )}
      {dialog && <LessonFormDialog onClose={() => setDialog(false)} onSaved={(l, c) => { setDialog(false); setConflicts(c); if (!c.length) navigate(`/lessons/${l.id}`) }} />}
    </div>
  )
}

function Halls() {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['halls'], queryFn: () => hallsApi.list() })
  const [edit, setEdit] = useState<Hall | 'new' | null>(null)
  const toggle = useMutation({ mutationFn: (id: number) => hallsApi.toggle(id), onSuccess: () => void qc.invalidateQueries({ queryKey: ['halls'] }) })

  return (
    <div className="space-y-4">
      {can('locations.manage') && <div className="flex justify-end"><PrimaryButton onClick={() => setEdit('new')}>+ {t('new_hall')}</PrimaryButton></div>}
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data?.length ? (
        <EmptyCard icon="pin" title={t('empty_halls')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {q.data.map((h) => (
            <li key={h.id} className={`rounded-2xl border bg-white p-4 shadow-sm ${h.is_active ? 'border-ink/8' : 'border-dashed border-ink/20 opacity-75'}`}>
              <div className="flex items-start justify-between gap-2">
                <p dir="auto" className="font-semibold text-ink">{h.name}</p>
                <Badge tone={GENDER_TONE[h.gender]}>{t(`gender.${h.gender}`)}</Badge>
              </div>
              <p className="mt-1 text-sm text-ink/55">
                {h.code && <span className="tabular-nums">{h.code} · </span>}{t('halls.capacity')}: {formatNumber(h.capacity, locale)}
                {h.lessons_count !== undefined && <> · {t('halls.circles', { n: formatNumber(h.lessons_count, locale) })}</>}
              </p>
              {h.address && <p dir="auto" className="mt-1 text-sm text-ink/55">{h.address}</p>}
              <div className="mt-3 flex flex-wrap gap-2">
                <Link to={`/lessons/halls/${h.id}`} className={buttonClass('secondary')}><Icon name="attendance" className="size-4" />{t('halls.calendar')}</Link>
                {h.map_link && <a href={h.map_link} target="_blank" rel="noreferrer" className={buttonClass('secondary')}><Icon name="pin" className="size-4" />{t('halls.open_map')}</a>}
                {can('locations.manage') && (
                  <>
                    <SecondaryButton onClick={() => setEdit(h)}><Icon name="edit" className="size-4" />{t('halls.edit')}</SecondaryButton>
                    <SecondaryButton onClick={() => toggle.mutate(h.id)}>{h.is_active ? t('halls.toggle_off') : t('halls.toggle_on')}</SecondaryButton>
                  </>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {edit && <HallFormDialog hall={edit === 'new' ? undefined : edit} onClose={() => setEdit(null)} />}
    </div>
  )
}

function Bookings() {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const from = new Date().toISOString().slice(0, 10)
  const q = useQuery({ queryKey: ['bookings', page], queryFn: () => bookingsApi.list({ from, page, per_page: 20 }) })
  const remove = useMutation({ mutationFn: (id: number) => bookingsApi.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: ['bookings'] }) })

  return (
    <div className="space-y-4">
      {can('locations.manage') && <div className="flex justify-end"><PrimaryButton onClick={() => setOpen(true)}>+ {t('new_booking')}</PrimaryButton></div>}
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data?.data.length ? (
        <EmptyCard icon="attendance" title={t('empty_bookings')} />
      ) : (
        <>
          <ul className={`${SURFACE} divide-y divide-ink/6`}>
            {q.data.data.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                <span className="w-32 text-ink/70">{formatDate(b.booking_date, locale, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                <span className="tabular-nums text-ink/70">{formatTime(b.start_time, locale)}–{formatTime(b.end_time, locale)}</span>
                <span dir="auto" className="flex-1 font-medium text-ink">{b.title}</span>
                <span dir="auto" className="text-ink/55">{b.location?.name}</span>
                {b.gender && <Badge tone={GENDER_TONE[b.gender]}>{t(`gender.${b.gender}`)}</Badge>}
                {can('locations.manage') && <button type="button" className="text-xs text-danger hover:underline" onClick={() => window.confirm(t('bookings.delete_confirm')) && remove.mutate(b.id)}>{t('bookings.delete')}</button>}
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />
        </>
      )}
      {open && <BookingDialog onClose={() => setOpen(false)} />}
    </div>
  )
}
