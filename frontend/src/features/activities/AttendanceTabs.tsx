import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { activitiesApi, type Activity, type ActivityAttendanceStatus, type AttendanceSheet } from '../../api/activities'
import { parseApiError } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, FilterBar, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, SURFACE, TABLE_HEAD, TableWrap, TextInput } from '../../components/ui'
import { formatDate, formatNumber, formatPercent } from '../../lib/format'
import { QueryError, STATUS_TONE } from '../attendanceFollowup/shared'
import type { CrudNotice } from '../common/crud'
import { StudentCell } from './shared'

const STATUSES: ActivityAttendanceStatus[] = ['present', 'late', 'absent', 'excused']

/** تسجيل حضور البرنامج / حضور الرحلة: one day of the activity, its registered students. */
export default function AttendanceTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const [date, setDate] = useState<string | undefined>(undefined)
  const q = useQuery({ queryKey: ['activity-attendance', activity.id, date ?? null], queryFn: () => activitiesApi.attendance(activity.id, date), placeholderData: keepPreviousData })
  if (q.isLoading) return <LoadingState />
  if (q.isError) return <QueryError error={q.error} onRetry={() => void q.refetch()} />
  if (!q.data) return null
  if (q.data.dates.length === 0) return <EmptyCard icon="attendance" title={t('attendance.not_started')} />

  return (
    <div className="space-y-4">
      {q.data.dates.length > 1 && (
        <FilterBar label={t('filters')}>
          <SelectField label={t('attendance.date')} className="sm:w-64" value={q.data.date} onChange={(e) => setDate(e.target.value)}
            options={q.data.dates.map((d) => ({ value: d, label: formatDate(d, i18n.language, { weekday: 'long', day: 'numeric', month: 'long' }) }))} />
        </FilterBar>
      )}
      {q.data.data.length === 0 ? <EmptyCard icon="students" title={t('no_registrations')} /> : <Sheet key={q.data.date} activity={activity} sheet={q.data} />}
    </div>
  )
}

