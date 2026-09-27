import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { attendanceMessagingApi } from '../../api/messages'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { EmptyState } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Notice, PrimaryButton, TABLE_HEAD, TableWrap } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { formatDateTime, StatusBadge } from './status'

/** Section 23 per-session delivery view: every reminder and notice, confirmations, excuses, and "send now". */
export default function SessionDeliveryPage() {
  const { sessionId } = useParams()
  const id = Number(sessionId)
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['session-delivery', id], queryFn: () => attendanceMessagingApi.session(id) })
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const refresh = () => void qc.invalidateQueries({ queryKey: ['session-delivery', id] })
  const sendNow = useMutation({ mutationFn: () => attendanceMessagingApi.sendNow(id), onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); refresh() }, onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }) })
  const rule = useMutation({
    mutationFn: (d: { reminders_enabled: boolean; second_reminder_enabled: boolean }) => attendanceMessagingApi.lessonRule(q.data!.session.lesson.id, d),
    onSuccess: refresh, onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  const d = q.data

  return (
    <div className="mx-auto max-w-7xl space-y-5">
      <Link to="/messages" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline"><Icon name="chevron" className="size-4 ltr:rotate-180" /> {t('delivery.back')}</Link>
      <header className="flex flex-wrap items-start gap-4">
        <div className="min-w-0 flex-1 space-y-1">
          <h1 className="font-display text-3xl text-ink">{t('delivery.title')}</h1>
          <p className="text-sm text-ink/60"><Link to={`/lessons/${d.session.lesson.id}`} dir="auto" className="text-brand-700 hover:underline">{d.session.lesson.name}</Link> · {formatDate(d.session.date, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · {d.session.start_time}</p>
        </div>
        {d.can_send && d.session.status === 'scheduled' && (
          <div className="text-end">
            <PrimaryButton loading={sendNow.isPending} onClick={() => sendNow.mutate()}><Icon name="messages" className="size-4" /> {t('delivery.send_now')}</PrimaryButton>
            <p className="mt-1 text-xs text-ink/50">{t('delivery.send_now_hint')}</p>
          </div>
        )}
      </header>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      <div className="grid gap-5 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardTitle>{t('delivery.messages')}</CardTitle>
          {d.messages.length === 0 ? <EmptyState size="sm" icon="messages" title={t('delivery.no_messages')} /> : (
            <TableWrap>
              <table className="w-full min-w-[36rem] text-sm">
                <thead className={TABLE_HEAD}>
                  <tr>
                    <th className="px-4 py-3 text-start font-medium">{t('delivery.columns.type')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('delivery.columns.recipient')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('delivery.columns.status')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('delivery.columns.time')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink/6">
                  {d.messages.map((m) => (
                    <tr key={m.id} title={m.body}>
                      <td className="px-4 py-3 text-ink">{t(`types.${m.type}`, { defaultValue: m.type })}</td>
                      <td className="px-4 py-3"><span dir="auto" className="block text-ink">{m.student?.full_name}</span><span dir="ltr" className="text-xs tabular-nums text-ink/50">{m.recipient_phone}</span>{m.recipient_type && <span className="ms-1 text-xs text-ink/45">· {t(`recipient.${m.recipient_type}`, { defaultValue: m.recipient_type })}</span>}</td>
                      <td className="px-4 py-3"><StatusBadge status={m.status} /></td>
                      <td className="px-4 py-3 text-xs text-ink/60">{m.sent_at ? t('delivery.sent_at', { when: formatDateTime(m.sent_at, locale) }) : m.scheduled_for ? t('delivery.scheduled_for', { when: formatDateTime(m.scheduled_for, locale) }) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableWrap>
          )}
        </Card>
        <div className="space-y-5">
          {can('lessons.manage') && (
            <Card>
              <CardTitle>{t('delivery.circle_rule')}</CardTitle>
              <div className="space-y-2">
                {(['reminders_enabled', 'second_reminder_enabled'] as const).map((k) => (
                  <label key={k} className="flex items-center gap-2 text-sm text-ink/80">
                    <input type="checkbox" className="size-4 accent-brand-600" checked={d.lesson_rule[k]} disabled={rule.isPending} onChange={(e) => rule.mutate({ ...d.lesson_rule, [k]: e.target.checked })} />
                    {t(k === 'reminders_enabled' ? 'delivery.reminders_on' : 'delivery.second_on')}
                  </label>
                ))}
              </div>
            </Card>
          )}
          <Card>
            <CardTitle>{t('delivery.confirmations')}</CardTitle>
            {d.confirmations.length === 0 ? <p className="text-sm text-ink/55">{t('delivery.no_confirmations')}</p> : (
              <ul className="space-y-1.5 text-sm">{d.confirmations.map((c, i) => <li key={i} className="flex items-center gap-2"><Icon name="check" className="size-4 text-brand-600" /><span dir="auto" className="flex-1">{c.student}</span><span className="text-xs text-ink/50">{formatDateTime(c.confirmed_at, locale)}</span></li>)}</ul>
            )}
          </Card>
          <Card>
            <CardTitle>{t('delivery.excuses')}</CardTitle>
            {d.excuses.length === 0 ? <p className="text-sm text-ink/55">{t('delivery.no_excuses')}</p> : (
              <ul className="space-y-2 text-sm">{d.excuses.map((e) => <li key={e.id}><p className="flex items-center gap-2"><span dir="auto" className="flex-1">{e.student?.full_name}</span><Badge tone={e.status === 'rejected' ? 'muted' : e.status === 'pending' ? 'gold' : 'brand'}>{t(`excuses.status.${e.status}`)}</Badge></p>{e.body && <p dir="auto" className="text-xs text-ink/60">{e.body}</p>}</li>)}</ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
