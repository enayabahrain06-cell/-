import { useCallback, useEffect, useRef, useState } from 'react'
import axios from 'axios'
import { useTranslation } from 'react-i18next'
import { placementApi, type Package, type PlacementResult, type PlacementState } from '../../api/registration'
import type { Question } from '../../api/exams'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { IconButton, inputClass, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, SURFACE } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { toLatinDigits } from '../../lib/phone'
import { clearPlacementToken, readPlacementToken, writePlacementToken } from './placementToken'
import { StepFooter, StepHeading } from './StepParts'

type Answer = Record<string, unknown> | null

/** Answered in the sense the server grades: an option, a true/false choice, some text, or a confirmed order. */
function isAnswered(q: Question, a: Answer): boolean {
  if (!a) return false
  switch (q.type) {
    case 'mcq': return typeof a.key === 'string'
    case 'true_false': return typeof a.value === 'boolean'
    case 'complete_verse': return String(a.text ?? '').trim() !== ''
    case 'order_verses': return Array.isArray(a.order)
    default: return false
  }
}

/**
 * The placement test inside registration: an intro, then one question at a time with a timer and autosave.
 * Resumes a stored attempt for the package; calls onFinished with the token and the scored result.
 */
export function PlacementTest({ pkg, fullName, guardianPhone, stepLabel, onBack, onFinished }: {
  pkg: Package
  fullName: string
  guardianPhone: string
  stepLabel: string
  onBack: () => void
  onFinished: (token: string, result: PlacementResult) => void
}) {
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const info = pkg.placement!

  const [phase, setPhase] = useState<'loading' | 'intro' | 'test'>(() => (readPlacementToken(pkg.id) ? 'loading' : 'intro'))
  const [token, setToken] = useState<string | null>(null)
  const [questions, setQuestions] = useState<Question[]>([])
  const [answers, setAnswers] = useState<Record<number, Answer>>({})
  const [idx, setIdx] = useState(0)
  const [remaining, setRemaining] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [starting, setStarting] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved'>('idle')

  const pending = useRef(new Map<number, Answer>())
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const finishing = useRef(false)

  const load = useCallback((tok: string, s: PlacementState) => {
    if (s.status !== 'in_progress' || !s.attempt) {
      if (s.result) onFinished(tok, s.result)
      return
    }
    setToken(tok)
    setQuestions(s.attempt.questions ?? [])
    setAnswers(Object.fromEntries((s.attempt.answers ?? []).map((a) => [a.question_id, a.answer as Answer])))
    setRemaining(s.attempt.remaining_seconds ?? null)
    setPhase('test')
  }, [onFinished])

  // Resume a stored attempt for this package, or show the intro.
  useEffect(() => {
    const stored = readPlacementToken(pkg.id)
    if (!stored) return
    placementApi.show(stored).then((s) => load(stored, s)).catch((e) => {
      clearPlacementToken(pkg.id)
      if (!(axios.isAxiosError(e) && e.response?.status === 404)) setError(t('placement.resume_failed'))
      setPhase('intro')
    })
  }, [pkg.id, load, t])

  const flush = useCallback(async () => {
    if (saveTimer.current) { clearTimeout(saveTimer.current); saveTimer.current = null }
    if (!token || pending.current.size === 0) return
    const rows = [...pending.current].map(([question_id, answer]) => ({ question_id, answer }))
    pending.current.clear()
    setSaveState('saving')
    try {
      const r = await placementApi.save(token, rows)
      setRemaining(r.remaining_seconds)
      setSaveState('saved')
    } catch {
      // Rejected once time is up; the server scores what it already has.
      setSaveState('idle')
    }
  }, [token])

  const finishFromServer = useCallback(async () => {
    if (!token || finishing.current) return
    finishing.current = true
    await flush()
    try {
      const s = await placementApi.show(token)
      if (s.result) onFinished(token, s.result)
      else finishing.current = false
    } catch (e) {
      finishing.current = false
      setError(parseApiError(e).message)
    }
  }, [token, flush, onFinished])

  // Server-side timer, counted down locally; at zero the server has already scored the attempt.
  useEffect(() => {
    if (phase !== 'test' || remaining === null) return
    const id = setTimeout(() => {
      if (remaining <= 1) { setRemaining(0); void finishFromServer() } else setRemaining(remaining - 1)
    }, remaining <= 0 ? 0 : 1000)
    return () => clearTimeout(id)
  }, [phase, remaining, finishFromServer])

  // Save before the tab closes when possible.
  useEffect(() => () => { if (saveTimer.current) clearTimeout(saveTimer.current) }, [])

  const setAnswer = (q: Question, a: Answer) => {
    setAnswers((prev) => ({ ...prev, [q.id]: a }))
    pending.current.set(q.id, a)
    setSaveState('idle')
    if (saveTimer.current) clearTimeout(saveTimer.current)
    saveTimer.current = setTimeout(() => void flush(), 1500)
  }

  const start = async () => {
    setStarting(true)
    setError(null)
    try {
      const s = await placementApi.start({ package_id: pkg.id, full_name: fullName, guardian_phone: toLatinDigits(guardianPhone) })
      if (s.token) {
        writePlacementToken(pkg.id, s.token)
        load(s.token, s)
        window.scrollTo({ top: 0 })
      }
    } catch (e) {
      const p = parseApiError(e)
      setError(p.fields.exam?.[0] ?? p.fields.guardian_phone?.[0] ?? p.fields.full_name?.[0] ?? p.message)
    } finally {
      setStarting(false)
    }
  }

  const submit = async () => {
    if (!token || submitting) return
    setSubmitting(true)
    setError(null)
    try {
      await flush()
      const r = await placementApi.submit(token)
      setConfirming(false)
      onFinished(token, r.result)
    } catch (e) {
      const p = parseApiError(e)
      setError(p.fields.answers?.[0] ?? p.message)
      setConfirming(false)
    } finally {
      setSubmitting(false)
    }
  }

  if (phase === 'loading') return <div className={`${SURFACE} mx-auto max-w-2xl p-6`}><LoadingState /></div>

  if (phase === 'intro') {
    return (
      <section aria-labelledby="step-title" className={`${SURFACE} mx-auto max-w-2xl space-y-5 p-6`}>
        <StepHeading step={stepLabel} title={t('placement.intro_title')} help={t('placement.intro_help')} />
        <div className="flex flex-wrap items-center gap-3 rounded-xl bg-brand-50/60 px-4 py-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-full bg-white text-brand-700"><Icon name="exams" className="size-4" /></span>
          <div className="min-w-0 flex-1">
            <p dir="auto" className="font-semibold text-ink">{info.name}</p>
            <p className="text-sm text-ink/60">
              {t('placement.questions_n', { n: n(info.questions_count) })} · {t('placement.time_limit', { n: n(info.duration_minutes) })}
            </p>
          </div>
        </div>
        <ul className="space-y-2 text-sm text-ink/75">
          {(['intro_1', 'intro_2', 'intro_3'] as const).map((k) => (
            <li key={k} className="flex gap-2"><Icon name="check" className="mt-0.5 size-4 shrink-0 text-brand-600" />{t(`placement.${k}`)}</li>
          ))}
        </ul>
        {error && <Notice tone="error">{error}</Notice>}
        <StepFooter>
          <SecondaryButton onClick={onBack}><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('public.back')}</SecondaryButton>
          <PrimaryButton loading={starting} onClick={() => void start()}>{starting ? t('placement.starting') : t('placement.start')}</PrimaryButton>
        </StepFooter>
      </section>
    )
  }

  const total = questions.length
  const q = questions[idx]
  const answeredCount = questions.filter((x) => isAnswered(x, answers[x.id] ?? null)).length
  const missing = questions.map((x, i) => ({ x, i })).filter(({ x }) => !isAnswered(x, answers[x.id] ?? null))
  const mins = remaining === null ? null : Math.floor(Math.max(remaining, 0) / 60)
  const secs = remaining === null ? null : Math.max(remaining, 0) % 60
  const clock = mins === null ? null : `${n(mins)}:${formatNumber(secs!, locale, { minimumIntegerDigits: 2 })}`
  const lowTime = remaining !== null && remaining <= 60

  return (
    <section aria-labelledby="step-title" className={`${SURFACE} mx-auto max-w-2xl space-y-5 p-6`}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-medium text-gold-700">{stepLabel}</p>
          <h2 id="step-title" className="mt-0.5 text-lg font-semibold text-ink" aria-live="polite">{t('placement.question_of', { n: n(idx + 1), total: n(total) })}</h2>
        </div>
        {clock && (
          <p className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-semibold tabular-nums ${lowTime ? 'bg-danger/10 text-danger' : 'bg-page text-ink/75'}`}
            role="timer" aria-label={t('placement.time_left_sr', { time: clock })}>
            <Icon name="clock" className="size-4" />{clock}
          </p>
        )}
      </div>

      <div>
        <div className="flex h-2 overflow-hidden rounded-full bg-ink/8" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={answeredCount}
          aria-label={t('placement.answered', { n: n(answeredCount), total: n(total) })}>
          <span className="rounded-full bg-brand-600 transition-[width]" style={{ width: `${total ? (answeredCount / total) * 100 : 0}%` }} />
        </div>
        <p className="mt-1.5 flex justify-between gap-2 text-xs text-ink/55">
          <span aria-live="polite">{t('placement.answered', { n: n(answeredCount), total: n(total) })}</span>
          <span aria-live="polite">{saveState === 'saving' ? t('placement.saving') : saveState === 'saved' ? t('placement.saved') : ''}</span>
        </p>
      </div>

      {q && (
        <div className="space-y-4">
          <p dir="auto" className="text-lg leading-relaxed text-ink" lang={q.type === 'complete_verse' || q.type === 'order_verses' ? 'ar' : undefined}>{q.prompt}</p>
          <PlacementInput q={q} value={answers[q.id] ?? null} onChange={(a) => setAnswer(q, a)} />
        </div>
      )}

      {error && <Notice tone="error">{error}</Notice>}

      {missing.length > 0 && idx === total - 1 && (
        <div className="rounded-xl border border-gold-500/30 bg-gold-500/8 p-3 text-sm">
          <p className="text-ink/80">{t('placement.unanswered')}</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {missing.map(({ i }) => (
              <button key={i} type="button" onClick={() => setIdx(i)} aria-label={t('placement.go_to', { n: n(i + 1) })}
                className="grid min-w-9 place-items-center rounded-lg bg-white px-2 py-1.5 text-sm font-semibold tabular-nums text-gold-700 shadow-sm ring-1 ring-gold-500/30 hover:bg-gold-500/10">
                {n(i + 1)}
              </button>
            ))}
          </div>
        </div>
      )}

      <StepFooter>
        <SecondaryButton disabled={idx === 0} onClick={() => setIdx((i) => i - 1)}><Icon name="chevron" className="size-4 ltr:rotate-180" />{t('placement.previous')}</SecondaryButton>
        {idx < total - 1
          ? <PrimaryButton onClick={() => setIdx((i) => i + 1)}>{t('placement.next')}<Icon name="chevron" className="size-4 rtl:rotate-180" /></PrimaryButton>
          : <PrimaryButton disabled={missing.length > 0 || submitting} onClick={() => setConfirming(true)}>{t('placement.submit')}</PrimaryButton>}
      </StepFooter>

      {confirming && (
        <Modal title={t('placement.confirm_title')} onClose={() => !submitting && setConfirming(false)}
          footer={<>
            <SecondaryButton disabled={submitting} onClick={() => setConfirming(false)}>{t('placement.cancel')}</SecondaryButton>
            <PrimaryButton loading={submitting} disabled={submitting} onClick={() => void submit()}>{submitting ? t('placement.submitting') : t('placement.submit')}</PrimaryButton>
          </>}>
          <p className="text-sm text-ink/75">{t('placement.confirm_body', { n: n(total) })}</p>
        </Modal>
      )}
    </section>
  )
}

/** Answer controls. Payload shapes match the exam AutoGrader (same as the exam player, without recitation). */
function PlacementInput({ q, value, onChange }: { q: Question; value: Answer; onChange: (a: Answer) => void }) {
  const { t, i18n } = useTranslation('registration')
  const v = value ?? {}

  switch (q.type) {
    case 'mcq':
      return (
        <fieldset className="space-y-2">
          <legend className="sr-only">{t('placement.your_answer')}</legend>
          {(q.options ?? []).map((o) => (
            <label key={o.key} className={`flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border p-3 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${v.key === o.key ? 'border-brand-600 bg-brand-50' : 'border-ink/12 hover:bg-ink/5'}`}>
              <input type="radio" name={`pq-${q.id}`} className="size-4 shrink-0 accent-brand-600" checked={v.key === o.key} onChange={() => onChange({ key: o.key })} />
              <span dir="auto" className="text-base text-ink">{o.text}</span>
            </label>
          ))}
        </fieldset>
      )
    case 'true_false':
      return (
        <fieldset className="grid grid-cols-2 gap-3">
          <legend className="sr-only">{t('placement.your_answer')}</legend>
          {[true, false].map((b) => (
            <label key={String(b)} className={`cursor-pointer rounded-xl border p-4 text-center text-base font-semibold has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-500 ${v.value === b ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-ink/12 text-ink/75 hover:bg-ink/5'}`}>
              <input type="radio" name={`pq-${q.id}`} className="sr-only" checked={v.value === b} onChange={() => onChange({ value: b })} />
              {b ? t('placement.true') : t('placement.false')}
            </label>
          ))}
        </fieldset>
      )
    case 'complete_verse':
      return (
        <div>
          <label htmlFor={`pa-${q.id}`} className="mb-1.5 block text-sm font-medium text-ink/75">{t('placement.your_answer')}</label>
          <textarea id={`pa-${q.id}`} dir="rtl" lang="ar" rows={2} value={String(v.text ?? '')} onChange={(e) => onChange({ text: e.target.value })}
            className={inputClass('md', 'w-full font-quran text-xl')} />
        </div>
      )
    case 'order_verses': {
      const initial = (q.options ?? []).map((o) => o.key)
      const confirmed = Array.isArray(v.order)
      const order = confirmed ? (v.order as string[]) : initial
      const moveTo = (i: number, j: number) => { const next = [...order]; const [x] = next.splice(i, 1); next.splice(j, 0, x); onChange({ order: next }) }
      return (
        <div className="space-y-3">
          <p className="text-sm text-ink/55">{t('placement.order_hint')}</p>
          <ol className="space-y-2">
            {order.map((k, i) => (
              <li key={k} className="flex items-center gap-2 rounded-xl border border-ink/12 bg-page/40 p-2 ps-3">
                <span className="w-5 shrink-0 text-center text-sm tabular-nums text-ink/50">{formatNumber(i + 1, i18n.language)}</span>
                <span dir="rtl" lang="ar" className="min-w-0 flex-1 font-quran text-lg text-ink">{q.options?.find((o) => o.key === k)?.text}</span>
                <IconButton size="md" icon="chevron" iconClassName="-rotate-90" label={t('placement.move_up')} disabled={i === 0} onClick={() => moveTo(i, i - 1)} />
                <IconButton size="md" icon="chevron" iconClassName="rotate-90" label={t('placement.move_down')} disabled={i === order.length - 1} onClick={() => moveTo(i, i + 1)} />
              </li>
            ))}
          </ol>
          {confirmed
            ? <p className="inline-flex items-center gap-1.5 text-sm text-brand-700"><Icon name="check" className="size-4" />{t('placement.order_confirmed')}</p>
            : <SecondaryButton onClick={() => onChange({ order })}>{t('placement.use_order')}</SecondaryButton>}
        </div>
      )
    }
    default:
      return null
  }
}

