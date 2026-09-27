import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { MESSAGE_STATUSES, messagesApi, type MessageLog } from '../../api/messages'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import SelectField from '../../components/SelectField'
import { EmptyState } from '../../components/ornaments'
import { ErrorState, FilterBar, LoadingState, Modal, Notice, PrimaryButton, SearchInput, SecondaryButton, SURFACE, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { formatDateTime, STATUS_META, StatusBadge } from './status'

const FILTER_KEYS = ['status', 'type', 'phone', 'from', 'to', 'page'] as const

export default function LogTab() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const get = (k: (typeof FILTER_KEYS)[number]) => params.get(k) ?? ''
  const filters = { status: get('status'), type: get('type'), phone: get('phone'), from: get('from'), to: get('to'), page: Number(get('page')) || 1 }
  const [phone, setPhone] = useState(filters.phone)
  const [open, setOpen] = useState<MessageLog | null>(null)
  const [confirmAll, setConfirmAll] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  const set = (k: (typeof FILTER_KEYS)[number], v: string) => {
    const n = new URLSearchParams(params)
    if (v) n.set(k, v)
    else n.delete(k)
    if (k !== 'page') n.delete('page')
    setParams(n, { replace: true })
  }
  // Debounced phone search.
  useEffect(() => {
    const id = setTimeout(() => { if (phone !== filters.phone) set('phone', phone.trim()) }, 350)
    return () => clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [phone])

  const range = { from: filters.from || undefined, to: filters.to || undefined }
  const stats = useQuery({ queryKey: ['messages', 'stats', range], queryFn: () => messagesApi.stats(range) })
  const logs = useQuery({ queryKey: ['messages', 'logs', filters], queryFn: () => messagesApi.logs({ ...filters, per_page: 20 }), placeholderData: keepPreviousData })

  const resend = useMutation({
    mutationFn: (id: number) => messagesApi.resend(id),
    onSuccess: () => { setNotice({ tone: 'success', text: t('log.resent') }); void qc.invalidateQueries({ queryKey: ['messages'] }) },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const resendAll = useMutation({
    mutationFn: () => messagesApi.resendFailed(range),
    onSuccess: (r) => { setConfirmAll(false); setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['messages'] }) },
    onError: (e) => { setConfirmAll(false); setNotice({ tone: 'error', text: parseApiError(e).message }) },
  })

  const n = (v: number) => formatNumber(v, locale)
  const failed = stats.data?.by_status.failed ?? 0
  const hasFilters = FILTER_KEYS.some((k) => k !== 'page' && get(k))
  const typeOptions = Object.keys((t('types', { returnObjects: true }) as Record<string, string>) ?? {})
    .map((k) => ({ value: k, label: t(`types.${k}`) }))
    .sort((a, b) => a.label.localeCompare(b.label, locale))

  return (
    <div className="space-y-5">
      {/* Counts per status; a tile filters the list to that status. */}
      <section aria-label={t('stats.label')} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <StatTile label={t('stats.total')} value={stats.data ? n(stats.data.total) : '—'} icon="messages" active={!filters.status} onClick={() => set('status', '')} />
        {MESSAGE_STATUSES.filter((s) => (stats.data?.by_status[s] ?? 0) > 0 || ['sent', 'failed', 'queued'].includes(s)).map((s) => (
          <StatTile key={s} label={t(`status.${s}`)} value={stats.data ? n(stats.data.by_status[s] ?? 0) : '—'} icon={STATUS_META[s].icon}
            tone={s === 'failed' && failed > 0 ? 'danger' : undefined} active={filters.status === s} onClick={() => set('status', filters.status === s ? '' : s)} />
        ))}
      </section>

      <FilterBar label={t('filters.label')}>
        <SearchInput label={t('filters.phone')} value={phone} onChange={(e) => setPhone(e.target.value)} className="sm:w-56" dir="ltr" inputMode="tel" />
        <SelectField className="sm:w-44" label={t('filters.status')} value={filters.status} onChange={(e) => set('status', e.target.value)}
          options={[{ value: '', label: t('filters.any_status') }, ...MESSAGE_STATUSES.map((s) => ({ value: s, label: t(`status.${s}`) }))]} />
        <SelectField className="sm:w-56" label={t('filters.type')} value={filters.type} onChange={(e) => set('type', e.target.value)}
          options={[{ value: '', label: t('filters.any_type') }, ...typeOptions]} />
        <TextInput className="sm:w-40" type="date" label={t('filters.from')} value={filters.from} max={filters.to || undefined} onChange={(e) => set('from', e.target.value)} />
        <TextInput className="sm:w-40" type="date" label={t('filters.to')} value={filters.to} min={filters.from || undefined} onChange={(e) => set('to', e.target.value)} />
        {hasFilters && (
          <SecondaryButton onClick={() => { setPhone(''); setParams(params.get('tab') ? { tab: params.get('tab') as string } : {}, { replace: true }) }}>
            {t('filters.clear')}
          </SecondaryButton>
        )}
        {can('messages.manage') && failed > 0 && (
          <SecondaryButton className="sm:ms-auto" onClick={() => setConfirmAll(true)}>
            <Icon name="refresh" className="size-4" />
            {t('log.resend_all')} ({n(failed)})
          </SecondaryButton>
        )}
      </FilterBar>

      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      {logs.isLoading ? (
        <LoadingState />
      ) : logs.isError ? (
        <ErrorState message={t('error')} onRetry={() => void logs.refetch()} />
      ) : !logs.data?.data.length ? (
        <div className={SURFACE}><EmptyState icon="messages" title={t('log.empty')} body={t('log.empty_hint')} /></div>
      ) : (
        <>
          <ul className={`${SURFACE} divide-y divide-ink/6 ${logs.isFetching ? 'opacity-70' : ''}`}>
            {logs.data.data.map((m) => (
              <li key={m.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-brand-50/40">
                <div className="min-w-0 flex-1 basis-56">
                  <p dir="auto" className="truncate text-start font-medium text-ink">{m.student_name || t('log.no_name')}</p>
                  <p className="text-sm text-ink/55">
                    <span dir="ltr" className="tabular-nums">{m.recipient_phone}</span>
                    <span className="mx-1.5 text-ink/30" aria-hidden>·</span>
                    {t(`recipient.${m.recipient_type}`, { defaultValue: m.recipient_type })}
                  </p>
                </div>
                <div className="min-w-0 basis-40 text-sm">
                  <p className="text-ink/80">{t(`types.${m.type}`, { defaultValue: m.type_label ?? m.type })}</p>
                  {m.created_at && <time dateTime={m.created_at} className="text-xs text-ink/50">{formatDateTime(m.created_at, locale)}</time>}
                </div>
                <div className="flex basis-36 flex-col items-start gap-1">
                  <StatusBadge status={m.status} />
                  {m.attempts > 1 && <span className="text-xs text-ink/50">{t('log.attempts', { count: m.attempts, n: n(m.attempts) })}</span>}
                </div>
                {m.error && <p dir="auto" title={m.error} className="line-clamp-1 min-w-0 basis-full text-start text-xs text-danger sm:basis-48 sm:flex-1">{m.error}</p>}
                <div className="ms-auto flex gap-2">
                  <SecondaryButton className="px-2.5 py-1.5 text-xs" onClick={() => setOpen(m)}>{t('log.details')}</SecondaryButton>
                  {m.status === 'failed' && can('messages.manage') && (
                    <PrimaryButton className="px-2.5 py-1.5 text-xs" loading={resend.isPending && resend.variables === m.id} onClick={() => resend.mutate(m.id)}>
                      {t('log.resend')}
                    </PrimaryButton>
                  )}
                </div>
              </li>
            ))}
          </ul>
          <Pagination page={logs.data.meta.current_page} lastPage={logs.data.meta.last_page} total={logs.data.meta.total} onPage={(p) => set('page', String(p))} />
        </>
      )}

      {open && <DetailModal id={open.id} initial={open} onClose={() => setOpen(null)} />}
      {confirmAll && (
        <Modal title={t('log.resend_all_confirm_title')} onClose={() => setConfirmAll(false)}
          footer={<>
            <SecondaryButton onClick={() => setConfirmAll(false)}>{t('log.cancel')}</SecondaryButton>
            <PrimaryButton loading={resendAll.isPending} onClick={() => resendAll.mutate()}>{t('log.confirm')}</PrimaryButton>
          </>}>
          <p className="text-sm text-ink/75">{t('log.resend_all_confirm', { n: n(failed) })}</p>
        </Modal>
      )}
    </div>
  )
}

function StatTile({ label, value, icon, tone, active, onClick }: { label: string; value: string; icon: string; tone?: 'danger'; active?: boolean; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={active}
      className={`${SURFACE} p-4 text-start transition hover:bg-ink/[0.02] focus-visible:outline-2 focus-visible:outline-brand-500 ${active ? 'ring-2 ring-brand-500/40' : ''} ${tone === 'danger' ? 'bg-danger/5' : ''}`}>
      <span className="flex items-center justify-between gap-2">
        <span className="text-sm text-ink/60">{label}</span>
        <span className={`rounded-lg p-1.5 ${tone === 'danger' ? 'bg-danger/10 text-danger' : 'bg-brand-50 text-brand-700'}`}><Icon name={icon} className="size-4" /></span>
      </span>
      <span className="mt-2 block text-2xl font-semibold tabular-nums text-ink">{value}</span>
    </button>
  )
}

function DetailModal({ id, initial, onClose }: { id: number; initial: MessageLog; onClose: () => void }) {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['messages', 'log', id], queryFn: () => messagesApi.log(id), initialData: initial })
  const m = q.data
  const steps: [string, string | null][] = [
    ['created', m.created_at], ['scheduled', m.scheduled_for], ['sent', m.sent_at], ['delivered', m.delivered_at], ['read', m.read_at],
  ]
  const Row = ({ label, children }: { label: string; children: React.ReactNode }) => (
    <div className="grid grid-cols-3 gap-2 py-1.5 text-sm"><dt className="text-ink/55">{label}</dt><dd className="col-span-2 min-w-0 text-ink">{children}</dd></div>
  )

  return (
    <Modal title={t('detail.title')} onClose={onClose} wide footer={<SecondaryButton onClick={onClose}>{t('detail.close')}</SecondaryButton>}>
      <dl className="divide-y divide-ink/6">
        <Row label={t('detail.to')}>
          <span dir="auto">{m.student_name || t('log.no_name')}</span> · <span dir="ltr" className="tabular-nums">{m.recipient_phone}</span> · {t(`recipient.${m.recipient_type}`, { defaultValue: m.recipient_type })}
        </Row>
        <Row label={t('detail.type')}>{t(`types.${m.type}`, { defaultValue: m.type_label ?? m.type })}</Row>
        <Row label={t('detail.status')}><StatusBadge status={m.status} /></Row>
        <Row label={t('detail.attempts')}>{formatNumber(m.attempts, locale)}</Row>
        {m.template_key && <Row label={t('detail.template')}><span dir="ltr" className="font-mono text-xs">{m.template_key}</span></Row>}
        <Row label={t('detail.locale')}>{m.locale === 'en' ? 'English' : 'العربية'}</Row>
        {m.provider && <Row label={t('detail.provider')}>{m.provider}</Row>}
        {m.provider_message_id && <Row label={t('detail.provider_id')}><span dir="ltr" className="break-all font-mono text-xs">{m.provider_message_id}</span></Row>}
      </dl>

      <div>
        <h3 className="mb-1.5 text-sm font-medium text-ink/75">{t('detail.body')}</h3>
        <p dir="auto" className="whitespace-pre-wrap rounded-xl bg-page/70 p-3 text-start text-sm leading-relaxed text-ink">{m.body}</p>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-medium text-ink/75">{t('detail.timeline')}</h3>
        <ol className="space-y-2 border-s-2 border-ink/10 ps-4">
          {steps.map(([k, at]) => (
            <li key={k} className={`relative text-sm ${at ? 'text-ink' : 'text-ink/40'}`}>
              <span className={`absolute -start-[1.4rem] top-1.5 size-2.5 rounded-full ${at ? 'bg-brand-600' : 'bg-ink/15'}`} aria-hidden />
              <span className="font-medium">{t(`detail.${k}`)}</span>
              {at && <time dateTime={at} className="ms-2 text-ink/60">{formatDateTime(at, locale)}</time>}
            </li>
          ))}
        </ol>
      </div>

      {m.error && (
        <div>
          <h3 className="mb-1.5 text-sm font-medium text-danger">{t('detail.error')}</h3>
          <p dir="auto" className="whitespace-pre-wrap rounded-xl border border-danger/25 bg-danger/5 p-3 text-start text-sm text-danger">{m.error}</p>
        </div>
      )}
    </Modal>
  )
}
