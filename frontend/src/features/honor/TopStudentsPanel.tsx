import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { gradesApi } from '../../api/grades'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { EmptyCard, ErrorState, FilterBar, LoadingState, Notice, SecondaryButton, TABLE_HEAD, TableWrap } from '../../components/ui'
import { formatNumber, formatPercent } from '../../lib/format'
import { useTermScope } from '../../app/term'
const MEDAL = ['text-gold-500', 'text-ink/45', 'text-gold-700']

/** تحديد المتفوقين: the honor board's grades source — students ranked by their weighted grade totals of the term. */
export default function TopStudentsPanel({ gender }: { gender?: 'male' | 'female' }) {
  const { t, i18n } = useTranslation('grades')
  const ts = useTermScope()
  const locale = i18n.language
  const [levelId, setLevelId] = useState('')
  const [lessonId, setLessonId] = useState('')
  const [subjectId, setSubjectId] = useState('')
  const [limit, setLimit] = useState('10')
  const q = useQuery({
    queryKey: ['top-students', gender ?? null, levelId, lessonId, subjectId, limit],
    queryFn: () => gradesApi.topStudents({ gender, level_id: levelId || undefined, lesson_id: lessonId || undefined, subject_id: subjectId || undefined, limit }),
    placeholderData: (prev) => prev,
  })
  const d = q.data
  const n = (v: number) => formatNumber(v, locale)

  if (q.isLoading && !d) return <LoadingState />
  if (q.isError) return isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  if (!d) return null
  const classes = d.classes.filter((c) => !levelId || String(c.level_id) === levelId)

  return (
    <div className="space-y-4">
      <FilterBar label={t('common.filters')} className="print:hidden">
        <SelectField label={t('common.level')} className="sm:w-48" value={levelId} onChange={(e) => { setLevelId(e.target.value); setLessonId('') }}
          options={[{ value: '', label: t('common.all') }, ...d.levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
        <SelectField label={t('common.class')} className="sm:w-48" value={lessonId} onChange={(e) => setLessonId(e.target.value)}
          options={[{ value: '', label: t('common.all') }, ...classes.map((c) => ({ value: String(c.id), label: c.name }))]} />
        <SelectField label={t('common.subject')} className="sm:w-48" value={subjectId} onChange={(e) => setSubjectId(e.target.value)}
          options={[{ value: '', label: t('top.all_subjects') }, ...d.subjects.map((s) => ({ value: String(s.id), label: s.name }))]} />
        <SelectField label={t('top.limit')} className="sm:w-32" value={limit} onChange={(e) => setLimit(e.target.value)}
          options={['3', '10', '20', '50'].map((v) => ({ value: v, label: n(Number(v)) }))} />
        <SecondaryButton className="self-end sm:ms-auto" onClick={() => window.print()}><Icon name="printer" className="size-4" />{t('top.print')}</SecondaryButton>
      </FilterBar>
      <p className="text-sm text-ink/60">{t('top.hint', { term: ts.label(d.term.name), n: n(d.ranked) })}</p>
      {d.rows.length === 0 ? <EmptyCard icon="medal" title={t('top.empty')} body={t('top.empty_body')} /> : (
        <TableWrap surface>
          <table className="w-full min-w-[34rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th className="px-4 py-3 text-start font-medium">{t('top.rank')}</th>
                <th className="px-4 py-3 text-start font-medium">{t('common.student')}</th>
                <th className="px-4 py-3 text-start font-medium">{t('common.class')}</th>
                <th className="px-4 py-3 text-end font-medium">{subjectId ? t('top.total') : t('top.average')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {d.rows.map((r) => (
                <tr key={r.student.id}>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center gap-1.5 font-semibold tabular-nums text-ink">
                      {r.rank <= 3 && <Icon name="medal" className={`size-5 ${MEDAL[r.rank - 1]}`} title={t('top.place', { n: n(r.rank) })} />}{n(r.rank)}
                    </span>
                  </td>
                  <td className="px-4 py-3"><span dir="auto" className="font-medium text-ink">{r.student.full_name}</span><span className="block text-xs tabular-nums text-ink/50"><bdi dir="ltr">{r.student.student_no}</bdi></span></td>
                  <td className="px-4 py-3"><span dir="auto" className="text-ink/80">{r.lesson.name}</span>{r.level && <span className="block text-xs text-ink/50">{r.level.name}</span>}</td>
                  <td className="px-4 py-3 text-end">
                    <span className="font-semibold tabular-nums text-brand-700">{formatPercent(r.score, locale)}</span>
                    {!subjectId && r.subjects.length > 1 && <span className="block text-xs text-ink/50">{t('top.subjects_count', { n: n(r.subjects.length) })}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      )}
    </div>
  )
}
