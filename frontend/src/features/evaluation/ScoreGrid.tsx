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
import { IconButton, Notice, SecondaryButton, TextInput, SURFACE } from '../../components/ui'
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

/**
 * From xl the header and every row share one column template (student · four scores · total · actions),
 * so each score sits exactly under its heading and the table fills the card. Below xl a row stacks.
 */
const COLS = 'xl:grid xl:grid-cols-[minmax(13rem,1fr)_repeat(4,minmax(5.5rem,7.5rem))_6.5rem_5.75rem] xl:items-center xl:gap-x-3'

export const isComplete =(d: Draft) => CRITERIA.every((c) => d[c] !== null)

/** Where a row stands: scored and saved, edited since the last save, or not scored yet. */
function rowState(d: Draft, saved: SavedScore | null | undefined): 'saved' | 'changed' | 'empty' {
  const touched = CRITERIA.some((c) => d[c] !== (saved?.[c] ?? null)) || d.note !== (saved?.note ?? '') || d.progress.length > 0
  if (touched) return 'changed'
  return saved ? 'saved' : 'empty'
}

const STATE_DOT = { saved: 'bg-brand-600', changed: 'bg-gold-500', empty: 'bg-ink/20' } as const

/** One list row per student: four 0–10 scores and the total inline, then a collapsible panel for the note, optional ledger ranges, send-to-guardian and suggestion actions. */
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
  // Details panel per row: an explicit toggle wins, otherwise the row follows "show all details".
  const [expanded, setExpanded] = useState<Record<number, boolean>>({})
  const [showAll, setShowAll] = useState(false)

  const send = useMutation({
    mutationFn: (evaluationId: number) => evaluationsApi.send(evaluationId),
    onSuccess: (_d, evaluationId) => { setSentIds((ids) => [...ids, evaluationId]); setNotice(t('send_done')) },
  })

  const byStudent = new Map<number, Suggestion[]>()
  for (const s of suggestions) byStudent.set(s.student_id, [...(byStudent.get(s.student_id) ?? []), s])

  return (
    <>
      {notice && <Notice>{notice}</Notice>}
      <section aria-labelledby="score-roster-title" className={SURFACE}>
        <div className="flex flex-wrap items-center gap-2 border-b border-ink/6 px-4 py-3 sm:px-5">
          <h2 id="score-roster-title" className="text-base font-semibold text-ink">
            {t('students')} <span className="ms-1 text-sm font-normal text-ink/45">({formatNumber(students.length, locale)})</span>
          </h2>
          <button type="button" aria-pressed={showAll} className="ms-auto text-sm font-medium text-brand-700 hover:underline"
            onClick={() => { setShowAll((v) => !v); setExpanded({}) }}>
            {showAll ? t('hide_all_details') : t('show_all_details')}
          </button>
        </div>
        {/* Column headers from xl, where the scores sit inline; they replace the per-row labels there. */}
        <div aria-hidden="true" className={`hidden border-b border-ink/6 bg-page/60 px-5 py-2 text-xs font-medium text-ink/55 ${COLS}`}>
          <span>{t('student')}</span>
          {CRITERIA.map((c) => <span key={c} className="truncate text-center">{t(`criteria.${c}`)}</span>)}
          <span className="text-center">{t('total')}</span>
          <span />
        </div>
        <ul className="divide-y divide-ink/6">
          {students.map((st) => {
            const d = drafts[st.id]
            if (!d) return null
            const done = saved[st.id]
            const total = isComplete(d) ? CRITERIA.reduce((a, c) => a + (d[c] ?? 0), 0) : null
            const anyLow = CRITERIA.some((c) => d[c] !== null && (d[c] as number) < threshold)
            const state = rowState(d, done)
            const sent = done && (done.sent_to_guardian_at || sentIds.includes(done.id))
            const sug = byStudent.get(st.id) ?? []
            const open = expanded[st.id] ?? showAll
            const filled = (d.note ? 1 : 0) + d.progress.length
            const panel = `score-details-${st.id}`
            const toggleLabel = open ? t('hide_details') : t('show_details')

            return (
              <li key={st.id} className="px-4 py-3 sm:px-5">
                <div className={`flex flex-wrap items-center gap-x-3 gap-y-2 ${COLS}`}>
                  {/* flex-1 from a 0 basis: the name truncates so the total and actions stay on its line on phones. */}
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    {/* Status dot on the avatar: saved, changed since the last save, or not scored yet. */}
                    <span className="relative shrink-0" title={t(`row_state.${state}`)}>
                      <Avatar name={st.full_name} initial={st.initial} src={st.photo_url} gender={st.gender} size="sm" />
                      <span className={`absolute -bottom-0.5 -end-0.5 size-3 rounded-full ring-2 ring-white ${STATE_DOT[state]}`} aria-hidden="true" />
                    </span>
                    <span className="min-w-0">
                      <Link to={`/students/${st.id}`} dir="auto" title={st.full_name} className="block truncate font-semibold text-ink hover:text-brand-700">{st.full_name}</Link>
                      <span className={`block text-xs ${state === 'changed' ? 'text-gold-700' : 'text-ink/45'}`}>{t(`row_state.${state}`)}</span>
                    </span>
                  </div>

                  {/* Phones: scores drop to their own line under the name and actions. From xl each one is a column of the row. */}
                  <div className="order-last grid w-full grid-cols-2 gap-2 sm:grid-cols-4 xl:contents">
                    {CRITERIA.map((c) => (
                      <ScoreSelect key={c} id={`s-${st.id}-${c}`} label={t(`criteria.${c}`)} value={d[c]} threshold={threshold} locale={locale} labelClassName="xl:sr-only"
                        onChange={(v) => onChange(st.id, { [c]: v } as Partial<Draft>)} />
                    ))}
                  </div>

                  {/* Total: green when every score is at or above the low mark, gold when one is below it. */}
                  <span className={`min-w-16 rounded-lg px-2.5 py-2 text-center text-sm tabular-nums xl:min-w-0 ${total === null ? 'bg-ink/5 text-ink/40' : anyLow ? 'bg-gold-500/12 text-gold-700' : 'bg-brand-50 text-brand-700'}`} title={t('total')}>
                    <span className="sr-only">{t('total')}: </span>
                    <b className="text-base">{total === null ? '—' : formatNumber(total, locale)}</b><span className="opacity-70">/{formatNumber(40, locale)}</span>
                  </span>

                  <div className="flex items-center gap-1 xl:justify-end">
                    {done && (
                      sent
                        ? <span className="inline-grid size-10 place-items-center rounded-lg text-brand-700" title={t('sent')}><Icon name="check" className="size-4" /><span className="sr-only">{t('sent')}</span></span>
                        : <IconButton icon="messages" size="md" label={send.isPending && send.variables === done.id ? t('sending') : t('send')} disabled={send.isPending} onClick={() => send.mutate(done.id)} />
                    )}
                    <button type="button" aria-expanded={open} aria-controls={panel} aria-label={toggleLabel} title={toggleLabel}
                      onClick={() => setExpanded((e) => ({ ...e, [st.id]: !open }))}
                      className="relative inline-grid size-10 shrink-0 place-items-center rounded-lg text-ink/55 transition hover:bg-ink/5 hover:text-ink">
                      <Icon name="edit" className="size-4" />
                      {filled > 0 && <span className="absolute end-1.5 top-1.5 size-2 rounded-full bg-brand-600" />}
                    </button>
                  </div>
                </div>

                {open && (
                  <div id={panel} className="mt-3 space-y-3 rounded-xl bg-page/70 p-3">
                    <TextInput label={t('note')} value={d.note} onChange={(e) => onChange(st.id, { note: e.target.value })} dir="auto" />
                    {withProgress && (
                      <div className="space-y-2">
                        <p className="text-sm font-medium text-ink/75">{t('ledger')}</p>
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
      </section>

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
