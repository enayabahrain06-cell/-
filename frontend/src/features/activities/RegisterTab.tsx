import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { activitiesApi, type Activity, type ActivityOptions, type Candidate, type Outcome } from '../../api/activities'
import { parseApiError } from '../../api/client'
import type { StudentSummary } from '../../api/students'
import SelectField from '../../components/SelectField'
import StudentPicker from '../../components/StudentPicker'
import { Badge, EmptyCard, IconButton, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, SURFACE, TABLE_HEAD, TableWrap, type Tone } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import type { CrudNotice } from '../common/crud'
import { REG_TONE, StudentCell } from './shared'

const OUTCOME_TONE: Record<Outcome, Tone> = { register: 'brand', waitlist: 'gold', already: 'info', refused: 'danger' }

/**
 * التسجيل في البرامج / التسجيل في الرحلة: pick students one by one or a whole class, see each one's eligibility
 * (seats, gender, age, level, active), register the eligible ones; below, the current registrations with
 * confirm (from the waiting list) and cancel.
 */
export default function RegisterTab({ activity, classes }: { activity: Activity; classes: ActivityOptions['classes'] }) {
  const { t, i18n } = useTranslation('activities')
  const n = (v: number) => formatNumber(v, i18n.language)
  const qc = useQueryClient()
  const [mode, setMode] = useState<'students' | 'class'>('students')
  const [lessonId, setLessonId] = useState('')
  const [picked, setPicked] = useState<StudentSummary[]>([])
  const [selected, setSelected] = useState<number[] | null>(null)
  const [chargeBook, setChargeBook] = useState(false)
  const [notice, setNotice] = useState<CrudNotice>(null)
  const ids = picked.map((s) => s.id)
  const check = useQuery({
    queryKey: ['activity-candidates', activity.id, mode, mode === 'class' ? lessonId : ids.join(',')],
    queryFn: () => activitiesApi.candidates(activity.id, mode === 'class' ? { lesson_id: Number(lessonId) } : { student_ids: ids }),
    enabled: mode === 'class' ? lessonId !== '' : ids.length > 0,
  })
  const rows: Candidate[] = check.data?.data ?? []
  const eligible = rows.filter((r) => r.outcome === 'register' || r.outcome === 'waitlist').map((r) => r.student.id)
  const chosen = selected ?? eligible
  const refresh = () => ['activity-roster', 'activity-candidates', 'activities', 'invoices', 'student-wallet'].forEach((k) => void qc.invalidateQueries({ queryKey: [k] }))
  const onError = (e: unknown) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) }
  const register = useMutation({
    mutationFn: () => activitiesApi.register(activity.id, { student_ids: chosen, charge_book: chargeBook }),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); setPicked([]); setSelected(null); refresh() },
    onError,
  })
  const toggle = (id: number) => setSelected(chosen.includes(id) ? chosen.filter((x) => x !== id) : [...chosen, id])

  return (
    <div className="space-y-4">
      {activity.status !== 'open' && <Notice tone="info">{t('not_open_notice')}</Notice>}
      <section className={`${SURFACE} space-y-4 p-4 sm:p-5`} aria-labelledby="reg-pick">
        <div className="flex flex-wrap items-center gap-3">
          <h2 id="reg-pick" className="flex-1 text-base font-semibold text-ink">{t('register.title')}</h2>
          <Segmented name="reg-mode" label={t('register.title')} value={mode} onChange={(v) => { setMode(v); setSelected(null) }}
            options={[{ value: 'students', label: t('register.by_student') }, { value: 'class', label: t('register.by_class') }]} />
        </div>
        {mode === 'class' ? (
          <SelectField label={t('class')} className="sm:w-80" value={lessonId} onChange={(e) => { setLessonId(e.target.value); setSelected(null) }}
            options={[{ value: '', label: '—' }, ...classes.map((c) => ({ value: String(c.id), label: c.level ? t('class_option', { name: c.name, level: c.level }) : c.name }))]} />
        ) : (
          <div className="space-y-2">
            <StudentPicker label={t('register.add_student')} value={null} gender={activity.gender === 'male' || activity.gender === 'female' ? activity.gender : undefined}
              onChange={(s) => { if (s && !ids.includes(s.id)) { setPicked([...picked, s]); setSelected(null) } }} />
          </div>
        )}
        {check.isFetching && !check.data ? <LoadingState /> : rows.length > 0 && (
          <>
            <TableWrap>
              <table className="relative w-full min-w-[34rem] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th scope="col" className="w-10 px-3 py-2"><span className="sr-only">{t('select')}</span></th>
                    <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
                    <th scope="col" className="px-3 py-2 text-start font-medium">{t('register.result')}</th>
                    {mode === 'students' && <th scope="col" className="px-3 py-2"><span className="sr-only">{t('remove')}</span></th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink/6">
                  {rows.map((r) => {
                    const can = r.outcome === 'register' || r.outcome === 'waitlist'
                    return (
                      <tr key={r.student.id} className={can ? '' : 'text-ink/60'}>
                        <td className="px-3 py-2">
                          {can && <input type="checkbox" aria-label={r.student.full_name} className="size-4 accent-brand-700" checked={chosen.includes(r.student.id)} onChange={() => toggle(r.student.id)} />}
                        </td>
                        <td className="px-3 py-2"><StudentCell s={r.student} /></td>
                        <td className="px-3 py-2">
                          <Badge tone={OUTCOME_TONE[r.outcome]}>{t(`outcome.${r.outcome}`)}</Badge>
                          {r.reason && r.outcome !== 'register' && <span className="ms-2 text-xs text-ink/60">{t(`reasons.${r.reason}`)}</span>}
                        </td>
                        {mode === 'students' && (
                          <td className="px-3 py-2 text-end"><IconButton icon="close" label={t('remove')} onClick={() => { setPicked(picked.filter((p) => p.id !== r.student.id)); setSelected(null) }} /></td>
                        )}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </TableWrap>
            <div className="flex flex-wrap items-center gap-3">
              {activity.has_book && activity.book_price_fils > 0 && (
                <label className="flex items-center gap-2 text-sm text-ink/80">
                  <input type="checkbox" className="size-4 accent-brand-700" checked={chargeBook} onChange={(e) => setChargeBook(e.target.checked)} />
                  {t('register.charge_book')}
                </label>
              )}
              <PrimaryButton className="ms-auto" disabled={chosen.length === 0 || activity.status !== 'open'} loading={register.isPending} onClick={() => register.mutate()}>
                {t('register.submit', { n: n(chosen.length) })}
              </PrimaryButton>
            </div>
            {activity.price_fils > 0 && <p className="text-xs text-ink/50">{t('register.fee_note')}</p>}
          </>
        )}
      </section>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <Registrations activity={activity} onNotice={setNotice} onError={onError} refresh={refresh} />
    </div>
  )
}

function Registrations({ activity, onNotice, onError, refresh }: { activity: Activity; onNotice: (n: CrudNotice) => void; onError: (e: unknown) => void; refresh: () => void }) {
  const { t, i18n } = useTranslation('activities')
  const n = (v: number) => formatNumber(v, i18n.language)
  const q = useQuery({ queryKey: ['activity-roster', activity.id], queryFn: () => activitiesApi.roster(activity.id) })
  const act = useMutation({
    mutationFn: ({ id, kind }: { id: number; kind: 'confirm' | 'cancel' }) => (kind === 'confirm' ? activitiesApi.confirm(activity.id, id) : activitiesApi.cancel(activity.id, id)),
    onSuccess: (r) => { onNotice({ tone: 'success', text: r.message }); refresh() },
    onError,
  })
  if (q.isLoading) return <LoadingState />
  const rows = q.data?.data ?? []

  return (
    <section className={`${SURFACE} space-y-3 p-4 sm:p-5`} aria-labelledby="reg-current">
      <div className="flex flex-wrap items-center gap-2">
        <h2 id="reg-current" className="flex-1 text-base font-semibold text-ink">{t('register.current')}</h2>
        {q.data && <Badge tone="brand">{t('registered')} <span className="tabular-nums">{n(q.data.totals.registered)}</span></Badge>}
        {q.data && q.data.totals.waitlist > 0 && <Badge tone="gold">{t('waitlist')} <span className="tabular-nums">{n(q.data.totals.waitlist)}</span></Badge>}
        {q.data?.totals.seats_left !== null && q.data?.totals.seats_left !== undefined && <Badge>{t('seats_left', { n: n(q.data.totals.seats_left) })}</Badge>}
      </div>
      {rows.length === 0 ? <EmptyCard icon="students" title={t('no_registrations')} /> : (
        <ul className="divide-y divide-ink/6">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 py-2">
              <div className="min-w-0 flex-[1_1_12rem]"><StudentCell s={r.student} /></div>
              <Badge tone={REG_TONE[r.status]}>{t(`reg_status.${r.status}`)}</Badge>
              <div className="flex gap-2">
                {r.status === 'waitlist' && <SecondaryButton className="py-1" disabled={act.isPending} onClick={() => act.mutate({ id: r.id, kind: 'confirm' })}>{t('confirm')}</SecondaryButton>}
                <SecondaryButton className="py-1 text-danger" disabled={act.isPending} onClick={() => { if (window.confirm(t('cancel_confirm'))) act.mutate({ id: r.id, kind: 'cancel' }) }}>{t('cancel_registration')}</SecondaryButton>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
