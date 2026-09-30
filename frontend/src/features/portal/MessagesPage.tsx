import { useInfiniteQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { portalApi } from '../../api/portal'
import { M_BTN_SECONDARY, MEmpty, MListSkeleton, Pill } from '../../components/mobile/atoms'
import { formatDate } from '../../lib/format'
import PortalLayout from './PortalLayout'
import { firstName, usePortalRole } from './hooks'
import { PortalError } from './shared'

/** WhatsApp messages the centre sent to this family (/my-messages), newest first, read-only. */
export default function MessagesPage() {
  const { t, i18n } = useTranslation('portal')
  const locale = i18n.language
  const role = usePortalRole()
  const q = useInfiniteQuery({
    queryKey: ['portal-messages', locale],
    queryFn: ({ pageParam }) => portalApi.messages(pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  })
  const rows = q.data?.pages.flatMap((p) => p.data) ?? []
  const title = t('messages.title')
  const when = (iso: string) => formatDate(iso, locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })

  const body = (
    <div className="space-y-4">
      <p className="text-[13px] text-ink/65">{t('messages.intro')}</p>
      {q.isError ? <PortalError onRetry={() => void q.refetch()} /> : q.isLoading ? <MListSkeleton rows={4} /> : rows.length === 0 ? (
        <div className="rounded-card border border-ink/10 bg-white shadow-card"><MEmpty icon="messages" text={t('messages.empty')} /></div>
      ) : (
        <ul className="space-y-3" aria-label={title}>
          {rows.map((m) => (
            <li key={m.id} className="rounded-card border border-ink/10 bg-white p-4 shadow-card">
              <div className="mb-2 flex items-center justify-between gap-3">
                {m.student ? <Pill tone="ok"><bdi>{firstName(m.student.full_name)}</bdi></Pill> : <Pill>{t('messages.general')}</Pill>}
                {m.sent_at && <span className="text-[13px] tabular-nums text-ink/65">{when(m.sent_at)}</span>}
              </div>
              <p className="whitespace-pre-line break-words text-[15px] leading-6 text-ink" dir="auto">{m.body}</p>
            </li>
          ))}
        </ul>
      )}
      {q.hasNextPage && (
        <button type="button" onClick={() => void q.fetchNextPage()} disabled={q.isFetchingNextPage} className={`${M_BTN_SECONDARY} w-full`}>
          {q.isFetchingNextPage ? t('loading') : t('load_more')}
        </button>
      )}
    </div>
  )

  return role === 'guardian'
    ? <PortalLayout><h1 className="mb-4 font-display text-[22px] leading-7 text-brand-900 lg:text-3xl">{title}</h1>{body}</PortalLayout>
    : <PortalLayout title={title} back="/my-account" breadcrumb={[{ label: t('tabs.account'), to: '/my-account' }, { label: title }]}>{body}</PortalLayout>
}
