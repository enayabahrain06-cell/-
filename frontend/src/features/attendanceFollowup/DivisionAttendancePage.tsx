import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { AttendanceStatus } from '../../api/attendance'
import { attendanceFollowupApi, type DivisionSheet } from '../../api/attendanceFollowup'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, FilterBar, LoadingState, Notice, PrimaryButton, SURFACE, Segmented, TextInput } from '../../components/ui'
import { formatNumber, formatTime } from '../../lib/format'
import { DateStepper, QueryError, STATUS_TONE, todayIso } from './shared'

const STATUSES: AttendanceStatus[] = ['present', 'late', 'absent', 'excused']

/**
 * حضور التقسيم: date → the night's class session → one of its divisions → that division's students. Saving writes the
 * session's ordinary attendance rows (the class sheet shows the same data); the sheet then shows what is recorded.
 */
export default function DivisionAttendancePage() {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  const [params, setParams] = useSearchParams()
  const date = params.get('date') ?? todayIso()
  const set = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, v)
      else next.delete(k)
    }
    setParams(next, { replace: true })
  }
  const q = useQuery({ queryKey: ['division-nights', date], queryFn: () => attendanceFollowupApi.divisionNights(date) })
  const nights = useMemo(() => q.data?.data ?? [], [q.data])
  const sessionId = Number(params.get('session')) || (nights.length === 1 ? nights[0].id : 0)
  const night = nights.find((n) => n.id === sessionId)
  const divisionId = Number(params.get('division')) || (night?.divisions.length === 1 ? night.divisions[0].id : 0)
  const division = night?.divisions.find((d) => d.id === divisionId)

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.division_attendance')} subtitle={t('division.subtitle')} />
      </div>
      <FilterBar label={t('date')}>
        <DateStepper id="division-att-date" label={t('date')} value={date} onChange={(v) => set({ date: v, session: null, division: null })} />
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : nights.length === 0 ? (
        <EmptyCard icon="students" title={t('division.no_nights')} body={t('division.no_nights_body')} />
      ) : (
        <>
          <FilterBar label={t('division.pick')}>
            <SelectField label={t('division.session')} className="sm:w-72" value={String(sessionId || '')} onChange={(e) => set({ session: e.target.value || null, division: null })}
              options={[{ value: '', label: '—' }, ...nights.map((n) => ({ value: String(n.id), label: `${n.lesson.name} (${formatTime(n.start_time, locale)})` }))]} />
            <SelectField label={t('division.division')} className="sm:w-56" value={String(divisionId || '')} disabled={!night} onChange={(e) => set({ division: e.target.value || null })}
              options={[{ value: '', label: '—' }, ...(night?.divisions ?? []).map((d) => ({ value: String(d.id), label: d.name }))]} />
          </FilterBar>
          {!night || !division ? <EmptyCard icon="attendance" title={t('division.pick')} /> : <Sheet key={`${division.id}-${night.id}`} divisionId={division.id} sessionId={night.id} canRecord={q.data!.can_record} />}
        </>
      )}
    </div>
  )
}

function Sheet({ divisionId, sessionId, canRecord }: { divisionId: number; sessionId: number; canRecord: boolean }) {
  const q = useQuery({ queryKey: ['division-sheet', divisionId, sessionId], queryFn: () => attendanceFollowupApi.divisionSheet(divisionId, sessionId) })
  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <QueryError error={q.error} onRetry={() => void q.refetch()} />
  // Remount on new server data, so the drafts start from what is saved.
  return <SheetForm key={q.dataUpdatedAt} data={q.data} canRecord={canRecord && q.data.can_record} />
}

type Draft = { status: AttendanceStatus | null; note: string }

