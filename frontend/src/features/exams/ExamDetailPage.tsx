import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { examsApi, type Exam, type Question } from '../../api/exams'
import { saveBlob } from '../../api/payments'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { EmptyState, OrnamentDivider } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented } from '../../components/ui'
import { formatDate, formatNumber, formatPercent } from '../../lib/format'
import ExamFormDialog from './ExamFormDialog'
import { EXAM_STATUS_TONE } from './ExamsHomePage'
import GradeAttemptDialog from './GradeAttemptDialog'
import QuestionEditor from './QuestionEditor'

type Tab = 'overview' | 'questions' | 'grading' | 'results'

export default function ExamDetailPage() {
  const { id } = useParams()
  const examId = Number(id)
  const { t, i18n } = useTranslation('exams')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const q = useQuery({ queryKey: ['exam', examId, locale], queryFn: () => examsApi.show(examId) })
  const [edit, setEdit] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['exam', examId] }); void qc.invalidateQueries({ queryKey: ['exams'] }) }
  const act = useMutation({
    mutationFn: (a: 'publish' | 'close' | 'delete') => (a === 'publish' ? examsApi.publish(examId) : a === 'close' ? examsApi.close(examId) : examsApi.remove(examId)),
    onSuccess: (_d, a) => { if (a === 'delete') navigate('/exams'); else refresh() },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  const exam = q.data.data
  const stats = q.data.stats
  const tabs: Tab[] = exam.type === 'online' ? ['overview', 'questions', 'grading', 'results'] : ['overview', 'grading', 'results']
  const tab: Tab = (tabs as string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview'
  const manage = can('exams.manage')
  const n = (v: number) => formatNumber(v, locale)
  const dt = (iso: string) => formatDate(iso, locale, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <Link to="/exams" className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('actions.back')}</Link>
      <header className="rounded-2xl border border-ink/8 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 dir="auto" className="font-display text-3xl text-ink">{exam.name}</h1>
              <Badge tone={EXAM_STATUS_TONE[exam.status]}>{t(`status.${exam.status}`)}</Badge>
              <Badge>{t(`type.${exam.type}`)}</Badge>
              {exam.is_open_now && exam.status === 'published' && <Badge tone="danger">{t('open_now')}</Badge>}
            </div>
            <p dir="auto" className="mt-1 text-sm text-ink/60">{exam.lesson_name ?? exam.package_name}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            {manage && exam.status === 'draft' && <PrimaryButton loading={act.isPending} onClick={() => act.mutate('publish')}>{t('actions.publish')}</PrimaryButton>}
            {manage && exam.status === 'published' && <SecondaryButton onClick={() => act.mutate('close')}>{t('actions.close')}</SecondaryButton>}
            <SecondaryButton onClick={async () => saveBlob(await examsApi.rosterPdf(examId), `exam-${examId}-roster.pdf`, true)}><Icon name="table" className="size-4" />{t('actions.roster')}</SecondaryButton>
            {manage && <SecondaryButton onClick={() => setEdit(true)}><Icon name="edit" className="size-4" />{t('actions.edit')}</SecondaryButton>}
            {manage && exam.status === 'draft' && <SecondaryButton className="text-danger" onClick={() => window.confirm(t('actions.delete_confirm')) && act.mutate('delete')}>{t('actions.delete')}</SecondaryButton>}
          </div>
        </div>
        <OrnamentDivider className="my-3 text-gold-500/70" />
        <dl className="grid gap-3 text-sm sm:grid-cols-4">
          <div><dt className="text-xs text-ink/50">{t('form.exam_date')}</dt><dd className="text-ink">{formatDate(exam.exam_date, locale)}</dd></div>
          {exam.type === 'online' && <div><dt className="text-xs text-ink/50">{t('form.opens_at')}</dt><dd className="text-ink">{dt(exam.opens_at)}</dd></div>}
          {exam.type === 'online' && <div><dt className="text-xs text-ink/50">{t('form.closes_at')}</dt><dd className="text-ink">{dt(exam.closes_at)}</dd></div>}
          <div><dt className="text-xs text-ink/50">{t('form.pass_mark')}</dt><dd className="tabular-nums text-ink">{t('marks', { pass: n(exam.pass_mark), total: n(exam.total_marks) })}</dd></div>
        </dl>
      </header>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      <Segmented name="exam-tab" label={exam.name} value={tab} options={tabs.map((k) => ({ value: k, label: t(`tabs.${k}`) }))} onChange={(v) => setParams({ tab: v }, { replace: true })} />

      {tab === 'overview' && (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
            {([['eligible', n(stats.eligible)], ['graded', n(stats.graded)], ['passed', n(stats.passed)], ['pass_rate', formatPercent(stats.pass_rate, locale)], ['average', formatNumber(stats.average, locale, { maximumFractionDigits: 1 })]] as const).map(([k, v]) => (
              <div key={k} className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm"><p className="text-sm text-ink/60">{t(`stats.${k}`)}</p><p className="mt-1 text-2xl font-semibold tabular-nums text-ink">{v}</p></div>
            ))}
          </div>
          {exam.syllabus && <Card><CardTitle>{t('form.syllabus')}</CardTitle><p dir="auto" className="whitespace-pre-line text-ink/80">{exam.syllabus}</p></Card>}
        </div>
      )}
      {tab === 'questions' && <Questions exam={exam} editable={manage && exam.status === 'draft'} />}
      {tab === 'grading' && (exam.type === 'paper' ? <PaperGrading exam={exam} onSaved={refresh} /> : <OnlineGrading exam={exam} onSaved={refresh} />)}
      {tab === 'results' && <Results exam={exam} />}
      {edit && <ExamFormDialog exam={exam} onClose={() => setEdit(false)} onSaved={() => { setEdit(false); refresh() }} />}
    </div>
  )
}

function Questions({ exam, editable }: { exam: Exam; editable: boolean }) {
  const { t, i18n } = useTranslation('exams')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['exam-questions', exam.id], queryFn: () => examsApi.questions(exam.id) })
  const [edit, setEdit] = useState<Question | 'new' | null>(null)
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['exam-questions', exam.id] }); void qc.invalidateQueries({ queryKey: ['exam', exam.id] }) }
  const del = useMutation({ mutationFn: (qid: number) => examsApi.deleteQuestion(exam.id, qid), onSuccess: refresh })
  const move = useMutation({
    mutationFn: ({ from, to }: { from: number; to: number }) => { const ids = (q.data ?? []).map((x) => x.id); const [x] = ids.splice(from, 1); ids.splice(to, 0, x); return examsApi.reorder(exam.id, ids) },
    onSuccess: refresh,
  })
  const n = (v: number) => formatNumber(v, i18n.language)
  const sum = (q.data ?? []).reduce((a, x) => a + x.marks, 0)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <p className={`text-sm ${sum === exam.total_marks ? 'text-brand-700' : 'text-gold-700'}`}>{t('questions.total', { sum: n(sum), total: n(exam.total_marks) })}</p>
        {editable && <PrimaryButton className="ms-auto" onClick={() => setEdit('new')}>+ {t('questions.add')}</PrimaryButton>}
      </div>
      {q.isLoading ? <LoadingState /> : !q.data?.length ? (
        <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="exams" title={t('questions.empty')} /></div>
      ) : (
        <ol className="space-y-3">
          {q.data.map((qq, i) => (
            <li key={qq.id} className="rounded-2xl border border-ink/8 bg-white p-4 shadow-sm">
              <div className="flex flex-wrap items-start gap-3">
                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">{n(i + 1)}</span>
                <div className="min-w-0 flex-1">
                  <p dir="auto" className="font-display text-lg text-ink">{qq.prompt}</p>
                  {qq.options && qq.type === 'mcq' && (
                    <ul className="mt-2 space-y-1 text-sm">{qq.options.map((o) => <li key={o.key} dir="auto" className={o.key === qq.correct_answer?.key ? 'font-semibold text-brand-700' : 'text-ink/70'}>{o.key === qq.correct_answer?.key ? '✓ ' : '· '}{o.text}</li>)}</ul>
                  )}
                  {qq.type === 'true_false' && <p className="mt-1 text-sm text-brand-700">✓ {qq.correct_answer?.value ? t('questions.true') : t('questions.false')}</p>}
                  {qq.type === 'complete_verse' && <p dir="rtl" className="mt-1 font-display text-brand-700">✓ {qq.correct_answer?.text}</p>}
                  {qq.type === 'order_verses' && <ol className="mt-1 list-decimal ps-5 text-sm text-brand-700">{(qq.correct_answer?.order ?? []).map((k) => <li key={k} dir="rtl" className="font-display">{qq.options?.find((o) => o.key === k)?.text}</li>)}</ol>}
                </div>
                <div className="flex flex-col items-end gap-2">
                  <span className="flex gap-1"><Badge>{t(`questions.types.${qq.type}`)}</Badge><Badge tone="gold">{n(qq.marks)}</Badge></span>
                  {editable && (
                    <span className="flex gap-1 text-ink/60">
                      <button type="button" disabled={i === 0} className="rounded p-1 hover:bg-ink/5 disabled:opacity-30" aria-label={t('questions.move_up')} onClick={() => move.mutate({ from: i, to: i - 1 })}>↑</button>
                      <button type="button" disabled={i === q.data.length - 1} className="rounded p-1 hover:bg-ink/5 disabled:opacity-30" aria-label={t('questions.move_down')} onClick={() => move.mutate({ from: i, to: i + 1 })}>↓</button>
                      <button type="button" className="rounded p-1 hover:bg-ink/5" aria-label={t('questions.edit')} onClick={() => setEdit(qq)}><Icon name="edit" className="size-4" /></button>
                      <button type="button" className="rounded p-1 text-danger hover:bg-danger/5" aria-label={t('questions.delete')} onClick={() => del.mutate(qq.id)}>✕</button>
                    </span>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ol>
      )}
      {edit && <QuestionEditor examId={exam.id} question={edit === 'new' ? undefined : edit} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); refresh() }} />}
    </div>
  )
}

