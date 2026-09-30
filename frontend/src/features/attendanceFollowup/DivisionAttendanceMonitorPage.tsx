import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceFollowupApi } from '../../api/attendanceFollowup'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import { Badge, EmptyCard, FilterBar, LoadingState, Segmented, TABLE_HEAD, TableWrap, buttonClass } from '../../components/ui'
import { formatNumber, formatTime } from '../../lib/format'
import { DateStepper, QueryError, Stat, todayIso } from './shared'

/** مراقبة تسجيل حضور التقسيم: the night's divisions and how many of their students have no attendance row yet. */
export default function DivisionAttendanceMonitorPage() {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const date = params.get('date') ?? todayIso()
  const [only, setOnly] = useState<'all' | 'incomplete'>('incomplete')
  const q = useQuery({ queryKey: ['division-nights', date], queryFn: () => attendanceFollowupApi.divisionNights(date) })
  const rows = (q.data?.data ?? []).flatMap((s) => s.divisions.map((d) => ({ s, d })))
  const incomplete = rows.filter((r) => r.d.missing > 0)
  const shown = only === 'incomplete' ? incomplete : rows

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.division_attendance_monitor')} subtitle={t('division_monitor.subtitle')} />
      </div>
      <FilterBar label={t('date')}>
        <DateStepper id="division-monitor-date" label={t('date')} value={date} onChange={(v) => setParams({ date: v }, { replace: true })} />
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : rows.length === 0 ? (
        <EmptyCard icon="students" title={t('division.no_nights')} body={t('division.no_nights_body')} />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 *:min-w-0 sm:grid-cols-3">
            <Stat label={t('division_monitor.divisions')} value={rows.length} />
            <Stat label={t('division_monitor.complete')} value={rows.length - incomplete.length} tone="brand" />
            <Stat label={t('division_monitor.incomplete')} value={incomplete.length} tone={incomplete.length ? 'danger' : 'ink'} />
          </div>
          <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
            <Segmented name="division-monitor-filter" label={t('division_monitor.show')} value={only} onChange={setOnly}
              options={[{ value: 'incomplete', label: t('division_monitor.only_incomplete') }, { value: 'all', label: t('division_monitor.all') }]} />
          </div>
          {shown.length === 0 ? <EmptyCard icon="check" title={t('division_monitor.all_done')} /> : (
            <TableWrap surface>
              <table className="w-full min-w-[40rem] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th scope="col" className="px-4 py-3 text-start font-medium">{t('class')}</th>
                    <th scope="col" className="px-4 py-3 text-start font-medium">{t('division.division')}</th>
                    <th scope="col" className="px-4 py-3 text-start font-medium">{t('time')}</th>
                    <th scope="col" className="px-4 py-3 text-center font-medium">{t('division_monitor.students')}</th>
                    <th scope="col" className="px-4 py-3 text-center font-medium">{t('division_monitor.missing')}</th>
                    <th scope="col" className="px-4 py-3" aria-label={t('actions')} />
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink/6">
                  {shown.map(({ s, d }) => (
                    <tr key={`${s.id}-${d.id}`} className={d.missing > 0 ? 'bg-danger/5' : ''}>
                      <td className="px-4 py-3 font-medium text-ink"><bdi>{s.lesson.name}</bdi></td>
                      <td className="px-4 py-3">
                        <bdi className="text-ink">{d.name}</bdi>
                        {d.teacher && <p className="text-xs text-ink/55"><bdi>{d.teacher.name}</bdi></p>}
                      </td>
                      <td className="px-4 py-3 tabular-nums text-ink/70">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</td>
                      <td className="px-4 py-3 text-center tabular-nums">{t('division.recorded_of', { recorded: n(d.recorded), total: n(d.students) })}</td>
                      <td className="px-4 py-3 text-center">
                        {d.missing > 0 ? <Badge tone="danger">{n(d.missing)}</Badge> : <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('division_monitor.done')}</Badge>}
                      </td>
                      <td className="px-4 py-3 text-end">
                        {can('attendance.record') && q.data!.can_record && (
                          <Link to={`/division-attendance?date=${date}&session=${s.id}&division=${d.id}`} className={buttonClass('secondary', 'gap-1.5 px-3 py-1.5')}>
                            <Icon name="attendance" className="size-4" />{d.missing > 0 ? t('division_monitor.record') : t('division_monitor.open')}
                          </Link>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableWrap>
          )}
        </>
      )}
    </div>
  )
}
