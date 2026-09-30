import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MESSAGE_STATUSES, type MessageLog, type MessageStats } from '../../api/messages'
import type { Paginated } from '../../api/students'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import MPager from '../../components/mobile/MPager'
import MobileToast from '../../components/mobile/Toast'
import { Chip, ChipRow, MCard, MEmpty, MListSkeleton, MSearch, MSelect, Pill, M_BTN_SECONDARY } from '../../components/mobile/atoms'
import { formatNumber } from '../../lib/format'
import { statusTone } from './mobileStatus'
import { formatDateTime } from './status'

type Key = 'status' | 'type' | 'phone' | 'from' | 'to' | 'page'

/** Message log below lg: status chips with counts, phone search, filter sheet, card rows. State lives in LogTab. */
export default function MobileLog({ filters, set, phone, onPhone, onClear, hasFilters, stats, logs, loading, error, onRetry, typeOptions, onOpen, canResend, onResend, resending, failed, onResendAll, toast, onToastDone }: {
  filters: Record<Key, string | number>; set: (k: Key, v: string) => void; phone: string; onPhone: (v: string) => void; onClear: () => void; hasFilters: boolean
  stats: MessageStats | undefined; logs: Paginated<MessageLog> | undefined; loading: boolean; error: boolean; onRetry: () => void
  typeOptions: { value: string; label: string }[]; onOpen: (m: MessageLog) => void
  canResend: boolean; onResend: (id: number) => void; resending: number | null; failed: number; onResendAll: () => void
  toast: { tone: 'success' | 'error'; text: string } | null; onToastDone: () => void
}) {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const n = (v: number) => formatNumber(v, locale)
  const [sheet, setSheet] = useState(false)
  const sheetCount = [filters.type, filters.from, filters.to].filter(Boolean).length
  const statuses = MESSAGE_STATUSES.filter((s) => (stats?.by_status[s] ?? 0) > 0 || ['sent', 'failed', 'queued'].includes(s))

  return (
    <div className="space-y-3 lg:hidden">
      <MobileToast message={toast?.text ?? null} tone={toast?.tone === 'error' ? 'error' : 'ok'} onDone={onToastDone} />
      <div className="flex gap-2">
        <div className="min-w-0 flex-1"><MSearch label={t('filters.phone')} value={phone} onChange={onPhone} /></div>
        <button type="button" onClick={() => setSheet(true)} aria-label={t('filters.label')} title={t('filters.label')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="filter" className="size-5" />
          {sheetCount > 0 && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>
      <ChipRow label={t('filters.status')}>
        <Chip active={!filters.status} onClick={() => set('status', '')}>{t('mobile.all')}{stats && <> {n(stats.total)}</>}</Chip>
        {statuses.map((s) => (
          <Chip key={s} active={filters.status === s} onClick={() => set('status', filters.status === s ? '' : s)}>
            {t(`status.${s}`)}{stats && <> {n(stats.by_status[s] ?? 0)}</>}
          </Chip>
        ))}
      </ChipRow>

      {canResend && failed > 0 && (
        <button type="button" onClick={onResendAll} className={`${M_BTN_SECONDARY} w-full`}><Icon name="refresh" className="size-5" />{t('log.resend_all')} (<span className="tabular-nums">{n(failed)}</span>)</button>
      )}

      {loading ? <MListSkeleton rows={6} /> : error ? (
        <MCard><MEmpty icon="alert" text={t('error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
      ) : !logs?.data.length ? (
        <MCard><MEmpty icon="messages" text={t('log.empty')} action={hasFilters ? <button type="button" onClick={onClear} className={M_BTN_SECONDARY}>{t('filters.clear')}</button> : undefined} /></MCard>
      ) : (
        <ul aria-label={t('tabs.log')} className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
          {logs.data.map((m) => (
            <li key={m.id} className="flex min-h-16 items-center gap-2 pe-2">
              <button type="button" onClick={() => onOpen(m)} className="flex min-w-0 flex-1 items-center gap-3 py-2.5 ps-4 text-start" aria-label={`${t('log.details')}: ${m.student_name || t('log.no_name')}`}>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{m.student_name || t('log.no_name')}</bdi></span>
                  <span className="mt-0.5 block truncate text-[13px] text-ink/65">
                    <span dir="ltr" className="tabular-nums">{m.recipient_phone}</span> · {t(`types.${m.type}`, { defaultValue: m.type_label ?? m.type })}
                  </span>
                  {m.created_at && <time dateTime={m.created_at} className="mt-0.5 block text-xs tabular-nums text-ink/65">{formatDateTime(m.created_at, locale)}</time>}
                  {m.error && <span className="mt-0.5 block truncate text-xs text-danger"><bdi>{m.error}</bdi></span>}
                </span>
                <Pill tone={statusTone(m.status)}>{t(`status.${m.status}`, { defaultValue: m.status })}</Pill>
              </button>
              {m.status === 'failed' && canResend && (
                <button type="button" onClick={() => onResend(m.id)} disabled={resending === m.id} aria-label={`${t('log.resend')}: ${m.student_name || m.recipient_phone}`} title={t('log.resend')}
                  className="inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700 disabled:opacity-60">
                  <Icon name="refresh" className="size-5" />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {logs && <MPager page={logs.meta.current_page} lastPage={logs.meta.last_page} total={logs.meta.total} onPage={(p) => set('page', String(p))} />}

      <BottomSheet open={sheet} onClose={() => setSheet(false)} title={t('filters.label')}
        footer={<>
          <button type="button" onClick={() => { onClear(); setSheet(false) }} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('filters.clear')}</button>
          <button type="button" onClick={() => setSheet(false)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>
        </>}>
        <div className="space-y-4">
          <MSelect label={t('filters.type')} value={String(filters.type)} onChange={(v) => set('type', v)} options={[{ value: '', label: t('filters.any_type') }, ...typeOptions]} />
          <div className="grid grid-cols-2 gap-3">
            <DateField label={t('filters.from')} value={String(filters.from)} max={String(filters.to) || undefined} onChange={(v) => set('from', v)} />
            <DateField label={t('filters.to')} value={String(filters.to)} min={String(filters.from) || undefined} onChange={(v) => set('to', v)} />
          </div>
        </div>
      </BottomSheet>
    </div>
  )
}

function DateField({ label, value, min, max, onChange }: { label: string; value: string; min?: string; max?: string; onChange: (v: string) => void }) {
  return (
    <label className="block min-w-0">
      <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{label}</span>
      <input type="date" value={value} min={min} max={max} onChange={(e) => onChange(e.target.value)}
        className="h-12 w-full min-w-0 rounded-md border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
    </label>
  )
}