function PaperGrading({ exam, onSaved }: { exam: Exam; onSaved: () => void }) {
  const { t, i18n } = useTranslation('exams')
  const qc = useQueryClient()
  const r = useQuery({ queryKey: ['exam-results', exam.id], queryFn: () => examsApi.results(exam.id) })
  const attempts = useQuery({ queryKey: ['exam-attempts', exam.id], queryFn: () => examsApi.attempts(exam.id) })
  const [scores, setScores] = useState<Record<number, string>>({})
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  useEffect(() => { if (r.data) setScores(Object.fromEntries(r.data.rows.map((x) => [x.student_id, x.score === null ? '' : String(x.score)]))) }, [r.data])
  const save = useMutation({
    mutationFn: () => examsApi.scores(exam.id, Object.entries(scores).filter(([, v]) => v !== '').map(([sid, v]) => ({ student_id: Number(sid), score: Number(v) }))),
    onSuccess: () => { setMsg({ tone: 'success', text: t('grading.saved') }); void qc.invalidateQueries({ queryKey: ['exam-results', exam.id] }); void qc.invalidateQueries({ queryKey: ['exam-attempts', exam.id] }); onSaved() },
    onError: (e) => setMsg({ tone: 'error', text: parseApiError(e).message }),
  })
  const upload = useMutation({ mutationFn: ({ aid, file }: { aid: number; file: File }) => examsApi.uploadSheet(exam.id, aid, file), onSuccess: () => void qc.invalidateQueries({ queryKey: ['exam-attempts', exam.id] }) })
  if (r.isLoading) return <LoadingState />
  const sheetOf = (aid: number | null) => attempts.data?.find((a) => a.id === aid)?.sheet_media_id

  return (
    <div className="space-y-4">
      <p className="text-sm text-ink/60">{t('grading.paper_hint')}</p>
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      <ul className="divide-y divide-ink/6 rounded-2xl border border-ink/8 bg-white shadow-sm">
        {(r.data?.rows ?? []).map((row) => (
          <li key={row.student_id} className="flex flex-wrap items-center gap-3 px-4 py-2.5">
            <span dir="auto" className="min-w-0 flex-1 font-medium text-ink">{row.full_name}<span className="block text-xs tabular-nums text-ink/50">{row.student_no}</span></span>
            <label className="sr-only" htmlFor={`score-${row.student_id}`}>{t('grading.score')}</label>
            <input id={`score-${row.student_id}`} type="number" min={0} max={exam.total_marks} inputMode="numeric" value={scores[row.student_id] ?? ''} onChange={(e) => setScores({ ...scores, [row.student_id]: e.target.value })}
              className={`w-24 rounded-lg border px-2 py-1.5 text-center tabular-nums ${scores[row.student_id] !== '' && Number(scores[row.student_id]) < exam.pass_mark ? 'border-danger/50 text-danger' : 'border-ink/15'}`} />
            <span className="w-12 text-xs text-ink/50">/ {formatNumber(exam.total_marks, i18n.language)}</span>
            {row.attempt_id && (sheetOf(row.attempt_id) ? <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('grading.sheet_uploaded')}</Badge> : (
              <label className="cursor-pointer rounded-lg border border-ink/12 px-2.5 py-1 text-xs text-ink/70 hover:bg-ink/5">
                <Icon name="camera" className="me-1 inline size-3.5" />{t('grading.upload_sheet')}
                <input type="file" accept="image/jpeg,image/png" capture="environment" className="sr-only" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate({ aid: row.attempt_id!, file: f }) }} />
              </label>
            ))}
          </li>
        ))}
      </ul>
      <div className="flex justify-end"><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('grading.save')}</PrimaryButton></div>
    </div>
  )
}