function Sheet({ activity, sheet }: { activity: Activity; sheet: AttendanceSheet }) {
  const { t, i18n } = useTranslation('activities')
  const n = (v: number) => formatNumber(v, i18n.language)
  const qc = useQueryClient()
  const [drafts, setDrafts] = useState<Record<number, { status: ActivityAttendanceStatus | null; notes: string }>>(
    () => Object.fromEntries(sheet.data.map((r) => [r.student.id, { status: r.status, notes: r.notes ?? '' }])))
  const [notice, setNotice] = useState<CrudNotice>(null)
  const patch = (id: number, p: Partial<{ status: ActivityAttendanceStatus | null; notes: string }>) => setDrafts({ ...drafts, [id]: { ...drafts[id], ...p } })
  const marked = Object.values(drafts).filter((d) => d.status).length
  const save = useMutation({
    mutationFn: () => activitiesApi.saveAttendance(activity.id, { date: sheet.date, rows: sheet.data.map((r) => ({ student_id: r.student.id, status: drafts[r.student.id].status, notes: drafts[r.student.id].notes || null })) }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); ['activity-attendance', 'activity-roster', 'activity-attendance-summary'].forEach((k) => void qc.invalidateQueries({ queryKey: [k] })) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  return (
    <div className="space-y-4">
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <section className={SURFACE} aria-label={t('attendance.sheet')}>
        <div className="flex flex-wrap items-center gap-3 border-b border-ink/6 px-4 py-3 sm:px-5">
          <h2 className="flex-1 text-base font-semibold text-ink">{formatDate(sheet.date, i18n.language, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</h2>
          <SecondaryButton className="py-1.5" onClick={() => setDrafts(Object.fromEntries(sheet.data.map((r) => [r.student.id, { ...drafts[r.student.id], status: 'present' as const }])))}>{t('attendance.all_present')}</SecondaryButton>
        </div>
        <ul className="divide-y divide-ink/6">
          {sheet.data.map((r) => {
            const d = drafts[r.student.id]
            return (
              <li key={r.student.id} className="space-y-2 px-4 py-3 sm:px-5">
                <div className="flex flex-wrap items-center gap-3">
                  <div className="min-w-0 flex-[1_1_12rem]"><StudentCell s={r.student} /></div>
                  <Segmented fill name={`act-status-${r.student.id}`} label={r.student.full_name} value={d.status} onChange={(v) => patch(r.student.id, { status: v })}
                    options={STATUSES.map((s) => ({ value: s, label: t(`attendance.status.${s}`), tone: STATUS_TONE[s] }))} />
                </div>
                {d.status && d.status !== 'present' && (
                  <TextInput label={t('attendance.note')} hideLabel placeholder={t('attendance.note')} dir="auto" value={d.notes} onChange={(e) => patch(r.student.id, { notes: e.target.value })} />
                )}
              </li>
            )
          })}
        </ul>
        <div className="flex flex-wrap items-center gap-3 border-t border-ink/6 px-4 py-3 sm:px-5">
          <span className="text-sm tabular-nums text-ink/60">{t('attendance.marked', { marked: n(marked), total: n(sheet.data.length) })}</span>
          <PrimaryButton className="ms-auto min-w-36" loading={save.isPending} disabled={marked === 0} onClick={() => save.mutate()}>{t('save')}</PrimaryButton>
        </div>
      </section>
    </div>
  )
}

/** متابعة حضور البرنامج / الرحلة: the rate of each registered student and each day's tally. */
export function AttendanceFollowupTab({ activity }: { activity: Activity }) {
  const { t, i18n } = useTranslation('activities')
  const l = i18n.language
  const n = (v: number) => formatNumber(v, l)
  const roster = useQuery({ queryKey: ['activity-roster', activity.id], queryFn: () => activitiesApi.roster(activity.id) })
  const summary = useQuery({ queryKey: ['activity-attendance-summary', activity.id], queryFn: () => activitiesApi.attendanceSummary(activity.id) })
  if (roster.isLoading || summary.isLoading) return <LoadingState />
  if (roster.isError) return <QueryError error={roster.error} onRetry={() => void roster.refetch()} />
  const rows = (roster.data?.data ?? []).filter((r) => r.status === 'registered')
  const days = summary.data?.data ?? []
  if (rows.length === 0) return <EmptyCard icon="students" title={t('no_registrations')} />
  const th = 'px-3 py-2 text-center font-medium'

  return (
    <div className="grid gap-4 *:min-w-0 2xl:grid-cols-[3fr_2fr]">
      <section className="space-y-2" aria-labelledby="att-students">
        <h2 id="att-students" className="text-base font-semibold text-ink">{t('attendance.per_student')}</h2>
        <TableWrap surface>
          <table className="w-full min-w-[36rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
                {STATUSES.map((s) => <th key={s} scope="col" className={th}>{t(`attendance.status.${s}`)}</th>)}
                <th scope="col" className={th}>{t('attendance.rate')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2"><StudentCell s={r.student} /></td>
                  {STATUSES.map((s) => <td key={s} className="px-3 py-2 text-center tabular-nums">{n(r.attendance[s])}</td>)}
                  <td className="px-3 py-2 text-center">
                    {r.attendance.rate === null ? <Badge>{t('attendance.none')}</Badge> : <Badge tone={r.attendance.rate >= 75 ? 'brand' : r.attendance.rate >= 50 ? 'gold' : 'danger'}>{formatPercent(r.attendance.rate, l)}</Badge>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
        <p className="text-xs text-ink/50">{t('attendance.rate_hint')}</p>
      </section>
      <section className="space-y-2" aria-labelledby="att-days">
        <h2 id="att-days" className="text-base font-semibold text-ink">{t('attendance.per_date')}</h2>
        {days.length === 0 ? <EmptyCard icon="attendance" title={t('attendance.not_started')} /> : (
          <TableWrap surface>
            <table className="w-full min-w-[26rem] text-sm">
              <thead className={TABLE_HEAD}>
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('attendance.date')}</th>
                  {STATUSES.map((s) => <th key={s} scope="col" className={th}>{t(`attendance.status.${s}`)}</th>)}
                  <th scope="col" className={th}>{t('attendance.not_recorded')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {days.map((d) => (
                  <tr key={d.date}>
                    <td className="whitespace-nowrap px-3 py-2 tabular-nums">{formatDate(d.date, l, { weekday: 'short', day: 'numeric', month: 'short' })}</td>
                    {STATUSES.map((s) => <td key={s} className="px-3 py-2 text-center tabular-nums">{n(d[s])}</td>)}
                    <td className={`px-3 py-2 text-center tabular-nums ${d.recorded < (summary.data?.registered ?? 0) ? 'font-semibold text-danger' : ''}`}>{n(Math.max(0, (summary.data?.registered ?? 0) - d.recorded))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
      </section>
    </div>
  )
}