function SheetForm({ data, canRecord }: { data: DivisionSheet; canRecord: boolean }) {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const sep = locale.startsWith('ar') ? '، ' : ', '
  const qc = useQueryClient()
  const [drafts, setDrafts] = useState<Record<number, Draft>>(() =>
    Object.fromEntries(data.data.map((r) => [r.student.id, { status: r.attendance?.status ?? null, note: r.attendance?.note ?? '' }])))
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const patch = (id: number, p: Partial<Draft>) => { setDrafts((d) => ({ ...d, [id]: { ...d[id], ...p } })); setNotice(null) }
  const marked = Object.values(drafts).filter((d) => d.status).length
  const recorded = data.data.filter((r) => r.attendance).length
  const save = useMutation({
    mutationFn: () => attendanceFollowupApi.saveDivision(data.division.id, data.session.id,
      Object.entries(drafts).filter(([, d]) => d.status).map(([id, d]) => ({ student_id: Number(id), status: d.status as AttendanceStatus, note: d.note || null }))),
    onSuccess: (r) => {
      setNotice({ tone: 'success', text: t('division.saved', { n: n(r.saved) }) })
      void qc.invalidateQueries({ queryKey: ['division-sheet', data.division.id, data.session.id] })
      void qc.invalidateQueries({ queryKey: ['division-nights'] })
      void qc.invalidateQueries({ queryKey: ['attendance-sheet', data.session.id] })
      void qc.invalidateQueries({ queryKey: ['sessions-on'] })
    },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const tally = STATUSES.map((s) => ({ s, count: data.data.filter((r) => r.attendance?.status === s).length }))

  return (
    <div className="space-y-4">
      <section className={`${SURFACE} space-y-3 p-4 sm:p-5`} aria-labelledby="division-sheet-title">
        <div className="flex flex-wrap items-start gap-3">
          <div className="min-w-0 flex-1">
            <h2 id="division-sheet-title" className="text-base font-semibold text-ink"><bdi>{data.division.name}</bdi></h2>
            <p className="text-sm text-ink/60">
              <bdi>{data.session.lesson.name}</bdi>{sep}<span className="tabular-nums">{formatTime(data.session.start_time, locale)}–{formatTime(data.session.end_time, locale)}</span>
              {data.session.location && <>{sep}<bdi>{data.session.location.name}</bdi></>}
            </p>
            {data.division.teacher && <p className="text-xs text-ink/55">{t('division.teacher')}: <bdi>{data.division.teacher.name}</bdi></p>}
          </div>
          <Badge tone={recorded === data.data.length && recorded > 0 ? 'brand' : 'gold'}>{t('division.recorded_of', { recorded: n(recorded), total: n(data.data.length) })}</Badge>
        </div>
        {/* The saved attendance of the division (the view). */}
        <ul className="flex flex-wrap gap-2" aria-label={t('division.saved_view')}>
          {tally.map(({ s, count }) => <li key={s}><Badge tone={STATUS_TONE[s]}>{t(`status.${s}`)} <span className="tabular-nums">{n(count)}</span></Badge></li>)}
          <li><Badge>{t('not_recorded')} <span className="tabular-nums">{n(data.data.length - recorded)}</span></Badge></li>
        </ul>
        <Link to={`/attendance/${data.session.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
          {t('division.class_sheet')}<Icon name="chevron" className="size-4 rtl:rotate-180" />
        </Link>
      </section>

      {!canRecord && <Notice tone="info">{t('read_only')}</Notice>}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      {data.data.length === 0 ? <EmptyCard icon="students" title={t('division.no_students')} /> : (
        <section className={SURFACE} aria-label={t('students')}>
          <ul className="divide-y divide-ink/6">
            {data.data.map((r) => {
              const d = drafts[r.student.id]
              return (
                <li key={r.student.id} className="space-y-2 px-4 py-3 sm:px-5">
                  <div className="flex flex-wrap items-center gap-3">
                    <div className="min-w-0 flex-[1_1_12rem]">
                      <Link to={`/students/${r.student.id}`} className="block truncate font-semibold text-ink hover:text-brand-700"><bdi>{r.student.full_name}</bdi></Link>
                      <p className="text-xs tabular-nums text-ink/50">{r.student.student_no}</p>
                    </div>
                    {canRecord ? (
                      <Segmented fill name={`div-status-${r.student.id}`} label={r.student.full_name} value={d.status} onChange={(v) => patch(r.student.id, { status: v })}
                        options={STATUSES.map((s) => ({ value: s, label: t(`status.${s}`), tone: STATUS_TONE[s] }))} />
                    ) : r.attendance ? <Badge tone={STATUS_TONE[r.attendance.status]}>{t(`status.${r.attendance.status}`)}</Badge> : <Badge>{t('not_recorded')}</Badge>}
                  </div>
                  {canRecord && d.status && d.status !== 'present' && (
                    <TextInput label={t('note')} hideLabel placeholder={t('note')} value={d.note} onChange={(e) => patch(r.student.id, { note: e.target.value })} dir="auto" />
                  )}
                  {!canRecord && r.attendance?.note && <p dir="auto" className="text-sm text-ink/60">{r.attendance.note}</p>}
                </li>
              )
            })}
          </ul>
          {canRecord && (
            <div className="flex flex-wrap items-center gap-3 border-t border-ink/6 px-4 py-3 sm:px-5">
              <span className="text-sm tabular-nums text-ink/60">{t('division.marked', { marked: n(marked), total: n(data.data.length) })}</span>
              <PrimaryButton className="ms-auto min-w-36" loading={save.isPending} disabled={marked === 0} onClick={() => save.mutate()}>{t('save')}</PrimaryButton>
            </div>
          )}
        </section>
      )}
    </div>
  )
}
