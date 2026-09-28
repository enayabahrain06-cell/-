import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { ProgressEntry } from '../../api/attendance'
import { CRITERIA, evaluationsApi, type Criterion, type SavedScore, type Suggestion } from '../../api/evaluations'
import type { StudentSummary } from '../../api/students'
import Avatar from '../../components/Avatar'
import Icon from '../../components/Icon'
import QuranRangePicker from '../../components/QuranRangePicker'
import { Badge, Notice, SecondaryButton, TextInput, SURFACE, ROW_MAIN } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import IssueDialog from './IssueDialog'
import ScoreSelect from './ScoreSelect'

export type Draft = Record<Criterion, number | null> & { note: string; progress: ProgressEntry[] }

export const emptyDraft = (saved?: SavedScore | null): Draft => ({
  memorization: saved?.memorization ?? null,
  tajweed: saved?.tajweed ?? null,
  revision: saved?.revision ?? null,
  behavior: saved?.behavior ?? null,
  note: saved?.note ?? '',
  progress: [],
})

export const isComplete = (d: Draft) => CRITERIA.every((c) => d[c] !== null)

/** One row per student: four 0–10 scores, note, optional ledger ranges, send-to-guardian and suggestion actions. */
export default function ScoreGrid({ students, drafts, saved, suggestions, threshold, withProgress, onChange, onSuggestionDone }: {
  students: StudentSummary[]
  drafts: Record<number, Draft>
  saved: Record<number, SavedScore | null>
  suggestions: Suggestion[]
  threshold: number
  withProgress: boolean
  onChange: (studentId: number, patch: Partial<Draft>) => void
  onSuggestionDone: (s: Suggestion) => void
}) {
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  const [dialog, setDialog] = useState<{ student: StudentSummary; suggestion: Suggestion } | null>(null)
  const [sentIds, setSentIds] = useState<number[]>([])
  const [notice, setNotice] = useState<string | null>(null)

  const send = useMutation({
    mutationFn: (evaluationId: number) => evaluationsApi.send(evaluationId),
    onSuccess: (_d, evaluationId) => { setSentIds((ids) => [...ids, evaluationId]); setNotice(t('send_done')) },
  })

  const byStudent = new Map<number, Suggestion[]>()
  for (const s of suggestions) byStudent.set(s.student_id, [...(byStudent.get(s.student_id) ?? []), s])

  return (
    <>
      {notice && <Notice>{notice}</Notice>}
      <ul className="space-y-3">
        {students.map((st) => {
          const d = drafts[st.id]
          if (!d) return null
          const done = saved[st.id]
          const total = isComplete(d) ? CRITERIA.reduce((a, c) => a + (d[c] ?? 0), 0) : null
          const sent = done && (done.sent_to_guardian_at || sentIds.includes(done.id))
          const sug = byStudent.get(st.id) ?? []

          return (
            <li key={st.id} className={`${SURFACE} p-4`}>
              <div className="flex flex-wrap items-center gap-3">
                <Avatar name={st.full_name} initial={st.initial} src={st.photo_url} gender={st.gender} size="sm" />
                <Link to={`/students/${st.id}`} dir="auto" className={`${ROW_MAIN} truncate font-semibold text-ink hover:text-brand-700`}>{st.full_name}</Link>
                {total !== null && <span className="rounded-lg bg-page px-2.5 py-1 text-sm tabular-nums text-ink/75">{t('total')}: <b className="text-ink">{formatNumber(total, locale)}</b>/{formatNumber(40, locale)}</span>}
                {done && (
                  sent
                    ? <Badge tone="brand"><Icon name="check" className="size-3.5" />{t('sent')}</Badge>
                    : <SecondaryButton disabled={send.isPending} onClick={() => send.mutate(done.id)}><Icon name="messages" className="size-4" />{send.isPending && send.variables === done.id ? t('sending') : t('send')}</SecondaryButton>
                )}
              </div>

              <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                {CRITERIA.map((c) => (
                  <ScoreSelect key={c} id={`s-${st.id}-${c}`} label={t(`criteria.${c}`)} value={d[c]} threshold={threshold} locale={locale}
                    onChange={(v) => onChange(st.id, { [c]: v } as Partial<Draft>)} />
                ))}
              </div>
              <TextInput className="mt-3" label={t('note')} value={d.note} onChange={(e) => onChange(st.id, { note: e.target.value })} dir="auto" />

              {withProgress && (
                <div className="mt-3 space-y-2">
                  {d.progress.map((p, i) => (
                    <QuranRangePicker key={i} idPrefix={`e-${st.id}-${i}`} value={p}
                      onChange={(v) => onChange(st.id, { progress: d.progress.map((x, j) => (j === i ? v : x)) })}
                      onRemove={() => onChange(st.id, { progress: d.progress.filter((_, j) => j !== i) })} />
                  ))}
                  {d.progress.length < 4 && (
                    <button type="button" className="text-sm font-medium text-brand-700 hover:underline"
                      onClick={() => onChange(st.id, { progress: [...d.progress, { type: 'memorized', surah_number: st.progress.surah ?? 114, from_ayah: 1, to_ayah: 1 }] })}>
                      + {t('add_range')}
                    </button>
                  )}
                </div>
              )}

              {sug.length > 0 && (
                <div className="mt-3 space-y-2 rounded-xl border-s-4 border-gold-500 bg-gold-500/6 p-3">
                  {sug.map((s) => (
                    <div key={`${s.criterion}`} className="flex flex-wrap items-center gap-2 text-sm">
                      <Icon name="alert" className="size-4 text-gold-700" />
                      <span className="flex-1 text-ink/85">{s.message}</span>
                      <SecondaryButton onClick={() => setDialog({ student: st, suggestion: s })}>{t('suggest.open')}</SecondaryButton>
                      <button type="button" className="text-xs text-ink/50 hover:text-ink" onClick={() => onSuggestionDone(s)}>{t('suggest.dismiss')}</button>
                    </div>
                  ))}
                </div>
              )}
            </li>
          )
        })}
      </ul>

      {dialog && (
        <IssueDialog
          studentId={dialog.student.id}
          studentName={dialog.student.full_name}
          initial={{ category: dialog.suggestion.category, evaluation_id: dialog.suggestion.evaluation_id, lesson_id: dialog.suggestion.lesson_id, severity: dialog.suggestion.score < 4 ? 'high' : 'medium' }}
          onClose={() => setDialog(null)}
          onDone={() => { onSuggestionDone(dialog.suggestion); setDialog(null); setNotice(t('suggest.opened')) }}
        />
      )}
    </>
  )
}