function OnlineGrading({ exam, onSaved }: { exam: Exam; onSaved: () => void }) {
  const { t, i18n } = useTranslation('exams')
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['exam-attempts', exam.id], queryFn: () => examsApi.attempts(exam.id) })
  const [grading, setGrading] = useState<number | null>(null)
  if (q.isLoading) return <LoadingState />
  if (!q.data?.length) return <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="exams" title={t('grading.no_attempts')} /></div>
  const n = (v: number | null) => (v === null ? '—' : formatNumber(v, i18n.language))

  return (
    <>
      <ul className="divide-y divide-ink/6 rounded-2xl border border-ink/8 bg-white shadow-sm">
        {q.data.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
            <span dir="auto" className="min-w-0 flex-1 font-medium text-ink">{a.student?.full_name}</span>
            <Badge tone={a.status === 'submitted' ? 'gold' : a.status === 'graded' ? 'brand' : 'muted'}>{a.status === 'submitted' ? t('grading.needs_grading') : a.status_label}</Badge>
            <span className="tabular-nums text-ink/70">{t('grading.auto')}: {n(a.auto_score)} · {t('grading.manual')}: {n(a.manual_score)} · <b className="text-ink">{t('grading.total')}: {n(a.total_score)}</b></span>
            {a.passed !== null && <Badge tone={a.passed ? 'brand' : 'danger'}>{a.passed ? t('results.passed') : t('results.failed')}</Badge>}
            <SecondaryButton onClick={() => setGrading(a.id)}>{a.status === 'submitted' ? t('grading.grade') : t('grading.view')}</SecondaryButton>
          </li>
        ))}
      </ul>
      {grading && <GradeAttemptDialog examId={exam.id} attemptId={grading} onClose={() => setGrading(null)} onDone={() => { setGrading(null); void qc.invalidateQueries({ queryKey: ['exam-attempts', exam.id] }); onSaved() }} />}
    </>
  )
}

