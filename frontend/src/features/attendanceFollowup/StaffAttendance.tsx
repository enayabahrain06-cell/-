import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceFollowupApi, type StaffDay, type StaffDayRow, type StaffKind, type StaffStatus, type StaffSummaryRow } from '../../api/attendanceFollowup'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, FilterBar, IconButton, LoadingState, Modal, Notice, PrimaryButton, SURFACE, SecondaryButton, Segmented, TABLE_HEAD, TableWrap, TextInput } from '../../components/ui'
import { formatDate, formatNumber, formatPercent, formatTime } from '../../lib/format'
import { DateStepper, QueryError, STATUS_TONE, todayIso } from './shared'

const STATUSES: StaffStatus[] = ['present', 'late', 'absent', 'excused']
const TIMED = (s: StaffStatus | null) => s === 'present' || s === 'late'

// ── One night: record ──────────────────────────────────────────────────────

/** The expected staff of the night (and anyone added), with status, arrival and leaving times and a note. */
export function StaffDaySheet({ kind }: { kind: StaffKind }) {
  const { t } = useTranslation('attendanceFollowup')
  const [params, setParams] = useSearchParams()
  const date = params.get('date') ?? todayIso()
  const q = useQuery({ queryKey: ['staff-day', kind, date], queryFn: () => attendanceFollowupApi.staffDay(kind, date) })

  return (
    <div className="space-y-4">
      <FilterBar label={t('date')}>
        <DateStepper id={`staff-${kind}-date`} label={t('date')} value={date} onChange={(v) => { const p = new URLSearchParams(params); p.set('date', v); setParams(p, { replace: true }) }} />
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : <DayForm key={q.dataUpdatedAt} data={q.data} />}
    </div>
  )
}

type Draft = { status: StaffStatus | null; check_in: string; check_out: string; notes: string }
const draftOf = (r: StaffDayRow): Draft => ({ status: r.record?.status ?? null, check_in: r.record?.check_in ?? '', check_out: r.record?.check_out ?? '', notes: r.record?.notes ?? '' })

