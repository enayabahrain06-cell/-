import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { examsApi } from '../../api/exams'
import { useAuth } from '../../app/AuthContext'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { PageBand } from '../../components/ornaments'
import { Badge, ErrorState, LoadingState, PrimaryButton, type Tone, EmptyCard, SURFACE, FilterBar } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { GENDER_TONE } from '../lessons/LessonsHomePage'
import ExamFormDialog from './ExamFormDialog'
import MobileExams from './MobileExams'

export const EXAM_STATUS_TONE: Record<string, Tone> = { draft: 'muted', published: 'brand', closed: 'gold', graded: 'info' }

export default function ExamsHomePage() {
  const { t, i18n } = useTranslation('exams')
  const { t: tl } = useTranslation('lessons')
  const locale = i18n.language
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [open, setOpen] = useState(false)
  const filters = { type: params.get('type') ?? undefined, status: params.get('status') ?? undefined, page: Number(params.get('page') ?? 1), per_page: 20 }
  const q = useQuery({ queryKey: ['exams', filters, locale], queryFn: () => examsApi.list(filters), placeholderData: keepPreviousData })
  const set = (k: string, v: string) => { const n = new URLSearchParams(params); if (v) n.set(k, v); else n.delete(k); if (k !== 'page') n.delete('page'); setParams(n, { replace: true }) }
  const n = (v: number) => formatNumber(v, locale)
  const dt = (iso: string) => formatDate(iso, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })

  const pagination = q.data ? <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set('page', String(p))} /> : null

  return (
    <>
    <MobileExams exams={q.data?.data} loading={q.isLoading} error={q.isError || (!q.isLoading && !q.data)} onRetry={() => void q.refetch()} filters={filters} onSet={set}
      canCreate={can('exams.manage')} onCreate={() => setOpen(true)} pagination={pagination} />
    <div className="hidden space-y-5 lg:block">
      <PageBand title={t('title')} subtitle={t('subtitle')} />
      <FilterBar>
        <SelectField className="sm:w-40" label={t('filters.all_types')} hideLabel value={filters.type ?? ''} onChange={(e) => set('type', e.target.value)}
          options={[{ value: '', label: t('filters.all_types') }, { value: 'online', label: t('type.online') }, { value: 'paper', label: t('type.paper') }, { value: 'placement', label: t('type.placement') }]} />
        <SelectField className="sm:w-40" label={t('filters.all_statuses')} hideLabel value={filters.status ?? ''} onChange={(e) => set('status', e.target.value)}
          options={[{ value: '', label: t('filters.all_statuses') }, ...(['draft', 'published', 'closed', 'graded'] as const).map((s) => ({ value: s, label: t(`status.${s}`) }))]} />
        {can('exams.manage') && <PrimaryButton className="sm:ms-auto" onClick={() => setOpen(true)}>+ {t('new')}</PrimaryButton>}
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="exams" title={t('empty')} />
      ) : (
        <>
          <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
            {q.data.data.map((e) => (
              <li key={e.id}>
                <Link to={`/exams/${e.id}`} className={`${SURFACE} block h-full p-4 transition hover:border-brand-500/40 hover:shadow`}>
                  <div className="flex items-start justify-between gap-2">
                    <p dir="auto" className="font-semibold text-ink">{e.name}</p>
                    <div className="flex flex-wrap justify-end gap-1">
                      <Badge tone={EXAM_STATUS_TONE[e.status]}>{t(`status.${e.status}`)}</Badge>
                      {e.is_open_now && e.status === 'published' && <Badge tone="danger">{t('open_now')}</Badge>}
                    </div>
                  </div>
                  <p dir="auto" className="mt-1 text-sm text-ink/60">{e.lesson_name ?? e.package_name}</p>
                  <p className="mt-2 text-sm text-ink/70">{t(`type.${e.type}`)} · {e.type !== 'paper' ? t('window', { from: dt(e.opens_at), to: dt(e.closes_at) }) : formatDate(e.exam_date, locale)}</p>
                  <div className="mt-3 flex flex-wrap gap-2 text-xs text-ink/55">
                    {e.gender && <Badge tone={GENDER_TONE[e.gender]}>{tl(`gender.${e.gender}`)}</Badge>}
                    {/* A placement test has no pass mark: it recommends a level instead. */}
                    <span>{e.type === 'placement' ? t('placement.total_marks', { n: n(e.total_marks) }) : t('marks', { pass: n(e.pass_mark), total: n(e.total_marks) })}</span>
                    {e.type !== 'paper' && <span>· {t('duration', { n: n(e.duration_minutes) })} · {t('questions_n', { n: n(e.questions_count ?? 0) })}</span>}
                    <span>· {t('attempts_n', { n: n(e.attempts_count ?? 0) })}</span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={(p) => set('page', String(p))} />
        </>
      )}
    </div>
      {/* A new exam opens on its Questions tab with the question editor ready (ExamDetailPage reads ?add=1). */}
      {open && <ExamFormDialog onClose={() => setOpen(false)} onSaved={(e) => { setOpen(false); navigate(`/exams/${e.id}?tab=questions&add=1`) }} />}
    </>
  )
}
