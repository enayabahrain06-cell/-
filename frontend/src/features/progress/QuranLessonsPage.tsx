import { useState } from 'react'
import { isAxiosError } from 'axios'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { quranLessonsApi } from '../../api/education'
import { parseApiError } from '../../api/client'
import type { StudentSummary } from '../../api/students'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import StudentPicker from '../../components/StudentPicker'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Notice, SecondaryButton, Segmented, TABLE_HEAD, TableWrap, TextInput } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { useTermScope } from '../../app/term'

type Type = 'all' | 'memorized' | 'revised'

/** دروس القرآن: the term's memorization ledger, read-only (recorded from the attendance and evaluation sheets). */
export default function QuranLessonsPage() {
  const { t, i18n } = useTranslation('education')
  const ts = useTermScope()
  const locale = i18n.language
  const [lessonId, setLessonId] = useState('')
  const [student, setStudent] = useState<StudentSummary | null>(null)
  const [type, setType] = useState<Type>('all')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const filters = { lesson_id: lessonId ? Number(lessonId) : undefined, student_id: student?.id, type: type === 'all' ? undefined : type, from: from || undefined, to: to || undefined, page }
  const q = useQuery({ queryKey: ['quran-lessons', filters], queryFn: () => quranLessonsApi.list(filters), placeholderData: keepPreviousData })
  const reset = () => setPage(1)

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.quran_lessons')} subtitle={q.data ? t('quran.subtitle', { term: ts.label(q.data.term.name) }) : undefined} />
      </div>
      <FilterBar label={t('quran.class')}>
        <SelectField label={t('quran.class')} className="sm:w-52" value={lessonId} onChange={(e) => { setLessonId(e.target.value); reset() }}
          options={[{ value: '', label: t('quran.all_classes') }, ...(q.data?.classes ?? []).map((c) => ({ value: String(c.id), label: c.name }))]} />
        <div className="min-w-0 sm:w-64">
          <StudentPicker label={t('quran.student')} value={student} onChange={(s) => { setStudent(s); reset() }} />
        </div>
        <TextInput label={t('quran.from')} type="date" className="sm:w-40" value={from} onChange={(e) => { setFrom(e.target.value); reset() }} />
        <TextInput label={t('quran.to')} type="date" className="sm:w-40" value={to} onChange={(e) => { setTo(e.target.value); reset() }} />
        <Segmented name="quran-type" label={t('quran.type')} value={type} onChange={(v) => { setType(v); reset() }}
          options={(['all', 'memorized', 'revised'] as const).map((k) => ({ value: k, label: t(`quran.types.${k}`) }))} />
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError ? (
        isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState onRetry={() => void q.refetch()} />
      ) : q.data && (q.data.data.length === 0 ? <EmptyCard icon="lessons" title={t('quran.empty')} /> : (
        <>
          <p className="text-sm text-ink/60">{t('quran.total', { n: formatNumber(q.data.meta.total, locale), ayahs: formatNumber(q.data.meta.ayahs, locale) })}</p>
          <TableWrap surface>
            <table className="w-full min-w-[44rem] text-sm">
              <thead className={TABLE_HEAD}>
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('quran.date')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('quran.student')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('quran.class')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('quran.type')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('quran.range')}</th>
                  <th scope="col" className="px-3 py-2 text-end font-medium">{t('quran.ayahs')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('quran.recorded_by')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {q.data.data.map((r) => (
                  <tr key={r.id}>
                    <td className="whitespace-nowrap px-3 py-2 text-ink/70">{r.date ? formatDate(r.date, locale, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}</td>
                    <td className="px-3 py-2">
                      {r.student ? <Link to={`/students/${r.student.id}`} dir="auto" className="font-medium text-ink hover:text-brand-700">{r.student.full_name}</Link> : '—'}
                    </td>
                    <td className="px-3 py-2 text-ink/70"><bdi>{r.lesson?.name ?? '—'}</bdi></td>
                    <td className="px-3 py-2"><Badge tone={r.type === 'memorized' ? 'brand' : 'info'}>{t(`quran.types.${r.type}`)}</Badge></td>
                    <td className="px-3 py-2">{t('quran.range_text', { surah: r.surah, from: formatNumber(r.from_ayah, locale), to: formatNumber(r.to_ayah, locale) })}</td>
                    <td className="px-3 py-2 text-end tabular-nums">{formatNumber(r.ayah_count, locale)}</td>
                    <td className="px-3 py-2 text-ink/70"><bdi>{r.recorded_by ?? '—'}</bdi></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
          {q.data.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3">
              <SecondaryButton disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('quran.prev')}</SecondaryButton>
              <span className="text-sm tabular-nums text-ink/60">{t('quran.page', { n: formatNumber(page, locale), of: formatNumber(q.data.meta.last_page, locale) })}</span>
              <SecondaryButton disabled={page >= q.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>{t('quran.next')}</SecondaryButton>
            </div>
          )}
        </>
      ))}
    </div>
  )
}
