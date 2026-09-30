import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { attendanceApi, type AttendanceStatus, type RosterRow, type SaveResult } from '../../api/attendance'
import { parseApiError } from '../../api/client'
import { attendanceMessagingApi } from '../../api/messages'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import { ErrorState, LoadingState, Modal, Notice, PrimaryButton, Segmented, SecondaryButton, buttonClass, type Tone } from '../../components/ui'
import { formatDate, formatNumber, formatTime } from '../../lib/format'
import { printAttendance, shareAttendance } from './attendanceShare'

const STATUSES: { value: AttendanceStatus; tone: Tone }[] = [
  { value: 'present', tone: 'brand' },
  { value: 'late', tone: 'gold' },
  { value: 'absent', tone: 'danger' },
  { value: 'excused', tone: 'info' },
]

/**
 * Quick attendance in a popup: one status per student, "تحضير الجميع" fills the unmarked ones, one save.
 * Assignments, notes and the progress ledger stay on the full sheet (/attendance/:id); the values already
 * recorded there are sent back unchanged, so a quick save never clears them. After the save the sheet can be
 * printed, shared, and sent to the guardians (each gets their child's status; absent ones get the absence notice).
 */
export default function QuickAttendanceDialog({ sessionId, onClose }: { sessionId: number; onClose: () => void }) {
  const { t, i18n } = useTranslation('attendance')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const qc = useQueryClient()
  const { can } = useAuth()
  // Same query key as the full sheet, so the two share one cache entry.
  const q = useQuery({ queryKey: ['attendance-sheet', sessionId], queryFn: () => attendanceApi.roster(sessionId) })
  const roster = useMemo(() => q.data?.roster ?? [], [q.data])

  // The user's picks on top of what is already recorded.
  const [edits, setEdits] = useState<Record<number, AttendanceStatus>>({})
  const [dirty, setDirty] = useState(false)
  const [result, setResult] = useState<SaveResult | null>(null)
  const status = useMemo(() => Object.fromEntries(roster.map((r) => [r.student.id, edits[r.student.id] ?? r.attendance?.status ?? null])) as Record<number, AttendanceStatus | null>, [roster, edits])

  const set = (id: number, v: AttendanceStatus) => { setEdits((e) => ({ ...e, [id]: v })); setDirty(true) }
  const fillPresent = () => { setEdits((e) => ({ ...e, ...Object.fromEntries(roster.filter((r) => !status[r.student.id]).map((r) => [r.student.id, 'present' as const])) })); setDirty(true) }
  const marked = Object.values(status).filter(Boolean).length

  const save = useMutation({
    mutationFn: () => attendanceApi.save(sessionId, roster.filter((r) => status[r.student.id]).map((r: RosterRow) => ({
      student_id: r.student.id,
      status: status[r.student.id] as AttendanceStatus,
      memorization_assignment: r.attendance?.memorization_assignment || null,
      revision_assignment: r.attendance?.revision_assignment || null,
      note: r.attendance?.note || null,
    }))),
    onSuccess: (r) => {
      setResult(r)
      setDirty(false)
      void qc.invalidateQueries({ queryKey: ['attendance-sheet', sessionId] })
      void qc.invalidateQueries({ queryKey: ['sessions-on'] })
      void qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  const send = useMutation({ mutationFn: () => attendanceMessagingApi.sendResults(sessionId) })

  const close = () => { if (!dirty || window.confirm(t('unsaved'))) onClose() }
  const s = q.data?.session

  const footer = result ? (
    <button type="button" onClick={onClose} className={buttonClass('primary')}>{t('common:close')}</button>
  ) : (
    <>
      <Link to={`/attendance/${sessionId}`} onClick={onClose} className={buttonClass('secondary', 'me-auto')}>
        <Icon name="edit" className="size-4" />{t('open_sheet')}
      </Link>
      <SecondaryButton onClick={close}>{t('common:close')}</SecondaryButton>
      <PrimaryButton loading={save.isPending} disabled={marked === 0 || !q.data} onClick={() => save.mutate()}>
        {save.isPending ? t('saving') : t('save')}
      </PrimaryButton>
    </>
  )

  return (
    <Modal title={s?.lesson?.name ?? t('sheet_title')} onClose={close} footer={footer} wide>
      {q.isLoading ? <LoadingState /> : q.isError || !s ? <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} /> : result ? (
        <>
          <Notice>
            {t('saved', { saved: n(result.saved) })}
            {result.absent > 0 && <> {t('absence_queued', { n: n(result.absent) })}</>}
            {result.repeated_absence_alerts.length > 0 && <> {t('repeated_alerts', { n: n(result.repeated_absence_alerts.length) })}</>}
          </Notice>
          {send.isSuccess && <Notice>{send.data.message}</Notice>}
          {send.isError && <Notice tone="error">{parseApiError(send.error).message}</Notice>}
          <div className="grid gap-2 sm:grid-cols-2">
            <SecondaryButton onClick={() => printAttendance(t, locale, i18n.dir(), s, roster, status)}>
              <Icon name="printer" className="size-4" />{t('quick.print')}
            </SecondaryButton>
            <SecondaryButton onClick={() => shareAttendance(t, locale, s, roster, status)}>
              <Icon name="share" className="size-4" />{t('quick.share')}
            </SecondaryButton>
            {can('messages.send') && (
              <SecondaryButton disabled={send.isPending || send.isSuccess} onClick={() => { if (window.confirm(t('quick.send_results_confirm'))) send.mutate() }}>
                <Icon name="messages" className="size-4" />{t('quick.send_results')}
              </SecondaryButton>
            )}
            {can('messages.view') && (
              <Link to={`/messages/sessions/${sessionId}`} onClick={onClose} className={buttonClass('secondary')}>
                <Icon name="eye" className="size-4" />{t('quick.view_messages')}
              </Link>
            )}
          </div>
        </>
      ) : (
        <>
          <p className="flex flex-wrap gap-x-3 gap-y-1 text-sm text-ink/60">
            <span>{formatDate(s.session_date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
            <span className="tabular-nums">{formatTime(s.start_time, locale)}–{formatTime(s.end_time, locale)}</span>
            {s.location && <span dir="auto">{s.location.name}</span>}
          </p>
          {save.isError && <Notice tone="error">{parseApiError(save.error).message}</Notice>}
          {roster.length === 0 ? <Notice tone="info">{t('empty_roster')}</Notice> : (
            <>
              <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-semibold tabular-nums text-ink">{t('counts', { marked: n(marked), total: n(roster.length) })}</span>
                <SecondaryButton className="ms-auto" disabled={marked === roster.length} onClick={fillPresent}>
                  <Icon name="check" className="size-4" />{t('mark_all_present')}
                </SecondaryButton>
              </div>
              <ul className="divide-y divide-ink/6 rounded-xl border border-ink/8">
                {roster.map((r) => (
                  <li key={r.student.id} className={`flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5 ${status[r.student.id] === 'absent' ? 'bg-danger/5' : ''}`}>
                    <Avatar name={r.student.full_name} initial={r.student.initial} src={r.student.photo_url} gender={r.student.gender} size="sm" />
                    <span dir="auto" className="min-w-0 flex-[1_1_8rem] truncate font-medium text-ink">{r.student.full_name}</span>
                    <Segmented fill size="sm" name={`quick-${r.student.id}`} label={r.student.full_name} value={status[r.student.id] ?? null}
                      options={STATUSES.map((o) => ({ value: o.value, label: t(`status.${o.value}`), tone: o.tone }))}
                      onChange={(v) => set(r.student.id, v)} />
                  </li>
                ))}
              </ul>
            </>
          )}
        </>
      )}
    </Modal>
  )
}