/** The result as the family sees it: percentage, recommended level, counts, and right/wrong per question. */
export function PlacementResultView({ result, stepLabel, onContinue }: { result: PlacementResult; stepLabel: string; onContinue: () => void }) {
  const { t, i18n } = useTranslation('registration')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [showAnswers, setShowAnswers] = useState(false)
  const pct = formatNumber(result.percent / 100, locale, { style: 'percent', maximumFractionDigits: 1 })

  return (
    <section aria-labelledby="step-title" className={`${SURFACE} mx-auto max-w-2xl space-y-5 p-6`}>
      <StepHeading step={stepLabel} title={t('placement.result_title')} help={t('placement.result_help')} />

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-2xl bg-brand-50/70 p-5 text-center">
          <p className="text-sm text-ink/60">{t('placement.score')}</p>
          <p className="mt-1 text-4xl font-semibold tabular-nums text-brand-700">{pct}</p>
        </div>
        <div className="rounded-2xl bg-gold-500/8 p-5 text-center">
          <p className="text-sm text-ink/60">{t('placement.recommended')}</p>
          <p className="mt-2 text-2xl font-semibold text-ink">{result.recommended_level_label ?? t('placement.no_level')}</p>
        </div>
      </div>

      <dl className="divide-y divide-ink/6 rounded-xl border border-ink/8">
        {([['correct', result.correct, 'text-brand-700'], ['incorrect', result.incorrect, 'text-danger'], ['total', result.total_questions, 'text-ink']] as const).map(([k, v, tone]) => (
          <div key={k} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
            <dt className="text-ink/65">{t(`placement.${k}`)}</dt>
            <dd className={`font-semibold tabular-nums ${tone}`}>{n(v)}</dd>
          </div>
        ))}
      </dl>

      <div>
        <button type="button" aria-expanded={showAnswers} aria-controls="placement-answers" onClick={() => setShowAnswers((s) => !s)}
          className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
          <Icon name="chevron" className={`size-4 transition ${showAnswers ? '-rotate-90' : 'rotate-90'}`} />
          {showAnswers ? t('placement.hide_answers') : t('placement.view_answers')}
        </button>
        {showAnswers && (
          <ol id="placement-answers" className="mt-3 divide-y divide-ink/6 rounded-xl border border-ink/8">
            {result.questions.map((r) => (
              <li key={r.question_id} className="flex items-start gap-3 px-4 py-3 text-sm">
                <span className="w-6 shrink-0 pt-0.5 text-center tabular-nums text-ink/45">{n(r.position)}</span>
                <span dir="auto" className="min-w-0 flex-1 text-ink/85">{r.prompt}</span>
                <span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${r.is_correct ? 'bg-brand-50 text-brand-700' : r.answered ? 'bg-danger/10 text-danger' : 'bg-ink/6 text-ink/60'}`}>
                  <Icon name={r.is_correct ? 'check' : 'close'} className="size-3.5" />
                  {r.is_correct ? t('placement.right') : r.answered ? t('placement.wrong') : t('placement.unanswered_mark')}
                </span>
              </li>
            ))}
          </ol>
        )}
      </div>

      <StepFooter>
        <span />
        <PrimaryButton onClick={onContinue}>{t('placement.continue')}<Icon name="chevron" className="size-4 rtl:rotate-180" /></PrimaryButton>
      </StepFooter>
    </section>
  )
}
