import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { studentsApi, type PlacementAnswer, type PlacementRecord } from '../../../api/students'
import { parseApiError } from '../../../api/client'
import Icon from '../../../components/Icon'
import { Badge, Card, CardTitle, EmptyCard, ErrorState, LoadingState, TableWrap, TABLE_HEAD } from '../../../components/ui'
import { formatDate, formatNumber, formatPercent } from '../../../lib/format'

/** Placement test from the student's registration: score, the three levels (declared, recommended, confirmed) and every answer. */
export default function PlacementTab({ studentId }: { studentId: number }) {
  const { t, i18n } = useTranslation('students')
  const q = useQuery({ queryKey: ['student-placement', studentId, i18n.language], queryFn: () => studentsApi.placement(studentId) })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  if (q.data.length === 0) return <EmptyCard icon="exams" title={t('placement_tab.empty')} body={t('placement_tab.empty_body')} />

  return <div className="space-y-4">{q.data.map((r) => <PlacementCard key={r.attempt_id} record={r} />)}</div>
}

function PlacementCard({ record: r }: { record: PlacementRecord }) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const date = (iso: string | null) => (iso ? formatDate(iso, locale, { day: 'numeric', month: 'long', year: 'numeric' }) : '—')

  return (
    <Card aria-labelledby={`placement-${r.attempt_id}`}>
      <CardTitle id={`placement-${r.attempt_id}`} actions={<span className="text-xs text-ink/50" dir="ltr">{r.request_no}</span>}>
        <span dir="auto">{r.exam_name}</span>
      </CardTitle>

      <div className="grid gap-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
        <div className="rounded-xl bg-brand-50 p-4 text-center">
          <p className="text-xs text-brand-700/80">{t('placement_tab.score')}</p>
          <p className="mt-1 text-3xl font-semibold tabular-nums text-brand-700">{formatPercent(r.percent, locale)}</p>
          <p className="mt-1 text-xs tabular-nums text-ink/60">{t('placement_tab.marks', { score: n(r.score), total: n(r.total_marks) })}</p>
          <p className="text-xs tabular-nums text-ink/60">{t('placement_tab.correct', { correct: n(r.correct), total: n(r.total_questions) })}</p>
        </div>
        <dl className="grid gap-2 text-sm sm:grid-cols-3">
          <Level label={t('placement_tab.declared')} value={r.declared_level_label} />
          <Level label={t('placement_tab.recommended')} value={r.recommended_level_label} tone="brand" />
          <Level label={t('placement_tab.final')} value={r.final_level_label ?? t('placement_tab.not_confirmed')} tone={r.final_level ? 'info' : undefined}
            note={r.level_confirmed_by ? t('placement_tab.confirmed_by', { name: r.level_confirmed_by, date: date(r.level_confirmed_at) }) : undefined} />
          <div className="rounded-lg bg-page/70 px-3 py-2"><dt className="text-xs text-ink/50">{t('placement_tab.date')}</dt><dd className="text-ink">{date(r.submitted_at)}</dd></div>
          <div className="rounded-lg bg-page/70 px-3 py-2"><dt className="text-xs text-ink/50">{t('placement_tab.attempt')}</dt><dd className="tabular-nums text-ink">{r.attempt_no ? n(r.attempt_no) : '—'}</dd></div>
        </dl>
      </div>

      <h3 className="mb-2 mt-5 text-sm font-semibold text-ink">{t('placement_tab.answers')}</h3>
      <TableWrap>
        <table className="w-full min-w-[40rem] text-sm">
          <thead className={TABLE_HEAD}>
            <tr>
              <th className="px-3 py-2 text-start font-medium">#</th>
              <th className="px-3 py-2 text-start font-medium">{t('placement_tab.question')}</th>
              <th className="px-3 py-2 text-start font-medium">{t('placement_tab.answer')}</th>
              <th className="px-3 py-2 text-start font-medium">{t('placement_tab.key')}</th>
              <th className="px-3 py-2 text-end font-medium">{t('placement_tab.mark')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink/6">
            {r.answers.map((a) => (
              <tr key={a.position} className={a.is_correct ? '' : 'bg-danger/5'}>
                <td className="px-3 py-2 align-top tabular-nums text-ink/55">{n(a.position)}</td>
                <td className="px-3 py-2 align-top">
                  <p dir="auto" className="text-ink">{a.prompt}</p>
                  {(a.category || a.difficulty) && (
                    <span className="mt-1 flex flex-wrap gap-1">
                      {a.category && <Badge tone="info"><span dir="auto">{a.category}</span></Badge>}
                      {a.difficulty && <Badge>{t(`placement_tab.difficulty.${a.difficulty}`)}</Badge>}
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 align-top">
                  <span className={`inline-flex items-start gap-1.5 ${a.is_correct ? 'text-brand-700' : 'text-danger'}`}>
                    <Icon name={a.is_correct ? 'check' : 'close'} className="mt-0.5 size-4 shrink-0" />
                    <span className="sr-only">{a.is_correct ? t('placement_tab.right') : t('placement_tab.wrong')}</span>
                    <AnswerText a={a} value={a.answer} />
                  </span>
                </td>
                <td className="px-3 py-2 align-top text-ink/75"><AnswerText a={a} value={a.correct_answer} /></td>
                <td className="px-3 py-2 text-end align-top tabular-nums text-ink/75">{n(a.score)} / {n(a.marks)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </TableWrap>
    </Card>
  )
}

function Level({ label, value, tone, note }: { label: string; value: string | null; tone?: 'brand' | 'info'; note?: string }) {
  return (
    <div className="rounded-lg bg-page/70 px-3 py-2">
      <dt className="text-xs text-ink/50">{label}</dt>
      <dd className="mt-0.5">{value ? (tone ? <Badge tone={tone}>{value}</Badge> : <span className="text-ink">{value}</span>) : '—'}</dd>
      {note && <dd className="mt-1 text-xs text-ink/55">{note}</dd>}
    </div>
  )
}

/** An answer (the student's or the key) in words: option text, true/false, typed text, or the verses in order. */
function AnswerText({ a, value }: { a: PlacementAnswer; value: PlacementAnswer['answer'] }) {
  const { t } = useTranslation('students')
  if (!value) return <span className="text-ink/45">{t('placement_tab.no_answer')}</span>
  const option = (key?: string) => a.options?.find((o) => o.key === key)?.text ?? key
  switch (a.type) {
    case 'mcq':
      return <span dir="auto">{option(value.key)}</span>
    case 'true_false':
      return <span>{value.value ? t('placement_tab.true') : t('placement_tab.false')}</span>
    case 'order_verses':
      return <ol className="list-decimal ps-4 font-quran" dir="rtl" lang="ar">{(value.order ?? []).map((k) => <li key={k}>{option(k)}</li>)}</ol>
    case 'complete_verse':
      return <span className="font-quran" dir="rtl" lang="ar">{value.text ?? value.value}</span>
    default:
      return <span dir="auto">{String(value.text ?? value.value ?? '')}</span>
  }
}
