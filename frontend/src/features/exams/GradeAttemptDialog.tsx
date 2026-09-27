import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { examsApi, fetchMediaUrl, type Answer, type Question } from '../../api/exams'
import { parseApiError } from '../../api/client'
import { Badge, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'

function AudioPlayer({ url }: { url: string }) {
  const [src, setSrc] = useState<string | null>(null)
  useEffect(() => {
    let revoke: string | null = null
    void fetchMediaUrl(url).then((u) => { revoke = u; setSrc(u) })
    return () => { if (revoke) URL.revokeObjectURL(revoke) }
  }, [url])
  return src ? <audio controls src={src} className="w-full" /> : <p className="text-xs text-ink/50">…</p>
}

function renderAnswer(q: Question, a: Answer | undefined, t: (k: string) => string) {
  const v = a?.answer as Record<string, unknown> | null | undefined
  if (!v) return <span className="text-ink/45">{t('grading.no_answer')}</span>
  switch (q.type) {
    case 'mcq': return <span dir="auto">{q.options?.find((o) => o.key === v.key)?.text ?? String(v.key)}</span>
    case 'true_false': return <span>{v.value ? t('questions.true') : t('questions.false')}</span>
    case 'complete_verse': return <span dir="rtl" className="font-display text-lg">{String(v.text ?? '')}</span>
    case 'order_verses': return <ol className="list-decimal ps-5">{(v.order as string[] | undefined ?? []).map((k) => <li key={k} dir="rtl" className="font-display">{q.options?.find((o) => o.key === k)?.text}</li>)}</ol>
    default: return a?.audio_url ? <AudioPlayer url={a.audio_url} /> : <span className="text-ink/45">{t('grading.no_answer')}</span>
  }
}

/** Grade one online attempt: objective answers show their auto result; recitation gets a manual score and note. */
export default function GradeAttemptDialog({ examId, attemptId, onClose, onDone }: { examId: number; attemptId: number; onClose: () => void; onDone: () => void }) {
  const { t, i18n } = useTranslation('exams')
  const q = useQuery({ queryKey: ['exam-attempt', examId, attemptId], queryFn: () => examsApi.attempt(examId, attemptId) })
  const [scores, setScores] = useState<Record<number, { score: number; note: string }>>({})
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!q.data?.answers) return
    setScores(Object.fromEntries(q.data.answers.map((a) => [a.id, { score: a.score ?? 0, note: a.grader_note ?? '' }])))
  }, [q.data])

  const save = useMutation({
    mutationFn: () => examsApi.grade(examId, attemptId, Object.entries(scores).map(([id, s]) => ({ answer_id: Number(id), score: s.score, grader_note: s.note || null }))),
    onSuccess: onDone,
    onError: (e) => setError(parseApiError(e).message),
  })

  const a = q.data
  const questions: Question[] = a?.questions ?? a?.answers?.map((x) => x.question).filter(Boolean) as Question[] ?? []

  return (
    <Modal wide title={a?.student?.full_name ?? t('grading.grade')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('form.cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} disabled={!a?.answers?.length} onClick={() => save.mutate()}>{t('grading.save_grades')}</PrimaryButton></>}>
      {error && <Notice tone="error">{error}</Notice>}
      {!a ? <LoadingState /> : (
        <ol className="space-y-4">
          {questions.map((qq, i) => {
            const ans = a.answers?.find((x) => x.question_id === qq.id)
            const s = ans ? scores[ans.id] : undefined
            return (
              <li key={qq.id} className="rounded-xl border border-ink/10 p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <p dir="auto" className="font-medium text-ink"><span className="text-ink/45">{formatNumber(i + 1, i18n.language)}. </span>{qq.prompt}</p>
                  <span className="flex gap-1">
                    <Badge>{t(`questions.types.${qq.type}`)}</Badge>
                    {qq.type !== 'recitation' && ans && ans.is_correct !== undefined && ans.is_correct !== null && <Badge tone={ans.is_correct ? 'brand' : 'danger'}>{ans.is_correct ? t('grading.correct') : t('grading.wrong')}</Badge>}
                  </span>
                </div>
                <div className="mt-2 text-sm text-ink/80"><span className="text-xs text-ink/50">{t('grading.answer')}: </span>{renderAnswer(qq, ans, t)}</div>
                {ans && s && (
                  <div className="mt-2 grid gap-2 sm:grid-cols-4">
                    <TextInput label={`${t('grading.score')} / ${formatNumber(qq.marks, i18n.language)}`} type="number" min={0} max={qq.marks} value={s.score}
                      onChange={(e) => setScores({ ...scores, [ans.id]: { ...s, score: Math.min(qq.marks, Math.max(0, Number(e.target.value))) } })} />
                    <TextInput className="sm:col-span-3" label={t('grading.grader_note')} value={s.note} onChange={(e) => setScores({ ...scores, [ans.id]: { ...s, note: e.target.value } })} dir="auto" />
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
