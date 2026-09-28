import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { attendanceMessagingApi, type ExcuseRow } from '../../api/messages'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import { EmptyState } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, PrimaryButton, SecondaryButton, Segmented, type Tone, ROW_MAIN } from '../../components/ui'
import { formatDateTime } from './status'

const EXCUSE_TONE: Record<ExcuseRow['status'], Tone> = { applied: 'brand', pending: 'gold', approved: 'brand', rejected: 'muted' }

/** Section 23: supervisor inbox of free-text replies, and excuses received after attendance was taken. */
export default function InboxTab() {
  const { can } = useAuth()
  return (
    <div className="space-y-5">
      {can('attendance.record') && <ExcusesCard />}
      {can('messages.view') && <InboxCard />}
    </div>
  )
}

function InboxCard() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const qc = useQueryClient()
  const [status, setStatus] = useState<'open' | 'resolved' | 'all'>('open')
  const [page, setPage] = useState(1)
  const q = useQuery({ queryKey: ['inbox', status, page], queryFn: () => attendanceMessagingApi.inbox(status, page) })
  const resolve = useMutation({ mutationFn: (id: number) => attendanceMessagingApi.resolve(id), onSuccess: () => void qc.invalidateQueries({ queryKey: ['inbox'] }) })

  return (
    <Card>
      <CardTitle actions={<Segmented name="inbox-status" size="sm" label="" value={status} onChange={(v) => { setStatus(v); setPage(1) }}
        options={(['open', 'resolved', 'all'] as const).map((v) => ({ value: v, label: t(`inbox.${v}`) }))} />}>
        {t('inbox.title')}{q.data && q.data.meta.open > 0 && <Badge tone="gold" className="ms-2">{q.data.meta.open}</Badge>}
      </CardTitle>
      <p className="mb-3 text-xs text-ink/55">{t('inbox.hint')}</p>
      {q.isLoading ? <LoadingState /> : q.isError || !q.data ? <ErrorState onRetry={() => void q.refetch()} /> : q.data.data.length === 0 ? (
        <EmptyState size="sm" icon="messages" title={t('inbox.empty')} body={t('inbox.empty_body')} />
      ) : (
        <>
          <ul className="divide-y divide-ink/6">
            {q.data.data.map((m) => (
              <li key={m.id} className="flex flex-wrap items-start gap-3 py-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700"><Icon name="messages" className="size-4" /></span>
                <div className={`${ROW_MAIN} space-y-1`}>
                  <p className="flex flex-wrap items-center gap-2 text-sm">
                    <span dir="auto" className="font-medium text-ink">{m.sender ?? m.student?.full_name ?? t('inbox.unknown')}</span>
                    <span dir="ltr" className="text-xs tabular-nums text-ink/50">{m.from_phone}</span>
                    <span className="text-xs text-ink/45">{formatDateTime(m.received_at, locale)}</span>
                  </p>
                  {m.student && <p className="text-xs text-ink/55">{t('inbox.student')}: <Link to={`/students/${m.student.id}`} dir="auto" className="text-brand-700 hover:underline">{m.student.full_name}</Link></p>}
                  <p dir="auto" className="whitespace-pre-line rounded-xl bg-page/70 px-3 py-2 text-sm text-ink/85">{m.body}</p>
                  {m.status === 'resolved' && m.handled_by && <p className="text-xs text-ink/45">{t('inbox.handled', { name: m.handled_by, when: m.handled_at ? formatDateTime(m.handled_at, locale) : '' })}</p>}
                </div>
                {m.status === 'open' && <SecondaryButton onClick={() => resolve.mutate(m.id)} disabled={resolve.isPending}><Icon name="check" className="size-4" /> {t('inbox.resolve')}</SecondaryButton>}
              </li>
            ))}
          </ul>
          <Pagination page={q.data.meta.current_page} lastPage={q.data.meta.last_page} total={q.data.meta.total} onPage={setPage} />
        </>
      )}
    </Card>
  )
}

function ExcusesCard() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['excuses', 'pending'], queryFn: () => attendanceMessagingApi.excuses('pending') })
  const review = useMutation({
    mutationFn: ({ id, decision }: { id: number; decision: 'approve' | 'reject' }) => attendanceMessagingApi.reviewExcuse(id, decision),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['excuses'] }),
  })
  return (
    <Card>
      <CardTitle>{t('excuses.title')}</CardTitle>
      <p className="mb-3 text-xs text-ink/55">{t('excuses.hint')}</p>
      {q.isLoading ? <LoadingState /> : !q.data || q.data.length === 0 ? <p className="text-sm text-ink/55">{t('excuses.empty')}</p> : (
        <ul className="divide-y divide-ink/6">
          {q.data.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center gap-3 py-3">
              <div className={`${ROW_MAIN} space-y-1`}>
                <p className="flex flex-wrap items-center gap-2 text-sm">
                  <span dir="auto" className="font-medium text-ink">{e.student?.full_name}</span>
                  <Badge tone={EXCUSE_TONE[e.status]}>{t(`excuses.status.${e.status}`)}</Badge>
                  {e.session && <Link to={`/messages/sessions/${e.session.id}`} className="text-xs text-brand-700 hover:underline"><span dir="auto">{e.session.lesson}</span> · {e.session.date}</Link>}
                </p>
                {e.body && <p dir="auto" className="text-sm text-ink/70">{e.body}</p>}
                <p className="text-xs text-ink/45">{formatDateTime(e.created_at, locale)}</p>
              </div>
              <div className="flex gap-2">
                <SecondaryButton disabled={review.isPending} onClick={() => review.mutate({ id: e.id, decision: 'reject' })}>{t('excuses.reject')}</SecondaryButton>
                <PrimaryButton disabled={review.isPending} onClick={() => review.mutate({ id: e.id, decision: 'approve' })}>{t('excuses.approve')}</PrimaryButton>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
