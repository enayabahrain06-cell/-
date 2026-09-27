import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { dashboardApi, type AlertConflict, type DashboardAlert } from '../../api/dashboard'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import Pagination from '../../components/Pagination'
import { Modal, TABLE_HEAD_STICKY, SURFACE, IconButton } from '../../components/ui'
import { EmptyState } from '../../components/ornaments'
import { formatDate, formatNumber, formatTime, formatWeekday } from '../../lib/format'
import { relativeTime } from './relativeTime'
import { useDashboardFilters } from './useDashboardFilters'

type Severity = DashboardAlert['severity']

/** Priority = severity: urgent (red), important (gold), follow-up (grey). */
const PRIORITY_STYLE: Record<Severity, string> = {
  danger: 'border-s-danger bg-danger/5',
  warning: 'border-s-gold-500 bg-gold-500/6',
  info: 'border-s-ink/25 bg-ink/[0.03]',
}
const PRIORITY_PILL: Record<Severity, string> = {
  danger: 'bg-danger/10 text-danger',
  warning: 'bg-gold-500/12 text-gold-700',
  info: 'bg-ink/8 text-ink/65',
}

interface Action { label: string; to?: string; onClick?: () => void; disabled?: boolean; title?: string; busy?: boolean }

const PAGE = 20

