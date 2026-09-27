import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { studentsApi, type StudentDetail, type StudentProfile } from '../../../api/students'
import { parseApiError } from '../../../api/client'
import Alert from '../../../components/Alert'
import Icon from '../../../components/Icon'
import Pagination from '../../../components/Pagination'
import SelectField from '../../../components/SelectField'
import FormField from '../../../components/FormField'
import { EmptyState, StarSpinner } from '../../../components/ornaments'
import { PrimaryButton, SURFACE, inputClass } from '../../../components/ui'
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../../lib/format'
import JuzMap from './JuzMap'
import TrendChart from './TrendChart'

const card = `${SURFACE} p-4 sm:p-5`

function Loading() {
  return <div className="grid place-items-center py-16"><StarSpinner className="size-9 text-brand-600" /></div>
}

function Stat({ value, label }: { value: string; label: string }) {
  return (
    <div className={`${SURFACE} px-4 py-3`}>
      <p className="text-2xl font-semibold tabular-nums text-ink">{value}</p>
      <p className="text-sm text-ink/60">{label}</p>
    </div>
  )
}

/* ---------------------------------------------------------------- Memorization */
export function OverviewTab({ profile }: { profile: StudentProfile }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const p = profile.progress
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat value={formatNumber(p.quran_percent / 100, locale, { style: 'percent', maximumFractionDigits: 1 })} label={t('progress.quran')} />
        <Stat value={n(p.memorized_ayahs)} label={t('progress.memorized_ayahs')} />
        <Stat value={n(p.completed_juz)} label={t('progress.completed_juz')} />
        <div className={`${SURFACE} px-4 py-3`}>
          <p className="text-2xl font-semibold tabular-nums text-ink">{p.plan.percent === null ? '—' : formatPercent(p.plan.percent, locale)}</p>
          <p className="text-sm text-ink/60">{p.plan.target_ayahs ? t('progress.plan') : t('progress.no_plan')}</p>
          {p.plan.target_ayahs > 0 && (
            <>
              <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-ink/8" role="progressbar" aria-valuenow={p.plan.percent ?? 0} aria-valuemin={0} aria-valuemax={100}>
                <div className="h-full rounded-full bg-gold-500" style={{ width: `${p.plan.percent ?? 0}%` }} />
              </div>
              <p className="mt-1 text-xs text-ink/50">{t('progress.plan_detail', { done: n(p.plan.memorized_in_period), target: n(p.plan.target_ayahs), since: formatDate(p.plan.period_start, locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}</p>
            </>
          )}
        </div>
      </div>

      <section className={card} aria-labelledby="juz-map-title">
        <h3 id="juz-map-title" className="mb-3 font-semibold text-ink">{t('juz_map.title')}</h3>
        <JuzMap cells={p.juz_map} />
      </section>

      <section className={card} aria-labelledby="recent-title">
        <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h3 id="recent-title" className="font-semibold text-ink">{t('progress.recent')}</h3>
          <p className="text-sm text-ink/55">{n(p.revised_ayahs_30d)} {t('progress.revised_30d')}</p>
        </div>
        {p.recent.length === 0 ? (
          <EmptyState size="sm" icon="lessons" title={t('progress.recent_empty')} />
        ) : (
          <ul className="divide-y divide-ink/6">
            {p.recent.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                <span className="inline-flex items-center gap-2">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${r.type === 'memorized' ? 'bg-brand-50 text-brand-700' : 'bg-gold-500/12 text-gold-700'}`}>{r.type_label}</span>
                  <span className="text-ink">{t('progress.range', { surah: r.surah_name, from: n(r.from_ayah), to: n(r.to_ayah) })}</span>
                </span>
                <span className="text-ink/50">{formatDate(r.recorded_on, locale, { day: 'numeric', month: 'short' })}</span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

/* ---------------------------------------------------------------- Evaluation */
const CRITERIA = ['memorization', 'tajweed', 'revision', 'behavior'] as const

export function EvaluationTab({ profile }: { profile: StudentProfile }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const e = profile.evaluation
  const n1 = (v: number | null) => (v === null ? '—' : formatNumber(v, locale, { maximumFractionDigits: 1 }))

  return (
    <div className="space-y-5">
      <div className="grid gap-5 lg:grid-cols-3">
        <section className={card}>
          <h3 className="text-sm text-ink/60">{t('evaluation.rank')}</h3>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-ink">
            {e.rank?.rank ? t('evaluation.rank_value', { rank: formatNumber(e.rank.rank, locale), of: formatNumber(e.rank.of, locale) }) : '—'}
          </p>
          {e.rank?.average != null && <p className="mt-1 text-sm text-ink/55">{t('evaluation.average', { avg: n1(e.rank.average) })}</p>}
        </section>
        <section className={`${card} lg:col-span-2`}>
          <h3 className="text-sm text-ink/60">{t('evaluation.latest_note')}</h3>
          {e.latest_note ? (
            <blockquote className="mt-2">
              <p dir="auto" className="text-ink">{e.latest_note.note}</p>
              <footer className="mt-1 text-sm text-ink/50">{e.latest_note.teacher} · {formatDate(e.latest_note.date, locale, { day: 'numeric', month: 'long' })}</footer>
            </blockquote>
          ) : (
            <p className="mt-2 text-ink/45">—</p>
          )}
        </section>
      </div>

      <TrendChart weeks={e.trend} />

      <div className="grid gap-5 lg:grid-cols-2">
        <section className={card} aria-labelledby="latest-title">
          <h3 id="latest-title" className="mb-3 font-semibold text-ink">{t('evaluation.latest')}</h3>
          {e.latest_daily.length === 0 ? (
            <EmptyState size="sm" icon="evaluation" title={t('evaluation.empty')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[26rem] text-sm">
                <thead><tr className="border-b border-ink/10 text-ink/55">
                  <th className="py-2 text-start font-medium" />
                  {CRITERIA.map((c) => <th key={c} className="py-2 text-end font-medium">{t(`evaluation.criteria.${c}`)}</th>)}
                  <th className="py-2 text-end font-medium">{t('evaluation.total')}</th>
                </tr></thead>
                <tbody>
                  {e.latest_daily.map((r) => (
                    <tr key={r.id} className="border-b border-ink/5 last:border-0">
                      <td className="py-2 text-ink/70">{formatDate(r.date, locale, { day: 'numeric', month: 'short' })}</td>
                      {CRITERIA.map((c) => <td key={c} className={`py-2 text-end tabular-nums ${r[c] < 6 ? 'font-semibold text-danger' : 'text-ink'}`}>{formatNumber(r[c], locale)}</td>)}
                      <td className="py-2 text-end font-semibold tabular-nums text-ink">{formatNumber(r.total, locale)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className={card} aria-labelledby="monthly-title">
          <h3 id="monthly-title" className="mb-3 font-semibold text-ink">{t('evaluation.monthly')}</h3>
          {e.monthly_averages.length === 0 ? (
            <EmptyState size="sm" icon="evaluation" title={t('evaluation.empty')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[26rem] text-sm">
                <thead><tr className="border-b border-ink/10 text-ink/55">
                  <th className="py-2 text-start font-medium" />
                  {CRITERIA.map((c) => <th key={c} className="py-2 text-end font-medium">{t(`evaluation.criteria.${c}`)}</th>)}
                </tr></thead>
                <tbody>
                  {e.monthly_averages.map((m) => (
                    <tr key={m.period} className="border-b border-ink/5 last:border-0">
                      <td className="py-2 text-ink/70">{formatDate(`${m.period}-15`, locale, { month: 'long', year: 'numeric' })}</td>
                      {CRITERIA.map((c) => <td key={c} className="py-2 text-end tabular-nums">{n1(m[c])}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </div>
  )
}

/* ---------------------------------------------------------------- Difficulties */
const SEVERITY: Record<string, string> = { high: 'bg-danger/10 text-danger', medium: 'bg-gold-500/12 text-gold-700', low: 'bg-ink/6 text-ink/65' }

export function IssuesTab({ profile }: { profile: StudentProfile }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language

  if (profile.issues.length === 0) {
    return <div className={card}><EmptyState icon="check" title={t('issues.empty')} body={t('issues.empty_body')} /></div>
  }

  return (
    <ul className="space-y-3">
      {profile.issues.map((i) => (
        <li key={i.id} className={card}>
          <div className="flex flex-wrap items-center gap-2">
            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${SEVERITY[i.severity]}`}>{i.severity_label}</span>
            <span className="font-semibold text-ink">{i.category_label}{i.subcategory_label && <> · {i.subcategory_label}</>}</span>
            <span className="ms-auto rounded-full bg-brand-50 px-2.5 py-0.5 text-xs text-brand-700">{i.status_label}</span>
          </div>
          <p dir="auto" className="mt-2 text-ink/85">{i.description}</p>
          {i.action_plan && (
            <div className="mt-3 rounded-xl border-s-4 border-gold-500 bg-gold-500/6 px-3 py-2">
              <p className="text-xs font-semibold text-gold-700">{t('issues.action_plan')}</p>
              <p dir="auto" className="text-sm text-ink/85">{i.action_plan}</p>
            </div>
          )}
          <p className="mt-2 text-xs text-ink/50">
            {i.opened_at && t('issues.opened', { date: formatDate(i.opened_at, locale, { day: 'numeric', month: 'short' }), by: i.opened_by ?? '—' })}
            {i.next_follow_up_date && <> · {t('issues.follow_up', { date: formatDate(i.next_follow_up_date, locale, { day: 'numeric', month: 'short' }) })}</>}
          </p>
          {i.notes.length > 0 && (
            <div className="mt-3 border-t border-ink/6 pt-2">
              <p className="mb-1 text-xs font-semibold text-ink/55">{t('issues.notes')}</p>
              <ul className="space-y-1 text-sm">
                {i.notes.map((note) => (
                  <li key={note.id} className="text-ink/80"><span className="text-ink/45">{formatDate(note.noted_on, locale, { day: 'numeric', month: 'short' })} · </span><span dir="auto">{note.note}</span></li>
                ))}
              </ul>
            </div>
          )}
        </li>
      ))}
    </ul>
  )
}

/* ---------------------------------------------------------------- Attendance */
const ATT_STYLE: Record<string, string> = { present: 'bg-brand-50 text-brand-700', late: 'bg-gold-500/12 text-gold-700', absent: 'bg-danger/10 text-danger', excused: 'bg-info/10 text-info-700' }

export function AttendanceTab({ studentId }: { studentId: number }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const q = useQuery({ queryKey: ['student-attendance', studentId, page, status], queryFn: () => studentsApi.attendance(studentId, page, status || undefined) })

  if (q.isLoading || !q.data) return <Loading />
  const tot = q.data.totals

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <Stat value={formatPercent(tot.percent, locale)} label={t('attendance.percent')} />
        {(['present', 'late', 'absent', 'excused'] as const).map((k) => <Stat key={k} value={formatNumber(tot[k], locale)} label={t(`attendance.${k}`)} />)}
      </div>
      <section className={card}>
        <SelectField label={t('filters.status')} hideLabel className="mb-3 w-44" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          options={[{ value: '', label: t('attendance.all') }, ...(['present', 'late', 'absent', 'excused'] as const).map((k) => ({ value: k, label: t(`attendance.${k}`) }))]} />
        {q.data.data.length === 0 ? (
          <EmptyState size="sm" icon="attendance" title={t('attendance.empty')} />
        ) : (
          <ul className="divide-y divide-ink/6">
            {q.data.data.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                <span className="w-28 text-ink/70">{formatDate(a.date, locale, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${ATT_STYLE[a.status]}`}>{t(`attendance.${a.status}`)}</span>
                <span dir="auto" className="text-ink/60">{a.lesson}</span>
                {a.memorization_assignment && <span dir="auto" className="basis-full text-xs text-ink/50">{t('attendance.assignment', { text: a.memorization_assignment })}</span>}
              </li>
            ))}
          </ul>
        )}
        <div className="mt-3"><Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} /></div>
      </section>
    </div>
  )
}

/* ---------------------------------------------------------------- Wallet */
export function WalletTab({ studentId }: { studentId: number }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['student-wallet', studentId], queryFn: () => studentsApi.wallet(studentId) })
  if (q.isLoading || !q.data) return <Loading />
  const w = q.data
  const m = (f: number) => formatMoney(f, locale)

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className={`rounded-xl px-4 py-3 ${w.is_due ? 'bg-danger/8' : 'bg-page/60'}`}>
          <p className={`text-2xl font-semibold tabular-nums ${w.is_due ? 'text-danger' : 'text-ink'}`}>{m(w.balance_fils)}</p>
          <p className="text-sm text-ink/60">{t('wallet.balance')}</p>
        </div>
        <Stat value={m(w.outstanding_fils)} label={t('wallet.outstanding')} />
      </div>
      <section className={card}>
        <h3 className="mb-3 font-semibold text-ink">{t('wallet.invoices')}</h3>
        {w.invoices.length === 0 ? <EmptyState size="sm" icon="payments" title={t('wallet.no_invoices')} /> : (
          <ul className="divide-y divide-ink/6">
            {w.invoices.map((inv) => (
              <li key={inv.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                <span className="min-w-0">
                  <span dir="auto" className="block text-ink">{inv.description}</span>
                  <span className="block text-xs text-ink/50">{inv.invoice_no} · {t('wallet.due_on', { date: formatDate(inv.due_date, locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}</span>
                </span>
                <span className="text-end">
                  <span className="block tabular-nums text-ink">{m(inv.amount_fils)}</span>
                  <span className={`text-xs ${inv.is_overdue ? 'font-semibold text-danger' : 'text-ink/50'}`}>{inv.is_overdue ? t('wallet.overdue') : inv.status_label}</span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
      <section className={card}>
        <h3 className="mb-3 font-semibold text-ink">{t('wallet.transactions')}</h3>
        {w.transactions.data.length === 0 ? <EmptyState size="sm" icon="payments" title={t('wallet.no_transactions')} /> : (
          <ul className="divide-y divide-ink/6">
            {w.transactions.data.map((tx) => (
              <li key={tx.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                <span>
                  <span className="block text-ink">{tx.type_label}{tx.invoice_no && <> · {tx.invoice_no}</>}</span>
                  <span className="block text-xs text-ink/50">{formatDate(tx.created_at, locale, { day: 'numeric', month: 'short', year: 'numeric' })}{tx.created_by && <> · {tx.created_by}</>}</span>
                </span>
                <span className={`tabular-nums font-medium ${tx.amount_fils < 0 ? 'text-danger' : 'text-brand-700'}`}>{m(tx.amount_fils)}</span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

/* ---------------------------------------------------------------- Details */
export function DetailsTab({ student, canEdit, canPhoto }: { student: StudentDetail; canEdit: boolean; canPhoto: boolean }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const qc = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({ full_name: student.full_name, guardian_name: student.guardian_name, yearly_target_ayahs: student.yearly_target_ayahs ?? '', status: student.status, notes: student.notes ?? '' })
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ['student', student.id] })
    void qc.invalidateQueries({ queryKey: ['student-profile', student.id] })
    void qc.invalidateQueries({ queryKey: ['students'] })
  }

  const save = useMutation({
    mutationFn: () => studentsApi.update(student.id, { ...form, yearly_target_ayahs: form.yearly_target_ayahs === '' ? null : Number(form.yearly_target_ayahs) }),
    onSuccess: () => { setEditing(false); setSaved(true); setError(null); invalidate() },
    onError: (e) => setError(parseApiError(e).message),
  })
  const photo = useMutation({
    mutationFn: (file: File) => studentsApi.uploadPhoto(student.id, file),
    onSuccess: () => { setError(null); invalidate() },
    onError: (e) => setError(parseApiError(e).message),
  })

  const rows: [string, React.ReactNode][] = [
    [t('details.student_no'), <span className="tabular-nums">{student.student_no}</span>],
    [t('details.birth_date'), student.birth_date ? formatDate(student.birth_date, locale) : '—'],
    [t('details.gender'), t(`details.${student.gender}`)],
    [t('details.level'), student.memorization_level_label ?? '—'],
    [t('details.guardian'), <span dir="auto">{student.guardian_name}</span>],
    [t('details.guardian_phone'), <span dir="ltr" className="tabular-nums">{student.guardian_phone}</span>],
    [t('details.student_phone'), student.student_phone ? <span dir="ltr" className="tabular-nums">{student.student_phone}</span> : '—'],
    [t('details.yearly_target'), student.yearly_target_ayahs ? formatNumber(student.yearly_target_ayahs, locale) : '—'],
  ]

  return (
    <div className="space-y-5">
      {error && <Alert>{error}</Alert>}
      {saved && !editing && <Alert tone="success">{t('details.saved')}</Alert>}

      {canPhoto && (
        <section className={card}>
          <h3 className="mb-1 font-semibold text-ink">{t('details.photo')}</h3>
          <p className="mb-3 text-sm text-ink/55">{t('details.photo_hint')}</p>
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5">
            <Icon name="camera" className="size-4" />
            {photo.isPending ? t('details.uploading') : t('details.change_photo')}
            <input type="file" accept="image/jpeg,image/png,image/heic,image/heif" capture="environment" className="sr-only" disabled={photo.isPending}
              onChange={(e) => { const f = e.target.files?.[0]; if (f) photo.mutate(f); e.target.value = '' }} />
          </label>
        </section>
      )}

      <section className={card}>
        <div className="mb-3 flex items-center justify-between gap-2">
          <h3 className="font-semibold text-ink">{t('tabs.details')}</h3>
          {canEdit && !editing && (
            <button type="button" onClick={() => { setEditing(true); setSaved(false) }} className="inline-flex items-center gap-1.5 rounded-lg border border-ink/10 px-2.5 py-1.5 text-sm text-ink/75 hover:bg-ink/5">
              <Icon name="edit" className="size-4" />{t('details.edit')}
            </button>
          )}
        </div>

        {editing ? (
          <form onSubmit={(e) => { e.preventDefault(); save.mutate() }} className="grid gap-4 sm:grid-cols-2">
            <FormField label={t('columns.student')} value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} required minLength={3} />
            <FormField label={t('details.guardian')} value={form.guardian_name} onChange={(e) => setForm({ ...form, guardian_name: e.target.value })} required minLength={3} />
            <FormField label={t('details.yearly_target')} type="number" min={0} inputMode="numeric" value={form.yearly_target_ayahs} onChange={(e) => setForm({ ...form, yearly_target_ayahs: e.target.value })} />
            <SelectField label={t('columns.status')} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}
              options={['active', 'inactive', 'suspended', 'graduated'].map((s) => ({ value: s, label: t(`status.${s}`) }))} />
            <div className="sm:col-span-2">
              <label htmlFor="student-notes" className="mb-1.5 block text-sm font-medium text-ink/75">{t('details.notes')}</label>
              <textarea id="student-notes" rows={3} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })}
                className={inputClass('md', 'w-full')} />
            </div>
            <div className="flex gap-2 sm:col-span-2">
              <PrimaryButton type="submit" loading={save.isPending}>{t('details.save')}</PrimaryButton>
              <button type="button" onClick={() => setEditing(false)} className="rounded-xl border border-ink/10 px-4 py-2 text-sm text-ink/75 hover:bg-ink/5">{t('details.cancel')}</button>
            </div>
          </form>
        ) : (
          <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            {rows.map(([k, v]) => (
              <div key={k}>
                <dt className="text-xs text-ink/50">{k}</dt>
                <dd className="text-ink">{v}</dd>
              </div>
            ))}
            {student.notes && (
              <div className="sm:col-span-2">
                <dt className="text-xs text-ink/50">{t('details.notes')}</dt>
                <dd dir="auto" className="whitespace-pre-line text-ink">{student.notes}</dd>
              </div>
            )}
          </dl>
        )}
      </section>

      {student.lessons.length > 0 && (
        <section className={card}>
          <h3 className="mb-3 font-semibold text-ink">{t('details.circles')}</h3>
          <ul className="divide-y divide-ink/6 text-sm">
            {student.lessons.map((l) => (
              <li key={l.id} className="flex flex-wrap justify-between gap-2 py-2">
                <span dir="auto" className="text-ink">{l.name}</span>
                <span dir="auto" className="text-ink/55">{l.teacher} · {l.location}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