function DayForm({ data }: { data: StaffDay }) {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const qc = useQueryClient()
  const [rows, setRows] = useState<StaffDayRow[]>(data.data)
  const [drafts, setDrafts] = useState<Record<number, Draft>>(() => Object.fromEntries(data.data.map((r) => [r.user.id, draftOf(r)])))
  const [adding, setAdding] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const canRecord = data.can_record && data.in_term
  const patch = (id: number, p: Partial<Draft>) => { setDrafts((d) => ({ ...d, [id]: { ...d[id], ...p } })); setNotice(null) }
  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ['staff-day', data.kind] })
    void qc.invalidateQueries({ queryKey: ['staff-summary', data.kind] })
    void qc.invalidateQueries({ queryKey: ['staff-detail', data.kind] })
  }
  const save = useMutation({
    mutationFn: () => attendanceFollowupApi.saveStaffDay({
      academic_term_id: data.term.id, date: data.date, kind: data.kind,
      records: Object.entries(drafts).filter(([, d]) => d.status).map(([id, d]) => ({
        user_id: Number(id), status: d.status as StaffStatus,
        check_in: TIMED(d.status) && d.check_in ? d.check_in : null, check_out: TIMED(d.status) && d.check_out ? d.check_out : null, notes: d.notes || null,
      })),
    }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); invalidate() },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  const remove = useMutation({
    mutationFn: (id: number) => attendanceFollowupApi.removeStaff(id),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); invalidate() },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const candidates = data.candidates.filter((c) => !rows.some((r) => r.user.id === c.id))
  const add = () => {
    const c = candidates.find((x) => String(x.id) === adding)
    if (!c) return
    setRows((r) => [...r, { user: { id: c.id, name: c.name, phone: null }, expected: false, record: null, ...(data.kind === 'teacher' ? { sessions: [], took_attendance: { taken: 0, total: 0 } } : {}) }])
    setDrafts((d) => ({ ...d, [c.id]: { status: 'present', check_in: '', check_out: '', notes: '' } }))
    setAdding('')
  }
  const marked = Object.values(drafts).filter((d) => d.status).length
  const expected = rows.filter((r) => r.expected).length

  return (
    <div className="space-y-4">
      {!data.in_term && <Notice tone="info">{t('staff.outside_term', { term: data.term.name })}</Notice>}
      {data.in_term && !data.can_record && <Notice tone="info">{t('read_only')}</Notice>}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <p className="text-sm text-ink/60">{t(`staff.expected_${data.kind}`, { n: n(expected) })}</p>

      {rows.length === 0 ? <EmptyCard icon="teachers" title={t(`staff.none_${data.kind}`)} body={t(`staff.none_${data.kind}_body`)} /> : (
        <section className={SURFACE} aria-label={t(`staff.list_${data.kind}`)}>
          <ul className="divide-y divide-ink/6">
            {rows.map((r) => {
              const d = drafts[r.user.id]
              return (
                <li key={r.user.id} className="space-y-3 px-4 py-3 sm:px-5">
                  <div className="flex flex-wrap items-center gap-3">
                    <div className="min-w-0 flex-[1_1_12rem]">
                      <p className="flex flex-wrap items-center gap-2">
                        <bdi className="font-semibold text-ink">{r.user.name}</bdi>
                        {!r.expected && <Badge tone="info">{t('staff.added')}</Badge>}
                      </p>
                      {r.took_attendance && r.took_attendance.total > 0 && (
                        <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink/60">
                          <Badge tone={r.took_attendance.taken === r.took_attendance.total ? 'brand' : 'gold'}>
                            {t('staff.took', { taken: n(r.took_attendance.taken), total: n(r.took_attendance.total) })}
                          </Badge>
                          {r.sessions?.map((s) => <span key={s.id}><bdi>{s.lesson.name}</bdi> <span className="tabular-nums">({formatTime(s.start_time, locale)})</span></span>)}
                        </p>
                      )}
                    </div>
                    {canRecord ? (
                      <div className="flex w-full items-center gap-2 sm:w-auto">
                        <Segmented fill name={`staff-${r.user.id}`} label={r.user.name} value={d.status} onChange={(v) => patch(r.user.id, { status: v })}
                          options={STATUSES.map((s) => ({ value: s, label: t(`status.${s}`), tone: STATUS_TONE[s] }))} />
                        {r.record && <IconButton icon="trash" tone="danger" label={t('staff.remove')} onClick={() => { if (window.confirm(t('staff.remove_confirm'))) remove.mutate(r.record!.id) }} />}
                      </div>
                    ) : r.record ? <Badge tone={STATUS_TONE[r.record.status]}>{t(`status.${r.record.status}`)}</Badge> : <Badge>{t('not_recorded')}</Badge>}
                  </div>
                  {canRecord && d.status && (
                    <div className="grid gap-3 rounded-xl bg-page/70 p-3 sm:grid-cols-3">
                      {TIMED(d.status) && <TextInput label={t('staff.check_in')} type="time" value={d.check_in} onChange={(e) => patch(r.user.id, { check_in: e.target.value })} />}
                      {TIMED(d.status) && <TextInput label={t('staff.check_out')} type="time" value={d.check_out} onChange={(e) => patch(r.user.id, { check_out: e.target.value })} />}
                      <TextInput label={t('note')} value={d.notes} onChange={(e) => patch(r.user.id, { notes: e.target.value })} dir="auto" />
                    </div>
                  )}
                  {!canRecord && r.record && (r.record.check_in || r.record.notes) && (
                    <p className="text-sm text-ink/60">
                      {r.record.check_in && <span className="tabular-nums">{formatTime(r.record.check_in, locale)}{r.record.check_out && <>–{formatTime(r.record.check_out, locale)}</>}</span>}
                      {r.record.notes && <> <bdi>{r.record.notes}</bdi></>}
                    </p>
                  )}
                </li>
              )
            })}
          </ul>
        </section>
      )}

      {canRecord && (
        <div className={`${SURFACE} flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end`}>
          {candidates.length > 0 && (
            <>
              <SelectField label={t(`staff.add_${data.kind}`)} className="sm:w-64" value={adding} onChange={(e) => setAdding(e.target.value)}
                options={[{ value: '', label: '—' }, ...candidates.map((c) => ({ value: String(c.id), label: c.name }))]} />
              <SecondaryButton disabled={!adding} onClick={add}><Icon name="plus" className="size-4" />{t('staff.add')}</SecondaryButton>
            </>
          )}
          <PrimaryButton className="min-w-36 sm:ms-auto" loading={save.isPending} disabled={marked === 0} onClick={() => save.mutate()}>{t('save')}</PrimaryButton>
        </div>
      )}
    </div>
  )
}

// ── The term: view ─────────────────────────────────────────────────────────

