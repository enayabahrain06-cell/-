import { useState } from 'react'
import { isAxiosError } from 'axios'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { divisionsApi } from '../../api/education'
import { lessonsApi } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { EmptyCard, ErrorState, FilterBar, LoadingState, Notice, Segmented, SURFACE, TABLE_HEAD, TableWrap, TextInput, buttonClass } from '../../components/ui'
import { formatDate, formatNumber, formatPercent, formatTime } from '../../lib/format'
import { useEmbed, useOwnParam } from '../../app/embed'
import { useTermScope } from '../../app/term'

type Tab = 'record' | 'view'

/** Local calendar date as YYYY-MM-DD. */
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const shift = (days: number) => { const d = new Date(); d.setDate(d.getDate() + days); return ymd(d) }

/**
 * تقييم طلبة التقسيم / عرض تقييم طلبة التقسيم. Recording opens the ordinary evaluation sheet of a session filtered to
 * the division (the same sheet, one source); the view shows each student's average per criterion.
 */
export default function DivisionEvaluationPage() {
  const { t } = useTranslation('education')
  const ts = useTermScope()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tab: Tab = ownTab === 'view' ? 'view' : 'record'
  const base = useQuery({ queryKey: ['divisions', 'classes'], queryFn: () => divisionsApi.list() })
  const [lessonId, setLessonId] = useState<number | ''>('')
  const [divisionId, setDivisionId] = useState<number | ''>('')
  const cls = useQuery({ queryKey: ['divisions', lessonId], queryFn: () => divisionsApi.list(Number(lessonId)), enabled: lessonId !== '' })
  const divisions = cls.data?.divisions ?? []

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t(`nav:menu.${tab === 'view' ? 'view_division_evaluation' : 'division_evaluation'}`)} subtitle={t(tab === 'view' ? 'division_eval.subtitle_view' : 'division_eval.subtitle_record')} />
      </div>
      {!host && <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
        <Segmented name="division-eval-tab" label={t('nav:division_evaluation')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
          options={[{ value: 'record', label: t('nav:menu.division_evaluation') }, { value: 'view', label: t('nav:menu.view_division_evaluation') }]} />
      </div>}
      {base.isLoading ? <LoadingState /> : base.isError ? (
        isAxiosError(base.error) && base.error.response?.status === 422 ? <Notice tone="info">{parseApiError(base.error).message}</Notice> : <ErrorState onRetry={() => void base.refetch()} />
      ) : base.data && (base.data.classes.length === 0 ? <EmptyCard icon="lessons" title={t('division_eval.no_classes', { scope: ts.scope() })} /> : (
        <>
          <FilterBar label={t('division_eval.class')}>
            <SelectField label={t('division_eval.class')} className="sm:w-64" value={String(lessonId)} onChange={(e) => { setLessonId(e.target.value ? Number(e.target.value) : ''); setDivisionId('') }}
              options={[{ value: '', label: '—' }, ...base.data.classes.map((c) => ({ value: String(c.id), label: c.name }))]} />
            <SelectField label={t('division_eval.division')} className="sm:w-56" value={String(divisionId)} disabled={divisions.length === 0} onChange={(e) => setDivisionId(e.target.value ? Number(e.target.value) : '')}
              options={[{ value: '', label: '—' }, ...divisions.map((d) => ({ value: String(d.id), label: d.name }))]} />
          </FilterBar>
          {lessonId !== '' && cls.data && divisions.length === 0 && <EmptyCard icon="students" title={t('division_eval.no_divisions')} />}
          {lessonId !== '' && divisionId !== '' && (tab === 'record'
            ? <RecordTab lessonId={lessonId} divisionId={divisionId} />
            : <ViewTab divisionId={divisionId} />)}
        </>
      ))}
    </div>
  )
}

