import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { examsApi, type Attempt, type Exam, type PaperAnswerInput, type Question } from '../../api/exams'
import { parseApiError } from '../../api/client'
import SelectField from '../../components/SelectField'
import { Badge, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, inputClass } from '../../components/ui'
import { formatNumber } from '../../lib/format'

/** What the teacher enters per question: the written answer, or the recitation score. */
type Entry = { key?: string; value?: boolean; text?: string; positions?: Record<string, string>; score?: string }

function toEntry(q: Question, saved: Record<string, unknown> | null | undefined, score: number | null | undefined): Entry {
  if (q.type === 'recitation') return { score: score === null || score === undefined ? '' : String(score) }
  if (!saved) return {}
  if (q.type === 'order_verses') {
    const order = (saved.order as string[] | undefined) ?? []
    return { positions: Object.fromEntries(order.map((k, i) => [k, String(i + 1)])) }
  }
  return { key: saved.key as string | undefined, value: saved.value as boolean | undefined, text: saved.text as string | undefined }
}

/** The stored answer shape, or null when left blank (an incomplete verse order counts as blank). */
function toAnswer(q: Question, e: Entry): PaperAnswerInput['answer'] {
  switch (q.type) {
    case 'mcq': return e.key ? { key: e.key } : null
    case 'true_false': return e.value === undefined ? null : { value: e.value }
    case 'complete_verse': return e.text?.trim() ? { text: e.text.trim() } : null
    case 'order_verses': {
      const keys = (q.options ?? []).map((o) => o.key)
      const byPos = Object.entries(e.positions ?? {}).filter(([, p]) => p !== '').sort((a, b) => Number(a[1]) - Number(b[1]))
      const complete = byPos.length === keys.length && new Set(byPos.map(([, p]) => p)).size === keys.length
      return complete ? { order: byPos.map(([k]) => k) } : null
    }
    default: return null
  }
}

/**
 * Enter one student's written answers on a paper exam. Saving grades them on the server with the same rules
 * as online exams; recitation is the only part the teacher scores.
 */