/** "Alerts needing a decision": its own query (/api/alerts), term-aware, with per-type actions. */
export default function AlertsCard() {
  const { t, i18n } = useTranslation('dashboard')
  const locale = i18n.language
  const { can } = useAuth()
  const qc = useQueryClient()
  const { term } = useDashboardFilters()
  // null = the section's first page; a string = "view all" paged list ('' = every type).
  const [type, setType] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [sessionsOf, setSessionsOf] = useState<DashboardAlert | null>(null)
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null)

  const q = useQuery({
    queryKey: ['dashboard', 'alerts', type ?? 'top', page, term, locale],
    queryFn: () => dashboardApi.alerts({ type: type || undefined, page, per_page: type === null ? 30 : PAGE, term }),
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  })
  const done = (text: string, tone: 'ok' | 'error' = 'ok') => setNotice({ tone, text })
  const errText = (e: unknown) => (isAxiosError(e) && e.response?.data?.message) || t('alerts.action_failed')

  const resolve = useMutation({
    mutationFn: (id: number) => dashboardApi.resolveAlert(id),
    onSuccess: (r) => {
      done(t('alerts.handled_by', { name: r.resolved_by, time: new Date(r.resolved_at).toLocaleTimeString(locale === 'ar' ? 'ar-BH' : 'en-BH', { hour: 'numeric', minute: '2-digit' }) }))
      void qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (e) => done(errText(e), 'error'),
  })
  const message = useMutation({
    mutationFn: (id: number) => dashboardApi.messageGuardian(id),
    onSuccess: (r) => done(r.message),
    onError: (e) => done(errText(e), 'error'),
  })

  const actionsFor = (a: DashboardAlert): { primary?: Action; secondary?: Action } => {
    const s = a.subject
    switch (a.type) {
      case 'location_conflict':
        return {
          primary: s && can('lessons.view') ? { label: t('alerts.actions.resolve_conflict'), to: `/lessons/${s.id}` } : undefined,
          secondary: a.conflict?.sessions.length ? { label: t('alerts.actions.view_sessions', { n: formatNumber(a.conflict.count, locale) }), onClick: () => setSessionsOf(a) } : undefined,
        }
      case 'repeated_absence': {
        const canMsg = can('messages.send', 'lessons.manage') && a.id !== null
        const noPhone = a.absence ? !a.absence.has_phone : false
        return {
          primary: canMsg
            ? { label: t('alerts.actions.message_parents'), onClick: () => message.mutate(a.id as number), disabled: noPhone || (message.isPending && message.variables === a.id), busy: message.isPending && message.variables === a.id, title: noPhone ? t('alerts.no_phone') : undefined }
            : undefined,
          secondary: s && can('students.view') ? { label: t('alerts.actions.open_student'), to: `/students/${s.id}` } : undefined,
        }
      }
      case 'lesson_no_teacher':
        return {
          primary: s && can('lessons.manage') ? { label: t('alerts.actions.assign_teacher'), to: `/lessons/${s.id}` } : undefined,
          secondary: s && can('students.view') ? { label: t('alerts.actions.circle_students'), to: `/students?lesson_id=${s.id}` } : undefined,
        }
      case 'registration_request':
        return { primary: can('registrations.view') ? { label: t('alerts.actions.review_requests'), to: `/packages?tab=requests&status=pending${s ? `&package_id=${s.id}` : ''}` } : undefined }
      case 'invoice_overdue':
        return {
          primary: s?.student_id && can('students.view') ? { label: t('alerts.actions.open_wallet'), to: `/students/${s.student_id}?tab=wallet` } : undefined,
          secondary: can('wallets.view') ? { label: t('alerts.actions.invoices'), to: '/payments?tab=invoices' } : undefined,
        }
      case 'lottery_pending':
        return { primary: s && can('lottery.view') ? { label: t('alerts.actions.review_lottery'), to: `/lottery/${s.id}` } : undefined }
      case 'exam_upcoming':
        return { primary: s && can('exams.view') ? { label: t('alerts.actions.open_exam'), to: `/exams/${s.id}` } : undefined }
      default:
        return {}
    }
  }

  /** Built here (not server-side) so dates, times and counts use the viewer's digits. */
  const conflictLine = (c: AlertConflict) => {
    const sunday = new Date(Date.UTC(2026, 0, 4, 12))
    const days = c.weekdays.map((d) => formatWeekday(new Date(sunday.getTime() + d * 86400000), locale, 'short')).join(locale === 'ar' ? '، ' : '/')
    return t('alerts.conflict_line', {
      with: c.with.join(locale === 'ar' ? '، ' : ', '),
      count: formatNumber(c.count, locale),
      from: c.from ? formatDate(c.from, locale, { day: 'numeric', month: 'short' }) : '—',
      to: c.to ? formatDate(c.to, locale, { day: 'numeric', month: 'short' }) : '—',
      days: days || '—',
      start: formatTime(c.start_time, locale),
      end: formatTime(c.end_time, locale),
    })
  }

  const meta = q.data?.meta
  const items = q.data?.data ?? []
  const allTotal = meta?.all_total ?? 0
  const types = Object.entries(meta?.by_type ?? {}).sort((a, b) => b[1] - a[1])
  const pick = (v: string | null) => { setType(v); setPage(1) }
  const chip = (active: boolean) => `rounded-full px-2.5 py-1 text-xs font-medium ${active ? 'bg-brand-600 text-white' : 'bg-ink/6 text-ink/70 hover:bg-ink/10'}`

  return (
    <section className={`${SURFACE} flex min-w-0 flex-col`} aria-labelledby="alerts-title">
      <h2 id="alerts-title" className="flex items-center gap-2 border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        <Icon name="alert" className="size-5 text-gold-700" />
        {t('alerts.title')}
        {allTotal > 0 && <span className="rounded-full bg-danger px-2 py-0.5 text-xs font-semibold text-white">{formatNumber(allTotal, locale)}</span>}
        {allTotal > 0 && (
          <button type="button" onClick={() => pick(type === null ? '' : null)} className="ms-auto text-sm font-medium text-brand-700 hover:underline">
            {type === null ? t('alerts.view_all_short') : t('alerts.show_less')}
          </button>
        )}
      </h2>

      {notice && (
        <div role="status" className={`mx-3 mt-3 flex items-start gap-2 rounded-lg px-3 py-2 text-sm ${notice.tone === 'ok' ? 'bg-brand-50 text-brand-800' : 'bg-danger/10 text-danger'}`}>
          <Icon name={notice.tone === 'ok' ? 'check' : 'alert'} className="mt-0.5 size-4 shrink-0" />
          <span className="flex-1">{notice.text}</span>
          <IconButton icon="close" label={t('common:close')} onClick={() => setNotice(null)} />
        </div>
      )}

      {types.length > 1 && (
        <div role="group" aria-label={t('alerts.filter')} className="flex flex-wrap gap-1.5 border-b border-ink/6 px-3 py-2.5">
          <button type="button" aria-pressed={!type} className={chip(!type)} onClick={() => pick(null)}>
            {t('alerts.all')} {formatNumber(allTotal, locale)}
          </button>
          {types.map(([k, n]) => (
            <button key={k} type="button" aria-pressed={type === k} className={chip(type === k)} onClick={() => pick(k)}>
              {t(`alerts.types.${k}`, { defaultValue: k })} {formatNumber(n, locale)}
            </button>
          ))}
        </div>
      )}

      {q.isLoading ? (
        <AlertsSkeleton />
      ) : q.isError ? (
        <div role="alert" className="p-6 text-center text-sm text-danger">
          <p>{t('alerts.error')}</p>
          <button type="button" onClick={() => void q.refetch()} className="mt-2 rounded-lg border border-ink/10 bg-white px-3 py-1.5 font-medium text-ink shadow-sm">{t('retry')}</button>
        </div>
      ) : items.length === 0 ? (
        <EmptyState size="sm" icon="check" title={t('alerts.empty')} body={t('alerts.empty_hint')} />
      ) : (
        <ul className={`max-h-[30rem] space-y-2 overflow-y-auto p-3 ${q.isFetching ? 'opacity-70' : ''}`}>
          {items.map((a, i) => {
            const { primary, secondary } = actionsFor(a)
            const handling = resolve.isPending && resolve.variables === a.id
            return (
              <li key={a.id ?? `c-${a.type}-${a.subject?.id ?? i}`} className={`rounded-xl border-s-4 px-3 py-2.5 ${PRIORITY_STYLE[a.severity]}`}>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink/55">
                  <span className={`rounded-full px-2 py-0.5 font-semibold ${PRIORITY_PILL[a.severity]}`}>{t(`alerts.priority.${a.severity}`)}</span>
                  <span className="font-medium">{t(`alerts.types.${a.type}`, { defaultValue: a.type_label })}</span>
                  {a.created_at && a.kind === 'alert' && (
                    <>
                      <span aria-hidden>·</span>
                      <time dateTime={a.created_at} title={new Date(a.created_at).toLocaleString(locale === 'ar' ? 'ar-BH' : 'en-BH')}>{relativeTime(a.created_at, locale)}</time>
                    </>
                  )}
                </div>
                <p dir="auto" className="mt-1 truncate text-start text-sm font-semibold text-ink" title={a.title}>{a.title}</p>
                {(() => {
                  const body = a.conflict ? conflictLine(a.conflict) : a.body
                  return body && <p dir="auto" title={body} className="mt-0.5 line-clamp-2 text-start text-sm text-ink/65">{body}</p>
                })()}
                {(primary || secondary || (a.resolvable && a.id !== null)) && (
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    {primary && <ActionButton action={primary} primary />}
                    {secondary && <ActionButton action={secondary} />}
                    {a.resolvable && a.id !== null && (
                      <button
                        type="button"
                        disabled={handling}
                        onClick={() => resolve.mutate(a.id as number)}
                        className="ms-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-ink/60 hover:bg-ink/5 hover:text-ink disabled:opacity-60"
                      >
                        <Icon name="check" className="size-3.5" />
                        {handling ? t('alerts.resolving') : t('alerts.resolve')}
                      </button>
                    )}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}

      {type !== null && meta && (
        <div className="mt-auto border-t border-ink/6 px-3 py-2.5">
          <Pagination page={meta.current_page} lastPage={meta.last_page} total={meta.total} onPage={setPage} />
        </div>
      )}

      {sessionsOf?.conflict && <SessionsModal alert={sessionsOf} conflict={sessionsOf.conflict} locale={locale} onClose={() => setSessionsOf(null)} />}
    </section>
  )
}

function ActionButton({ action: a, primary = false }: { action: Action; primary?: boolean }) {
  const cls = primary
    ? 'inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50'
    : 'inline-flex items-center gap-1 rounded-lg border border-ink/12 bg-white px-2.5 py-1 text-xs font-medium text-ink/75 hover:bg-ink/5 disabled:opacity-50'
  if (a.to) return <Link to={a.to} className={cls}>{a.label}</Link>
  return (
    <button type="button" className={cls} onClick={a.onClick} disabled={a.disabled} title={a.title} aria-busy={a.busy}>
      {a.label}
    </button>
  )
}

function SessionsModal({ alert, conflict, locale, onClose }: { alert: DashboardAlert; conflict: AlertConflict; locale: string; onClose: () => void }) {
  const { t } = useTranslation('dashboard')
  return (
    <Modal title={t('alerts.sessions_title', { n: formatNumber(conflict.count, locale) })} onClose={onClose} wide>
      <p dir="auto" className="text-sm text-ink/70">{alert.title}</p>
      <div className="max-h-[60vh] overflow-y-auto rounded-xl border border-ink/8">
        <table className="w-full text-sm">
          <thead className={TABLE_HEAD_STICKY}>
            <tr className="border-b border-ink/10 text-ink/55">
              <th className="px-3 py-2 text-start font-medium">{t('alerts.col_date')}</th>
              <th className="px-3 py-2 text-start font-medium">{t('alerts.col_time')}</th>
              <th className="px-3 py-2 text-start font-medium">{t('alerts.col_with')}</th>
            </tr>
          </thead>
          <tbody>
            {conflict.sessions.map((s, i) => (
              <tr key={`${s.date}-${i}`} className="border-b border-ink/5 last:border-0">
                <td className="px-3 py-2 text-ink">{s.date ? `${formatWeekday(s.date, locale, 'short')} ${formatDate(s.date, locale, { day: 'numeric', month: 'short' })}` : '—'}</td>
                <td className="px-3 py-2 text-ink/75">{formatTime(s.start_time.slice(0, 5), locale)}–{formatTime(s.end_time.slice(0, 5), locale)}</td>
                <td dir="auto" className="px-3 py-2 text-start text-ink/75">{s.title}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Modal>
  )
}

function AlertsSkeleton() {
  return (
    <div className="space-y-2 p-3" aria-busy="true">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="h-24 animate-pulse rounded-xl bg-ink/5" />
      ))}
    </div>
  )
}
