import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { archiveApi, type ArchiveRecord } from '../../api/archive'
import { EmptyCard, ErrorState, LoadingState, TABLE_HEAD, TableWrap } from '../../components/ui'

/** The student's archive rows (records of previous years), on the profile page and in عرض الأرشيف. */
export default function StudentArchiveTab({ studentId }: { studentId: number }) {
  const q = useQuery({ queryKey: ['student-archive', studentId], queryFn: () => archiveApi.student(studentId) })
  if (q.isLoading) return <LoadingState />
  if (q.isError) return <ErrorState onRetry={() => void q.refetch()} />
  return <ArchiveTable rows={q.data ?? []} />
}

export function ArchiveTable({ rows, showName = false, onName }: { rows: ArchiveRecord[]; showName?: boolean; onName?: (r: ArchiveRecord) => void }) {
  const { t } = useTranslation('archive')
  if (rows.length === 0) return <EmptyCard icon="history" title={t('empty')} />
  return (
    <TableWrap surface>
      <table className="w-full min-w-[44rem] text-sm">
        <thead className={TABLE_HEAD}>
          <tr>
            {showName && <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.full_name')}</th>}
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.academic_year')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.term_label')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.level_label')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.class_label')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.subject')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.result')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('col.grade')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-ink/6">
          {rows.map((r) => (
            <tr key={r.id}>
              {showName && (
                <td className="px-3 py-2">
                  {onName ? (
                    <button type="button" className="text-start font-medium text-brand-700 hover:underline" dir="auto" onClick={() => onName(r)}>{r.full_name}</button>
                  ) : <span dir="auto" className="font-medium text-ink">{r.full_name}</span>}
                  <span className="block text-xs tabular-nums text-ink/50">{r.student?.student_no ?? r.cpr ?? r.student_no ?? t('unmatched')}</span>
                </td>
              )}
              <td className="px-3 py-2 tabular-nums" dir="ltr">{r.academic_year}</td>
              <td className="px-3 py-2 text-ink/70" dir="auto">{r.term_label ?? '—'}</td>
              <td className="px-3 py-2 text-ink/70" dir="auto">{r.level_label ?? '—'}</td>
              <td className="px-3 py-2 text-ink/70" dir="auto">{r.class_label ?? '—'}</td>
              <td className="px-3 py-2 text-ink/70" dir="auto">{r.subject ?? '—'}</td>
              <td className="px-3 py-2" dir="auto">{r.result ?? '—'}</td>
              <td className="px-3 py-2 tabular-nums" dir="auto">{r.grade ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </TableWrap>
  )
}