export default function PaperAnswersDialog({ exam, studentId, studentName, attemptId, onClose, onDone }: {
  exam: Exam; studentId: number; studentName: string; attemptId: number | null; onClose: () => void; onDone: (attempt: Attempt) => void
}) {
  const { t, i18n } = useTranslation('exams')
  const n = (v: number) => formatNumber(v, i18n.language)
  const questions = useQuery({ queryKey: ['exam-questions', exam.id], queryFn: () => examsApi.questions(exam.id) })
  const saved = useQuery({ queryKey: ['exam-attempt', exam.id, attemptId], queryFn: () => examsApi.attempt(exam.id, attemptId!), enabled: attemptId !== null })
  const [entries, setEntries] = useState<Record<number, Entry>>({})
  const [error, setError] = useState<string | null>(null)

  // Start from the answers already on file, so re-opening a student corrects rather than retypes.
  useEffect(() => {
    if (!questions.data || (attemptId !== null && !saved.data)) return
    const byQuestion = new Map((saved.data?.answers ?? []).map((a) => [a.question_id, a]))
    setEntries(Object.fromEntries(questions.data.map((q) => [q.id, toEntry(q, byQuestion.get(q.id)?.answer, byQuestion.get(q.id)?.score)])))
  }, [questions.data, saved.data, attemptId])

  const set = (qid: number, patch: Entry) => setEntries((s) => ({ ...s, [qid]: { ...s[qid], ...patch } }))

  const save = useMutation({
    mutationFn: () => examsApi.paperAnswers(exam.id, studentId, (questions.data ?? []).map((q) => {
      const e = entries[q.id] ?? {}
      return q.type === 'recitation' ? { question_id: q.id, score: e.score === '' || e.score === undefined ? 0 : Number(e.score) } : { question_id: q.id, answer: toAnswer(q, e) }
    })),
    onSuccess: onDone,
    onError: (e) => setError(parseApiError(e).message),
  })

  const loading = questions.isLoading || (attemptId !== null && saved.isLoading)

  return (
    <Modal wide title={t('paper.title', { name: studentName })} onClose={onClose}
      footer={<>
        <SecondaryButton onClick={onClose}>{t('questions.cancel')}</SecondaryButton>
        <PrimaryButton loading={save.isPending} disabled={loading} onClick={() => { setError(null); save.mutate() }}>{t('paper.save')}</PrimaryButton>
      </>}>
      <p className="text-sm text-ink/60">{t('paper.hint')}</p>
      {error && <Notice tone="error">{error}</Notice>}
      {loading ? <LoadingState /> : (
        <ol className="space-y-3">
          {(questions.data ?? []).map((q, i) => {
            const e = entries[q.id] ?? {}
            return (
              <li key={q.id} className="rounded-xl border border-ink/10 p-3">
                <div className="mb-2 flex flex-wrap items-start gap-2">
                  <span className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">{n(i + 1)}</span>
                  <p dir="auto" className="min-w-0 flex-1 text-sm font-medium text-ink">{q.prompt}</p>
                  <Badge tone="gold">{n(q.marks)}</Badge>
                </div>

                {q.type === 'mcq' && (
                  <fieldset className="min-w-0 space-y-1.5">
                    <legend className="sr-only">{q.prompt}</legend>
                    {(q.options ?? []).map((o) => (
                      <label key={o.key} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm ${e.key === o.key ? 'border-brand-600 bg-brand-50' : 'border-ink/12 hover:bg-ink/5'}`}>
                        <input type="radio" name={`q-${q.id}`} checked={e.key === o.key} onChange={() => set(q.id, { key: o.key })} className="size-4 accent-brand-600" />
                        <span dir="auto" className="min-w-0">{o.text}</span>
                      </label>
                    ))}
                    {e.key && <button type="button" className="text-xs text-ink/55 hover:underline" onClick={() => set(q.id, { key: undefined })}>{t('paper.clear')}</button>}
                  </fieldset>
                )}

                {q.type === 'true_false' && (
                  <Segmented name={`q-${q.id}`} label={q.prompt} value={e.value === undefined ? null : e.value ? 'true' : 'false'}
                    options={[{ value: 'true', label: t('questions.true') }, { value: 'false', label: t('questions.false') }]}
                    onChange={(v) => set(q.id, { value: v === 'true' })} />
                )}

                {q.type === 'complete_verse' && (
                  <>
                    <label htmlFor={`q-${q.id}`} className="sr-only">{t('grading.answer')}</label>
                    <input id={`q-${q.id}`} dir="rtl" lang="ar" value={e.text ?? ''} onChange={(ev) => set(q.id, { text: ev.target.value })}
                      placeholder={t('paper.written')} className={inputClass('md', 'w-full font-quran text-lg')} />
                  </>
                )}

                {q.type === 'order_verses' && (
                  <fieldset className="min-w-0 space-y-1.5">
                    <legend className="mb-1 text-xs text-ink/55">{t('paper.order_hint')}</legend>
                    {(q.options ?? []).map((o) => (
                      <div key={o.key} className="flex items-center gap-2">
                        <SelectField label={t('paper.position')} hideLabel className="w-20 shrink-0" value={e.positions?.[o.key] ?? ''}
                          onChange={(ev) => set(q.id, { positions: { ...e.positions, [o.key]: ev.target.value } })}
                          options={[{ value: '', label: '—' }, ...(q.options ?? []).map((_, p) => ({ value: String(p + 1), label: n(p + 1) }))]} />
                        <span dir="rtl" lang="ar" className="min-w-0 flex-1 font-quran text-base text-ink">{o.text}</span>
                      </div>
                    ))}
                  </fieldset>
                )}

                {q.type === 'recitation' && (
                  <div className="flex flex-wrap items-center gap-2">
                    <label htmlFor={`q-${q.id}`} className="text-sm text-ink/70">{t('paper.recitation_score')}</label>
                    <input id={`q-${q.id}`} type="number" min={0} max={q.marks} inputMode="numeric" value={e.score ?? ''}
                      onChange={(ev) => set(q.id, { score: ev.target.value })} className={inputClass('sm', 'w-20 text-center tabular-nums')} />
                    <span className="text-xs text-ink/50">/ {n(q.marks)}</span>
                  </div>
                )}
              </li>
            )
          })}
        </ol>
      )}
    </Modal>
  )
}