/** The class's sessions around today; each opens the evaluation sheet for the division. */
function RecordTab({ lessonId, divisionId }: { lessonId: number; divisionId: number }) {
  const { t, i18n } = useTranslation('education')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['lesson-sessions', lessonId, 'division-eval'], queryFn: () => lessonsApi.sessions(lessonId, shift(-21), shift(7)) })
  if (q.isLoading) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  const sessions = (q.data ?? []).filter((s) => s.status !== 'cancelled').slice().reverse()
  if (sessions.length === 0) return <EmptyCard icon="attendance" title={t('division_eval.no_sessions')} />
  return (
    <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
      {sessions.map((s) => (
        <li key={s.id} className={`${SURFACE} flex items-center gap-3 p-4`}>
          <div className="min-w-0 flex-1">
            <p className="font-semibold text-ink">{formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
            <p className="text-sm tabular-nums text-ink/55">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</p>
          </div>
          <Link to={`/evaluation/${s.id}?division=${divisionId}`} className={buttonClass('secondary', 'shrink-0 gap-1.5 px-3 py-2')}>
            <Icon name="evaluation" className="size-4" />{t('division_eval.open')}
          </Link>
        </li>
      ))}
    </ul>
  )
}

/** Averages per criterion and student over a date range, for one subject. */
function ViewTab({ divisionId }: { divisionId: number }) {
  const { t, i18n } = useTranslation('education')
  const locale = i18n.language
  const [subjectId, setSubjectId] = useState<number | undefined>(undefined)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [type, setType] = useState<'all' | 'daily' | 'monthly'>('all')
  const q = useQuery({
    queryKey: ['division-results', divisionId, subjectId ?? null, from, to, type],
    queryFn: () => divisionsApi.results(divisionId, { subject_id: subjectId, from: from || undefined, to: to || undefined, type: type === 'all' ? undefined : type }),
    placeholderData: keepPreviousData,
  })
  const n1 = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatNumber(v, locale, { maximumFractionDigits: 1 }))

  return (
    <div className="space-y-4">
      <FilterBar label={t('division_eval.subject')}>
        {q.data && q.data.subjects.length > 1 && (
          <SelectField label={t('division_eval.subject')} className="sm:w-52" value={String(q.data.subject_id)} onChange={(e) => setSubjectId(Number(e.target.value))}
            options={q.data.subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
        )}
        <TextInput label={t('division_eval.from')} type="date" className="sm:w-40" value={from} onChange={(e) => setFrom(e.target.value)} />
        <TextInput label={t('division_eval.to')} type="date" className="sm:w-40" value={to} onChange={(e) => setTo(e.target.value)} />
        <Segmented name="division-eval-type" label={t('division_eval.type')} value={type} onChange={setType}
          options={(['all', 'daily', 'monthly'] as const).map((k) => ({ value: k, label: t(`division_eval.types.${k}`) }))} />
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} /> : q.data && (q.data.data.length === 0 ? <EmptyCard icon="students" title={t('division_eval.empty')} /> : (
        <TableWrap surface>
          <table className="w-full min-w-[36rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th scope="col" className="px-3 py-2 text-start font-medium">{t('division_eval.student')}</th>
                {q.data.criteria.map((c) => <th key={c.id} scope="col" className="px-2 py-2 text-center font-medium"><bdi>{c.name}</bdi></th>)}
                <th scope="col" className="px-2 py-2 text-center font-medium">{t('division_eval.percent')}</th>
                <th scope="col" className="px-2 py-2 text-center font-medium">{t('division_eval.count')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {q.data.data.map((r) => (
                <tr key={r.student.id}>
                  <td className="px-3 py-2">
                    <Link to={`/students/${r.student.id}`} dir="auto" className="font-medium text-ink hover:text-brand-700">{r.student.full_name}</Link>
                  </td>
                  {q.data!.criteria.map((c) => <td key={c.id} className="px-2 py-2 text-center tabular-nums">{n1(r.averages[String(c.id)])}</td>)}
                  <td className="px-2 py-2 text-center tabular-nums">{r.percent === null ? '—' : formatPercent(Math.round(r.percent), locale)}</td>
                  <td className="px-2 py-2 text-center tabular-nums">{formatNumber(r.count, locale)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t border-ink/10 bg-page/60 font-semibold">
              <tr>
                <td className="px-3 py-2">{t('division_eval.average')}</td>
                {q.data.criteria.map((c) => <td key={c.id} className="px-2 py-2 text-center tabular-nums">{n1(q.data!.averages[String(c.id)])}</td>)}
                <td className="px-2 py-2 text-center tabular-nums">{q.data.percent === null ? '—' : formatPercent(Math.round(q.data.percent), locale)}</td>
                <td />
              </tr>
            </tfoot>
          </table>
        </TableWrap>
      ))}
    </div>
  )
}
