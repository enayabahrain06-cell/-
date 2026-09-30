import { useState } from 'react'
import { isAxiosError } from 'axios'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { gradesApi, type MonitorRow } from '../../api/grades'
import { parseApiError } from '../../api/client'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Notice, SURFACE, TABLE_HEAD, TableWrap } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { useTermScope } from '../../app/term'

/** مراقبة تسليم الدرجات: per class, subject and grade component, the students still without a score and who owes them. */
export default function GradeMonitorPage() {
  const { t, i18n } = useTranslation('grades')
  const ts = useTermScope()
  const locale = i18n.language
  const [levelId, setLevelId] = useState('')
  const [teacherId, setTeacherId] = useState('')
  const [onlyMissing, setOnlyMissing] = useState(true)
  const q = useQuery({
    queryKey: ['grade-monitor', levelId, teacherId, onlyMissing],
    queryFn: () => gradesApi.monitor({ level_id: levelId || undefined, teacher_id: teacherId || undefined, only_missing: onlyMissing ? 1 : undefined }),
    placeholderData: (prev) => prev,
  })
  const d = q.data
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t('nav:menu.grade_submission_monitor')} subtitle={d ? t('monitor.subtitle', { term: ts.label(d.term.name) }) : undefined} /></div>
      {q.isLoading && !d ? <LoadingState /> : q.isError ? (
        isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
      ) : d && (
        <>
          <FilterBar label={t('common.filters')}>
            <SelectField label={t('common.level')} className="sm:w-56" value={levelId} onChange={(e) => setLevelId(e.target.value)}
              options={[{ value: '', label: t('common.all') }, ...d.levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
            <SelectField label={t('monitor.teacher')} className="sm:w-56" value={teacherId} onChange={(e) => setTeacherId(e.target.value)}
              options={[{ value: '', label: t('common.all') }, ...d.teachers.map((x) => ({ value: String(x.id), label: x.name }))]} />
            <label className="flex min-h-10 items-center gap-2 self-end text-sm text-ink/80">
              <input type="checkbox" className="size-4 accent-brand-600" checked={onlyMissing} onChange={(e) => setOnlyMissing(e.target.checked)} />{t('monitor.only_missing')}
            </label>
          </FilterBar>
          <div className="grid grid-cols-3 gap-3">
            {([['rows', d.summary.rows], ['incomplete', d.summary.incomplete], ['missing', d.summary.missing]] as const).map(([k, v]) => (
              <div key={k} className={`${SURFACE} min-w-0 p-4`}>
                <p className="text-xs text-ink/60 sm:text-sm">{t(`monitor.stat_${k}`)}</p>
                <p className={`mt-1 text-2xl font-semibold tabular-nums ${k !== 'rows' && v > 0 ? 'text-danger' : 'text-ink'}`}>{n(v)}</p>
              </div>
            ))}
          </div>
          {d.rows.length === 0 ? <EmptyCard icon="check" title={onlyMissing ? t('monitor.all_done') : t('monitor.empty')} /> : (
            <TableWrap surface>
              <table className="w-full min-w-[46rem] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th className="px-4 py-3 text-start font-medium">{t('common.class')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('common.subject')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('monitor.component')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('monitor.teachers')}</th>
                    <th className="px-4 py-3 text-end font-medium">{t('monitor.missing')}</th>
                    <th className="relative px-4 py-3"><span className="sr-only">{t('monitor.open')}</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink/6">
                  {d.rows.map((r, i) => <Row key={`${r.lesson.id}-${r.subject.id}-${r.component?.id ?? 'none'}-${i}`} r={r} />)}
                </tbody>
              </table>
            </TableWrap>
          )}
        </>
      )}
    </div>
  )
}

function Row({ r }: { r: MonitorRow }) {
  const { t, i18n } = useTranslation('grades')
  const n = (v: number) => formatNumber(v, i18n.language)
  const missing = r.missing > 0
  const recordable = r.component && r.component.kind !== 'exam'
  return (
    <tr className={missing ? 'bg-danger/5' : ''}>
      <td className="px-4 py-3"><span dir="auto" className="font-medium text-ink">{r.lesson.name}</span><span className="block text-xs text-ink/50">{r.level.name}</span></td>
      <td className="px-4 py-3 text-ink/80">{r.subject.name}</td>
      <td className="px-4 py-3">
        {r.component ? (
          <>
            <span dir="auto" className="text-ink">{r.component.name}</span>{' '}
            <Badge tone={r.component.kind === 'exam' ? 'info' : 'muted'}>{t(`kind.${r.component.kind}`)}</Badge>
            {r.component.kind === 'exam' && (r.exam ? (
              <span className="block text-xs text-ink/55"><bdi>{r.exam.name}</bdi>{r.exam_covers_class === false && <span className="text-gold-700">{t('common.sep')}{t('monitor.exam_other_class')}</span>}</span>
            ) : <span className="block text-xs text-gold-700">{t('monitor.no_exam')}</span>)}
          </>
        ) : <Badge tone="gold">{t('monitor.no_distribution')}</Badge>}
      </td>
      <td className="px-4 py-3 text-ink/75">{r.teachers.length ? r.teachers.map((x) => x.name).join(t('common.sep')) : '—'}</td>
      <td className="px-4 py-3 text-end tabular-nums">
        <span className={missing ? 'font-semibold text-danger' : 'text-brand-700'}>{n(r.missing)}</span>
        <span className="text-xs text-ink/50"> / {n(r.students)}</span>
      </td>
      <td className="px-4 py-3 text-end">
        {recordable ? <Link to={`/grades?tab=record&lesson=${r.lesson.id}`} className="text-sm font-medium text-brand-700 hover:underline">{t('monitor.record')}</Link>
          : r.exam ? <Link to={`/upload-exam-grades?exam=${r.exam.id}`} className="text-sm font-medium text-brand-700 hover:underline">{t('monitor.enter_exam')}</Link>
          : !r.component ? <Link to="/grade-distribution" className="text-sm font-medium text-brand-700 hover:underline">{t('monitor.distribute')}</Link> : null}
      </td>
    </tr>
  )
}
