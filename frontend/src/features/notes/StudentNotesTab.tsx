import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { notesApi, type TimelineItem, type TimelineKind } from '../../api/education'
import { parseApiError } from '../../api/client'
import { useTerm } from '../../app/term'
import Icon from '../../components/Icon'
import { Badge, EmptyCard, ErrorState, LoadingState, Notice, PrimaryButton, Segmented, SURFACE, TextArea, type Tone } from '../../components/ui'
import { formatDate } from '../../lib/format'

const KIND_TONE: Record<TimelineKind, Tone> = { note: 'brand', issue: 'danger', issue_note: 'gold', attendance: 'info', evaluation: 'muted' }
const KIND_ICON: Record<TimelineKind, string> = { note: 'edit', issue: 'alert', issue_note: 'history', attendance: 'attendance', evaluation: 'evaluation' }
type Filter = 'all' | TimelineKind

/**
 * الملاحظات on the student profile: the student's notes merged (read through, never copied) with their difficulties
 * and follow-up notes, attendance notes and evaluation notes, newest first. Staff with notes.manage add a note here.
 */
export default function StudentNotesTab({ studentId }: { studentId: number }) {
  const { t, i18n } = useTranslation('notes')
  const locale = i18n.language
  const qc = useQueryClient()
  const { selected, current } = useTerm()
  const termId = selected?.id ?? current?.id ?? null
  const [filter, setFilter] = useState<Filter>('all')
  const [body, setBody] = useState('')
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const q = useQuery({ queryKey: ['student-timeline', studentId], queryFn: () => notesApi.timeline(studentId) })
  const add = useMutation({
    mutationFn: () => notesApi.create({ academic_term_id: termId as number, scope: 'student', student_id: studentId, body }),
    onSuccess: (r) => { setBody(''); setMsg({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['student-timeline', studentId] }) },
    onError: (e) => { const p = parseApiError(e); setMsg({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  const items = q.data.data.filter((i) => filter === 'all' || i.kind === filter || (filter === 'issue' && i.kind === 'issue_note'))

  return (
    <div className="space-y-4">
      {q.data.can_add && termId && (
        <section className={`${SURFACE} space-y-3 p-4`} aria-label={t('add')}>
          <TextArea label={t('new_note')} rows={2} dir="auto" value={body} onChange={(e) => setBody(e.target.value)} />
          <div className="flex justify-end">
            <PrimaryButton disabled={!body.trim()} loading={add.isPending} onClick={() => add.mutate()}><Icon name="plus" className="size-4" />{t('add')}</PrimaryButton>
          </div>
        </section>
      )}
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <Segmented name="timeline-filter" size="sm" label={t('timeline.filter')} value={filter} onChange={setFilter}
          options={(['all', 'note', 'issue', 'attendance', 'evaluation'] as const).map((k) => ({ value: k, label: t(`timeline.kinds.${k}`) }))} />
      </div>
      {items.length === 0 ? <EmptyCard icon="edit" title={t('timeline.empty')} /> : (
        <ol className="relative space-y-3 border-s-2 border-ink/8 ps-4">
          {items.map((i) => <TimelineRow key={i.key} item={i} locale={locale} />)}
        </ol>
      )}
    </div>
  )
}

function TimelineRow({ item, locale }: { item: TimelineItem; locale: string }) {
  const { t } = useTranslation('notes')
  return (
    <li className={`${SURFACE} relative p-3`}>
      <span className="absolute -start-[1.45rem] top-4 grid size-5 place-items-center rounded-full bg-white ring-2 ring-ink/10" aria-hidden="true">
        <Icon name={KIND_ICON[item.kind]} className="size-3 text-ink/55" />
      </span>
      <div className="flex flex-wrap items-center gap-1.5 text-xs text-ink/55">
        <Badge tone={KIND_TONE[item.kind]}>{t(`timeline.kinds.${item.kind}`)}</Badge>
        {item.meta.pinned && <Badge tone="gold">{t('pinned')}</Badge>}
        {item.meta.category && <Badge><bdi>{item.meta.category}</bdi></Badge>}
        {item.meta.subject && <Badge><bdi>{item.meta.subject}</bdi></Badge>}
        {item.kind === 'attendance' && item.meta.status && <Badge>{t(`attendance:status.${item.meta.status}`, { defaultValue: item.meta.status })}</Badge>}
        {item.date && <span>{formatDate(item.date, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
        {item.lesson && <span><bdi>{item.lesson}</bdi></span>}
        {item.author && <span><bdi>{item.author}</bdi></span>}
      </div>
      <p dir="auto" className="mt-1.5 whitespace-pre-line text-sm text-ink">{item.body}</p>
    </li>
  )
}