function Results({ exam }: { exam: Exam }) {
  const { t, i18n } = useTranslation('exams')
  const locale = i18n.language
  const { can } = useAuth()
  const r = useQuery({ queryKey: ['exam-results', exam.id], queryFn: () => examsApi.results(exam.id) })
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const send = useMutation({ mutationFn: () => examsApi.sendResults(exam.id), onSuccess: (d) => setMsg({ tone: 'success', text: d.message }), onError: (e) => setMsg({ tone: 'error', text: parseApiError(e).message }) })
  const certs = useMutation({ mutationFn: () => examsApi.certificates(exam.id), onSuccess: () => setMsg({ tone: 'success', text: t('results.certificates_done') }), onError: (e) => setMsg({ tone: 'error', text: parseApiError(e).message }) })
  if (r.isLoading) return <LoadingState />
  const rows = [...(r.data?.rows ?? [])].sort((a, b) => (b.score ?? -1) - (a.score ?? -1))
  const n = (v: number) => formatNumber(v, locale)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        {can('exams.manage') && <PrimaryButton loading={send.isPending} onClick={() => send.mutate()}><Icon name="messages" className="size-4" />{t('results.send')}</PrimaryButton>}
        {can('exams.manage') && <SecondaryButton disabled={certs.isPending} onClick={() => certs.mutate()}>{t('results.certificates')}</SecondaryButton>}
        <SecondaryButton onClick={async () => saveBlob(await examsApi.resultsXlsx(exam.id), `exam-${exam.id}-results.xlsx`)}><Icon name="table" className="size-4" />{t('results.xlsx')}</SecondaryButton>
      </div>
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      {r.data && r.data.top.length > 0 && (
        <Card>
          <CardTitle>{t('results.top')}</CardTitle>
          <ol className="grid gap-3 sm:grid-cols-3">
            {r.data.top.map((s, i) => (
              <li key={s.student_id} className={`rounded-xl p-3 text-center ${i === 0 ? 'bg-gold-500/12' : 'bg-page/70'}`}>
                <p className="font-display text-2xl text-gold-700">{n(i + 1)}</p>
                <p dir="auto" className="font-semibold text-ink">{s.full_name}</p>
                <p className="tabular-nums text-ink/70">{n(s.score)} / {n(exam.total_marks)}</p>
              </li>
            ))}
          </ol>
        </Card>
      )}
      {rows.length === 0 ? <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="exams" title={t('results.empty')} /></div> : (
        <div className="overflow-x-auto rounded-2xl border border-ink/8 bg-white shadow-sm">
          <table className="w-full min-w-[32rem] text-sm">
            <thead className="bg-page/60 text-ink/55"><tr><th className="px-4 py-2.5 text-start font-medium">{t('results.rank')}</th><th className="px-4 py-2.5 text-start font-medium">{t('results.student')}</th><th className="px-4 py-2.5 text-end font-medium">{t('results.score')}</th><th className="px-4 py-2.5 text-start font-medium">{t('results.result')}</th></tr></thead>
            <tbody className="divide-y divide-ink/6">
              {rows.map((row, i) => (
                <tr key={row.student_id}>
                  <td className="px-4 py-2.5 tabular-nums text-ink/60">{row.score === null ? '—' : n(i + 1)}</td>
                  <td className="px-4 py-2.5"><span dir="auto" className="font-medium text-ink">{row.full_name}</span><span className="block text-xs tabular-nums text-ink/50">{row.student_no}</span></td>
                  <td className="px-4 py-2.5 text-end tabular-nums">{row.score === null ? '—' : `${n(row.score)} / ${n(exam.total_marks)}`}</td>
                  <td className="px-4 py-2.5">{row.passed === null ? <Badge>{row.status ? t('grading.needs_grading') : t('results.absent')}</Badge> : <Badge tone={row.passed ? 'brand' : 'danger'}>{row.passed ? t('results.passed') : t('results.failed')}</Badge>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
