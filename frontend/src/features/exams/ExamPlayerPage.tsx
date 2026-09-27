import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { myExamsApi, type Attempt, type Question } from '../../api/exams'
import { parseApiError } from '../../api/client'
import { OrnamentFrame } from '../../components/ornaments'
import { ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton } from '../../components/ui'
import FamilyLayout from '../../layouts/FamilyLayout'
import { formatNumber } from '../../lib/format'

type AnswerMap = Record<number, unknown>

/**
 * Online exam player. The timer is display-only; the server enforces expiry (answers after expires_at are
 * rejected and the attempt auto-submits). Answers autosave 1.5 s after each change and every 20 s.
 */
export default function ExamPlayerPage() {
  const { id } = useParams()
  const examId = Number(id)
  const [params] = useSearchParams()
  const studentId = params.get('student') ? Number(params.get('student')) : undefined
  const { t, i18n } = useTranslation('exams')
  const locale = i18n.language

  const [attempt, setAttempt] = useState<Attempt | null>(null)
  const [answers, setAnswers] = useState<AnswerMap>({})
  const [index, setIndex] = useState(0)
  const [remaining, setRemaining] = useState<number | null>(null)
  const [saveState, setSaveState] = useState<{ at?: string; error?: boolean; saving?: boolean }>({})
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<'submitted' | 'time_up' | null>(null)
  const dirty = useRef(false)

  // Start or resume.
  useEffect(() => {
    myExamsApi.start(examId, studentId)
      .then((a) => {
        setAttempt(a)
        setRemaining(a.remaining_seconds ?? null)
        setAnswers(Object.fromEntries((a.answers ?? []).map((x) => [x.question_id, x.answer])))
        if (a.submitted_at) setDone('submitted')
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [examId, studentId])

  const save = useCallback(async () => {
    if (!attempt || !dirty.current || done) return
    dirty.current = false
    setSaveState((s) => ({ ...s, saving: true }))
    try {
      const payload = Object.entries(answers).filter(([qid]) => attempt.questions?.find((q) => q.id === Number(qid))?.type !== 'recitation').map(([qid, answer]) => ({ question_id: Number(qid), answer }))
      if (payload.length === 0) { setSaveState({}); return }
      const r = await myExamsApi.save(examId, payload, studentId)
      setRemaining(r.remaining_seconds)
      setSaveState({ at: r.saved_at })
    } catch (e) {
      dirty.current = true
      const p = parseApiError(e)
      if (p.fields.attempt || /expired|انته/.test(p.message)) setDone('time_up')
      else setSaveState({ error: true })
    }
  }, [answers, attempt, done, examId, studentId])

  // Debounced autosave after changes + periodic safety save.
  useEffect(() => { const h = setTimeout(() => void save(), 1500); return () => clearTimeout(h) }, [answers, save])
  useEffect(() => { const h = setInterval(() => void save(), 20_000); return () => clearInterval(h) }, [save])

  // Local countdown (resynced by each save).
  useEffect(() => {
    if (remaining === null || done) return
    if (remaining <= 0) { void myExamsApi.attempt(examId, studentId).finally(() => setDone('time_up')); return }
    const h = setTimeout(() => setRemaining((r) => (r === null ? r : r - 1)), 1000)
    return () => clearTimeout(h)
  }, [remaining, done, examId, studentId])

  const submit = useMutation({
    mutationFn: async () => { dirty.current = true; await save(); return myExamsApi.submit(examId, studentId) },
    onSuccess: () => setDone('submitted'),
    onError: (e) => setError(parseApiError(e).message),
  })

  const setAnswer = (qid: number, v: unknown) => { dirty.current = true; setAnswers((a) => ({ ...a, [qid]: v })) }

  if (error && !attempt) return <FamilyLayout><ErrorState message={error} /><p className="mt-4 text-center"><Link to="/my/exams" className="text-brand-700 hover:underline">{t('player.back')}</Link></p></FamilyLayout>
  if (!attempt) return <FamilyLayout><LoadingState /></FamilyLayout>

  if (done) {
    return (
      <FamilyLayout>
        <OrnamentFrame className="mx-auto max-w-lg text-gold-500">
          <div className="space-y-3 p-8 text-center">
            <h1 className="font-display text-3xl text-ink">{t('player.submitted')}</h1>
            {done === 'time_up' && <Notice tone="info">{t('player.time_up')}</Notice>}
            <p className="text-ink/70">{t('player.submitted_body')}</p>
            <Link to="/my/exams" className="inline-block rounded-xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white">{t('player.back')}</Link>
          </div>
        </OrnamentFrame>
      </FamilyLayout>
    )
  }

  const questions = attempt.questions ?? []
  const q = questions[index]
  const n = (v: number) => formatNumber(v, locale)
  const mm = remaining === null ? '—' : `${n(Math.floor(Math.max(0, remaining) / 60))}:${String(Math.max(0, remaining) % 60).padStart(2, '0').replace(/\d/g, (d) => n(Number(d)))}`
  const unanswered = questions.filter((x) => answers[x.id] === undefined || answers[x.id] === null).length
  const low = remaining !== null && remaining < 60

  return (
    <FamilyLayout>
      <div className="space-y-4 pb-24">
        <div className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-b border-ink/8 bg-page/95 px-4 py-2 backdrop-blur">
          <div role="timer" aria-live="off" aria-label={t('player.remaining')} className={`rounded-xl px-3 py-1.5 font-mono text-lg font-semibold tabular-nums ${low ? 'bg-danger/10 text-danger' : 'bg-white text-ink shadow-sm'}`}>{mm}</div>
          <p className="text-sm text-ink/60">{t('player.question_of', { n: n(index + 1), total: n(questions.length) })}</p>
          <p className="ms-auto text-xs text-ink/50" aria-live="polite">
            {saveState.saving ? t('player.saving') : saveState.error ? <span className="text-danger">{t('player.save_error')}</span> : saveState.at ? t('player.saved', { time: new Date(saveState.at).toLocaleTimeString(locale === 'ar' ? 'ar-BH' : 'en-BH', { hour: 'numeric', minute: '2-digit' }) }) : ''}
          </p>
        </div>

        {/* Question dots */}
        <nav aria-label={t('player.question_of', { n: '', total: '' })} className="flex flex-wrap gap-1.5">
          {questions.map((x, i) => (
            <button key={x.id} type="button" onClick={() => setIndex(i)} aria-current={i === index ? 'step' : undefined}
              className={`size-8 rounded-full text-xs font-semibold tabular-nums ${i === index ? 'bg-brand-700 text-white' : answers[x.id] !== undefined ? 'bg-brand-100 text-brand-800' : 'bg-white text-ink/60 ring-1 ring-ink/10'}`}>
              {n(i + 1)}
            </button>
          ))}
        </nav>

        {q && (
          <section className="rounded-2xl border border-ink/8 bg-white p-5 shadow-sm" aria-labelledby={`q-${q.id}`}>
            <p id={`q-${q.id}`} dir="auto" className="font-display text-2xl leading-relaxed text-ink">{q.prompt}</p>
            <div className="mt-4"><QuestionInput q={q} value={answers[q.id]} onChange={(v) => setAnswer(q.id, v)} examId={examId} studentId={studentId} /></div>
          </section>
        )}

        {error && <Notice tone="error">{error}</Notice>}
        <div className="fixed inset-x-0 bottom-0 z-20 border-t border-ink/8 bg-white/95 px-4 py-3 backdrop-blur">
          <div className="mx-auto flex max-w-5xl items-center gap-2">
            <SecondaryButton disabled={index === 0} onClick={() => setIndex(index - 1)}>{t('player.prev')}</SecondaryButton>
            <SecondaryButton disabled={index >= questions.length - 1} onClick={() => setIndex(index + 1)}>{t('player.next')}</SecondaryButton>
            {unanswered > 0 && <span className="text-xs text-gold-700">{t('player.unanswered', { n: n(unanswered) })}</span>}
            <PrimaryButton className="ms-auto" loading={submit.isPending} onClick={() => window.confirm(t('player.submit_confirm')) && submit.mutate()}>{t('player.submit')}</PrimaryButton>
          </div>
        </div>
      </div>
    </FamilyLayout>
  )
}

function QuestionInput({ q, value, onChange, examId, studentId }: { q: Question; value: unknown; onChange: (v: unknown) => void; examId: number; studentId?: number }) {
  const { t } = useTranslation('exams')
  const v = (value ?? {}) as Record<string, unknown>

  switch (q.type) {
    case 'mcq':
      return (
        <fieldset className="space-y-2">
          <legend className="sr-only">{t('player.your_answer')}</legend>
          {(q.options ?? []).map((o) => (
            <label key={o.key} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-3 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${v.key === o.key ? 'border-brand-600 bg-brand-50' : 'border-ink/12 hover:bg-ink/5'}`}>
              <input type="radio" name={`q-${q.id}`} className="size-4 accent-brand-600" checked={v.key === o.key} onChange={() => onChange({ key: o.key })} />
              <span dir="auto" className="text-lg text-ink">{o.text}</span>
            </label>
          ))}
        </fieldset>
      )
    case 'true_false':
      return (
        <fieldset className="flex gap-3">
          <legend className="sr-only">{t('player.your_answer')}</legend>
          {[true, false].map((b) => (
            <label key={String(b)} className={`flex-1 cursor-pointer rounded-xl border p-4 text-center text-lg font-semibold ${v.value === b ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-ink/12 text-ink/75'}`}>
              <input type="radio" name={`q-${q.id}`} className="sr-only" checked={v.value === b} onChange={() => onChange({ value: b })} />
              {b ? t('player.true') : t('player.false')}
            </label>
          ))}
        </fieldset>
      )
    case 'complete_verse':
      return (
        <div>
          <label htmlFor={`a-${q.id}`} className="mb-1.5 block text-sm font-medium text-ink/75">{t('player.your_answer')}</label>
          <textarea id={`a-${q.id}`} dir="rtl" rows={3} value={String(v.text ?? '')} onChange={(e) => onChange({ text: e.target.value })}
            className="block w-full rounded-xl border border-ink/15 px-3 py-2 font-display text-xl focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100" />
        </div>
      )
    case 'order_verses': {
      const initial = (q.options ?? []).map((o) => o.key)
      const order = (Array.isArray(v.order) ? (v.order as string[]) : initial)
      const moveTo = (i: number, j: number) => { const next = [...order]; const [x] = next.splice(i, 1); next.splice(j, 0, x); onChange({ order: next }) }
      return (
        <div>
          <p className="mb-2 text-sm text-ink/55">{t('player.order_hint')}</p>
          <ol className="space-y-2">
            {order.map((k, i) => (
              <li key={k} className="flex items-center gap-2 rounded-xl border border-ink/12 bg-page/40 p-3">
                <span className="w-6 text-center text-sm tabular-nums text-ink/50">{i + 1}</span>
                <span dir="rtl" className="flex-1 font-display text-lg text-ink">{q.options?.find((o) => o.key === k)?.text}</span>
                <button type="button" disabled={i === 0} className="rounded-lg px-2 py-1 hover:bg-ink/5 disabled:opacity-30" aria-label="↑" onClick={() => moveTo(i, i - 1)}>↑</button>
                <button type="button" disabled={i === order.length - 1} className="rounded-lg px-2 py-1 hover:bg-ink/5 disabled:opacity-30" aria-label="↓" onClick={() => moveTo(i, i + 1)}>↓</button>
              </li>
            ))}
          </ol>
        </div>
      )
    }
    default:
      return <Recorder examId={examId} questionId={q.id} studentId={studentId} already={!!v.recorded} onUploaded={() => onChange({ recorded: true })} />
  }
}

/** In-browser recitation recording (MediaRecorder); uploads when stopped. */
function Recorder({ examId, questionId, studentId, already, onUploaded }: { examId: number; questionId: number; studentId?: number; already: boolean; onUploaded: () => void }) {
  const { t } = useTranslation('exams')
  const [state, setState] = useState<'idle' | 'recording' | 'uploading' | 'done' | 'denied'>(already ? 'done' : 'idle')
  const [preview, setPreview] = useState<string | null>(null)
  const rec = useRef<MediaRecorder | null>(null)
  const chunks = useRef<Blob[]>([])

  const start = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mr = new MediaRecorder(stream)
      chunks.current = []
      mr.ondataavailable = (e) => e.data.size && chunks.current.push(e.data)
      mr.onstop = async () => {
        stream.getTracks().forEach((tr) => tr.stop())
        const blob = new Blob(chunks.current, { type: mr.mimeType || 'audio/webm' })
        setPreview(URL.createObjectURL(blob))
        setState('uploading')
        try { await myExamsApi.audio(examId, questionId, blob, studentId); setState('done'); onUploaded() } catch { setState('idle') }
      }
      rec.current = mr
      mr.start()
      setState('recording')
    } catch {
      setState('denied')
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        {state === 'recording'
          ? <PrimaryButton tone="danger" onClick={() => rec.current?.stop()}>■ {t('player.stop')}</PrimaryButton>
          : <PrimaryButton disabled={state === 'uploading'} onClick={() => void start()}>● {state === 'done' ? t('player.re_record') : t('player.record')}</PrimaryButton>}
        {state === 'recording' && <span className="animate-pulse text-sm text-danger" aria-live="polite">{t('player.recording')}</span>}
        {state === 'done' && <span className="text-sm text-brand-700" aria-live="polite">✓ {t('player.uploaded')}</span>}
      </div>
      {state === 'denied' && <Notice tone="error">{t('player.mic_denied')}</Notice>}
      {preview && <audio controls src={preview} className="w-full" />}
    </div>
  )
}