/** Per staff member: nights expected, attended, the statuses and the rate; a row opens the nights of the person. */
export function StaffSummary({ kind }: { kind: StaffKind }) {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [month, setMonth] = useState('')
  const [open, setOpen] = useState<StaffSummaryRow | null>(null)
  const q = useQuery({ queryKey: ['staff-summary', kind, month], queryFn: () => attendanceFollowupApi.staffSummary(kind, month), placeholderData: keepPreviousData })

  return (
    <div className="space-y-4">
      <FilterBar label={t('staff.month')}>
        <TextInput label={t('staff.month')} type="month" className="sm:w-48" value={month} onChange={(e) => setMonth(e.target.value)} />
        {month && <SecondaryButton onClick={() => setMonth('')}>{t('staff.whole_term')}</SecondaryButton>}
        {q.data && <p className="text-sm text-ink/60 sm:ms-auto sm:self-center">{t('staff.range', { from: formatDate(q.data.from, locale), to: formatDate(q.data.to, locale) })}</p>}
      </FilterBar>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyCard icon="teachers" title={t(`staff.none_${kind}`)} body={t(`staff.none_${kind}_body`)} />
      ) : (
        <TableWrap surface>
          <table className="w-full min-w-[44rem] text-sm">
            <thead className={TABLE_HEAD}>
              <tr>
                <th scope="col" className="px-4 py-3 text-start font-medium">{t(`staff.person_${kind}`)}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('staff.expected')}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('staff.attended')}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('status.late')}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('status.absent')}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('status.excused')}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('not_recorded')}</th>
                <th scope="col" className="px-3 py-3 text-center font-medium">{t('staff.rate')}</th>
                {kind === 'teacher' && <th scope="col" className="px-3 py-3 text-center font-medium">{t('staff.took_col')}</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-ink/6">
              {q.data.data.map((r) => (
                <tr key={r.user.id}>
                  <td className="px-4 py-3">
                    <button type="button" className="whitespace-nowrap text-start font-medium text-ink hover:text-brand-700 hover:underline" onClick={() => setOpen(r)}><bdi>{r.user.name}</bdi></button>
                  </td>
                  <td className="px-3 py-3 text-center tabular-nums">{n(r.expected)}</td>
                  <td className="px-3 py-3 text-center tabular-nums">{n(r.attended)}</td>
                  <td className="px-3 py-3 text-center tabular-nums">{n(r.late)}</td>
                  <td className="px-3 py-3 text-center tabular-nums">{n(r.absent)}</td>
                  <td className="px-3 py-3 text-center tabular-nums">{n(r.excused)}</td>
                  <td className="px-3 py-3 text-center tabular-nums">{r.not_recorded > 0 ? <Badge tone="gold">{n(r.not_recorded)}</Badge> : n(0)}</td>
                  <td className="px-3 py-3 text-center tabular-nums">
                    {r.rate === null ? '—' : <Badge tone={r.rate >= 90 ? 'brand' : r.rate >= 70 ? 'gold' : 'danger'}>{formatPercent(Math.round(r.rate), locale)}</Badge>}
                  </td>
                  {kind === 'teacher' && <td className="px-3 py-3 text-center tabular-nums">{r.sessions ? t('staff.took', { taken: n(r.sessions.taken), total: n(r.sessions.total) }) : '—'}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </TableWrap>
      )}
      {open && <DetailModal kind={kind} userId={open.user.id} name={open.user.name} month={month} onClose={() => setOpen(null)} />}
    </div>
  )
}

function DetailModal({ kind, userId, name, month, onClose }: { kind: StaffKind; userId: number; name: string; month: string; onClose: () => void }) {
  const { t, i18n } = useTranslation('attendanceFollowup')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const q = useQuery({ queryKey: ['staff-detail', kind, userId, month], queryFn: () => attendanceFollowupApi.staffDetail(kind, userId, month) })
  return (
    <Modal title={name} onClose={onClose} wide>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <QueryError error={q.error} onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <p className="text-sm text-ink/60">{t('staff.no_nights')}</p>
      ) : (
        <ul className="divide-y divide-ink/6">
          {q.data.data.map((d) => (
            <li key={d.date} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
              <span className="min-w-0 flex-[1_1_10rem] text-sm text-ink">{formatDate(d.date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
              {!d.expected && <Badge tone="info">{t('staff.added')}</Badge>}
              {d.record ? <Badge tone={STATUS_TONE[d.record.status]}>{t(`status.${d.record.status}`)}</Badge> : <Badge tone="gold">{t('not_recorded')}</Badge>}
              {d.record?.check_in && <span className="text-xs tabular-nums text-ink/60">{formatTime(d.record.check_in, locale)}{d.record.check_out && <>–{formatTime(d.record.check_out, locale)}</>}</span>}
              {d.took_attendance && d.took_attendance.total > 0 && <span className="text-xs text-ink/60">{t('staff.took', { taken: n(d.took_attendance.taken), total: n(d.took_attendance.total) })}</span>}
              {d.record?.notes && <p dir="auto" className="basis-full text-xs text-ink/55">{d.record.notes}</p>}
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}
