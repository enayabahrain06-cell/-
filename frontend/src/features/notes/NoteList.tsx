import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { notesApi, type NewNote, type Note, type NoteFilters } from '../../api/education'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { Badge, EmptyCard, ErrorState, IconButton, LoadingState, Notice, PrimaryButton, SecondaryButton, SURFACE, TextArea } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'

type Msg = { tone: 'success' | 'error'; text: string } | null

/**
 * One list of notes (U9) for the given filters: the add form on top when `add` is given, then the notes, pinned
 * first. Authors (and managers) pin, edit and delete; `readOnly` hides every action (the "view" screens).
 */
export default function NoteList({ filters, add, readOnly = false, showTarget = false, emptyText }: {
  filters: NoteFilters
  add?: Omit<NewNote, 'body'>
  readOnly?: boolean
  /** Show what the note is about (student, level, subject) — for lists that mix targets. */
  showTarget?: boolean
  emptyText?: string
}) {
  const { t, i18n } = useTranslation('notes')
  const locale = i18n.language
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [body, setBody] = useState('')
  const [pinned, setPinned] = useState(false)
  const [msg, setMsg] = useState<Msg>(null)
  const q = useQuery({ queryKey: ['notes', filters, page], queryFn: () => notesApi.list({ ...filters, page }), placeholderData: keepPreviousData })
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['notes'] }); void qc.invalidateQueries({ queryKey: ['note-students'] }); void qc.invalidateQueries({ queryKey: ['student-timeline'] }) }
  const onError = (e: unknown) => { const p = parseApiError(e); setMsg({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) }

  const create = useMutation({
    mutationFn: () => notesApi.create({ ...add!, body, pinned }),
    onSuccess: (r) => { setBody(''); setPinned(false); setMsg({ tone: 'success', text: r.message }); refresh() },
    onError,
  })

  return (
    <div className="space-y-3">
      {add && !readOnly && (
        <section className={`${SURFACE} space-y-3 p-4`} aria-label={t('add')}>
          <TextArea label={t('new_note')} rows={3} dir="auto" value={body} onChange={(e) => setBody(e.target.value)} />
          <div className="flex flex-wrap items-center gap-3">
            {add.scope === 'general' && (
              <label className="flex items-center gap-2 text-sm text-ink/80">
                <input type="checkbox" className="size-4 accent-brand-700" checked={pinned} onChange={(e) => setPinned(e.target.checked)} />
                {t('pin')}
              </label>
            )}
            <PrimaryButton className="ms-auto" disabled={!body.trim()} loading={create.isPending} onClick={() => create.mutate()}>
              <Icon name="plus" className="size-4" />{t('add')}
            </PrimaryButton>
          </div>
        </section>
      )}
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} /> : (q.data?.data.length ?? 0) === 0 ? (
        <EmptyCard icon="edit" title={emptyText ?? t('empty')} />
      ) : (
        <>
          <p className="text-sm text-ink/55">{t('count', { count: q.data!.meta.total, n: formatNumber(q.data!.meta.total, locale) })}</p>
          <ul className="space-y-2">
            {q.data!.data.map((n) => <NoteCard key={n.id} note={n} readOnly={readOnly} showTarget={showTarget} onDone={(m) => { setMsg(m); refresh() }} />)}
          </ul>
          {q.data!.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3">
              <SecondaryButton disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('prev')}</SecondaryButton>
              <span className="text-sm tabular-nums text-ink/60">{t('page', { n: formatNumber(page, locale), of: formatNumber(q.data!.meta.last_page, locale) })}</span>
              <SecondaryButton disabled={page >= q.data!.meta.last_page} onClick={() => setPage((p) => p + 1)}>{t('next')}</SecondaryButton>
            </div>
          )}
        </>
      )}
    </div>
  )
}

function NoteCard({ note, readOnly, showTarget, onDone }: { note: Note; readOnly: boolean; showTarget: boolean; onDone: (m: Msg) => void }) {
  const { t, i18n } = useTranslation('notes')
  const locale = i18n.language
  const [editing, setEditing] = useState(false)
  const [body, setBody] = useState(note.body)
  const onError = (e: unknown) => { const p = parseApiError(e); onDone({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) }
  const update = useMutation({
    mutationFn: (d: { body?: string; pinned?: boolean }) => notesApi.update(note.id, d),
    onSuccess: (r) => { setEditing(false); onDone({ tone: 'success', text: r.message }) },
    onError,
  })
  const remove = useMutation({ mutationFn: () => notesApi.remove(note.id), onSuccess: (r) => onDone({ tone: 'success', text: r.message }), onError })
  const canEdit = note.can_edit && !readOnly
  const target = note.student ? note.student.full_name : note.level_subject ? `${note.level_subject.subject.name}، ${note.level_subject.level.name}` : note.level?.name

  return (
    <li className={`${SURFACE} p-4 ${note.pinned ? 'border-gold-500/40 bg-gold-500/5' : ''}`}>
      <div className="flex flex-wrap items-start gap-2">
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex flex-wrap items-center gap-1.5 text-xs text-ink/55">
            {note.pinned && <Badge tone="gold"><Icon name="pin" className="size-3" />{t('pinned')}</Badge>}
            {showTarget && target && (note.student
              ? <Link to={`/students/${note.student.id}?tab=notes`} dir="auto" className="font-medium text-brand-700 hover:underline">{target}</Link>
              : <Badge tone="info"><bdi>{target}</bdi></Badge>)}
            {note.lesson && <Badge><bdi>{note.lesson.name}</bdi></Badge>}
            {note.created_at && <span>{formatDate(note.created_at, locale, { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
            <span>{note.author ? <bdi>{note.author.name}</bdi> : t('no_author')}</span>
          </div>
          {editing ? (
            <TextArea label={t('edit')} hideLabel rows={3} dir="auto" value={body} onChange={(e) => setBody(e.target.value)} />
          ) : (
            <p dir="auto" className="whitespace-pre-line text-sm text-ink">{note.body}</p>
          )}
        </div>
        {canEdit && !editing && (
          <div className="flex shrink-0">
            <IconButton icon="pin" label={note.pinned ? t('unpin') : t('pin')} disabled={update.isPending} onClick={() => update.mutate({ pinned: !note.pinned })} />
            <IconButton icon="edit" label={t('edit')} onClick={() => { setBody(note.body); setEditing(true) }} />
            <IconButton icon="trash" tone="danger" label={t('delete')} disabled={remove.isPending} onClick={() => { if (window.confirm(t('delete_confirm'))) remove.mutate() }} />
          </div>
        )}
      </div>
      {editing && (
        <div className="mt-3 flex justify-end gap-2">
          <SecondaryButton onClick={() => setEditing(false)}>{t('cancel')}</SecondaryButton>
          <PrimaryButton disabled={!body.trim()} loading={update.isPending} onClick={() => update.mutate({ body })}>{t('save')}</PrimaryButton>
        </div>
      )}
    </li>
  )
}
