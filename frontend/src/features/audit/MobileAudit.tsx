import { Fragment, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { AuditRow } from '../../api/audit'
import Icon from '../../components/Icon'
import BottomSheet from '../../components/mobile/BottomSheet'
import MPager from '../../components/mobile/MPager'
import { Chip, ChipRow, MCard, MEmpty, MListSkeleton, MSelect, M_BTN_SECONDARY } from '../../components/mobile/atoms'
import { formatDate, formatHijri } from '../../lib/format'

type Key = 'action' | 'user_id' | 'from' | 'to' | 'page'
type Tone = 'ok' | 'gold' | 'info' | 'err'

/** Area → icon; the badge colour follows the kind of change (money gold, people and settings lapis, removals clay). */
const ICON: Record<string, string> = {
  attendance: 'attendance', certificate: 'certificate', enrollment: 'enroll', registration: 'enroll', exam: 'exams', lesson: 'lessons',
  package: 'packages', payment: 'payments', wallet: 'wallet', refund: 'wallet', invoice: 'payments', user: 'users', role: 'users', settings: 'settings',
}
const BADGE: Record<Tone, string> = { ok: 'bg-brand-50 text-brand-700', gold: 'bg-gold-500/12 text-gold-700', info: 'bg-info/10 text-info', err: 'bg-danger/10 text-danger' }
function toneOf(action: string): Tone {
  if (/(delet|cancel|deactivat|reject|remov|refund)/.test(action)) return 'err'
  const area = action.split('.')[0]
  if (['payment', 'wallet', 'invoice'].includes(area)) return 'gold'
  if (['user', 'role', 'settings'].includes(area)) return 'info'
  return 'ok'
}
/** Records with a page of their own. */
function subjectLink(r: AuditRow): string | null {
  if (r.subject === 'Student') return `/students/${r.subject_id}`
  if (r.subject === 'Lesson') return `/lessons/${r.subject_id}`
  if (r.subject === 'RegistrationRequest') return '/packages?tab=requests'
  if (r.subject === 'User') return '/users'
  return null
}
const dayKey = (iso: string) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain' }).format(new Date(iso))

/** Audit log below lg (mobile-redesign-spec.md §6.26). Filters, query and paging stay in AuditLogPage (same URL params). */
export default function MobileAudit({ rows, meta, loading, error, onRetry, f, setFilter, onClear, filtered, groups, users }: {
  rows: AuditRow[] | undefined; meta: { current_page: number; last_page: number; total: number } | undefined; loading: boolean; error: boolean; onRetry: () => void
  f: Record<Key, string>; setFilter: (k: Key, v: string) => void; onClear: () => void; filtered: boolean
  groups: { value: string; label: string }[]; users: { id: number; name: string }[]
}) {
  const { t, i18n } = useTranslation('audit')
  const locale = i18n.language
  const [sheet, setSheet] = useState(false)
  const [open, setOpen] = useState<number | null>(null)
  const sheetCount = [f.user_id, f.from, f.to].filter(Boolean).length
  const days: { key: string; rows: AuditRow[] }[] = []
  for (const r of rows ?? []) {
    const k = dayKey(r.created_at)
    const last = days[days.length - 1]
    if (last?.key === k) last.rows.push(r)
    else days.push({ key: k, rows: [r] })
  }

  return (
    <div className="space-y-3 lg:hidden">
      <div className="flex items-center gap-2">
        <div className="min-w-0 flex-1">
          <ChipRow label={t('group')}>
            <Chip active={!f.action} onClick={() => setFilter('action', '')}>{t('mobile.all')}</Chip>
            {groups.map((g) => <Chip key={g.value} active={f.action === g.value} onClick={() => setFilter('action', f.action === g.value ? '' : g.value)}>{g.label}</Chip>)}
          </ChipRow>
        </div>
        <button type="button" onClick={() => setSheet(true)} aria-label={t('mobile.filters')} title={t('mobile.filters')}
          className="relative inline-grid size-11 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700">
          <Icon name="filter" className="size-5" />
          {sheetCount > 0 && <span aria-hidden className="absolute end-2 top-2 size-2 rounded-full bg-gold-500" />}
        </button>
      </div>

      {loading ? <MListSkeleton rows={6} /> : error || !rows ? (
        <MCard><MEmpty icon="alert" text={t('mobile.error')} action={<button type="button" onClick={onRetry} className={M_BTN_SECONDARY}>{t('common:retry')}</button>} /></MCard>
      ) : rows.length === 0 ? (
        <MCard><MEmpty icon="eye" text={t('empty_body')} action={filtered ? <button type="button" onClick={onClear} className={M_BTN_SECONDARY}>{t('clear')}</button> : undefined} /></MCard>
      ) : days.map((d) => (
        <section key={d.key} className="space-y-2" aria-labelledby={`audit-day-${d.key}`}>
          <h2 id={`audit-day-${d.key}`} className="px-1 text-[13px] font-semibold text-ink/65">
            {formatDate(d.key, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · <span className="font-normal">{formatHijri(d.key, locale)}</span>
          </h2>
          <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
            {d.rows.map((r) => {
              const area = r.action.split('.')[0]
              const to = subjectLink(r)
              const expanded = open === r.id
              return (
                <li key={r.id} className="px-4 py-3">
                  <div className="flex items-start gap-3">
                    <span aria-hidden className={`mt-0.5 inline-grid size-[30px] shrink-0 place-items-center rounded-lg ${BADGE[toneOf(r.action)]}`}>
                      <Icon name={ICON[area] ?? 'eye'} className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-[15px] text-ink [overflow-wrap:anywhere]">
                        {r.user
                          ? <button type="button" onClick={() => setFilter('user_id', String(r.user!.id))} className="font-semibold text-info" title={t('mobile.by_user')}><bdi>{r.user.name}</bdi></button>
                          : <span className="font-semibold">{t('system')}</span>}
                        {' · '}{r.action_label}
                      </p>
                      <p className="mt-0.5 text-[13px] text-ink/65">
                        {to
                          ? <Link to={to} className="inline-flex min-h-6 items-center text-info"><span dir="ltr" className="tabular-nums">{r.subject} #{r.subject_id}</span></Link>
                          : <span dir="ltr" className="tabular-nums">{r.subject} #{r.subject_id}</span>}
                      </p>
                    </div>
                    <div className="-me-2 -mt-1 flex shrink-0 flex-col items-end">
                      <time dateTime={r.created_at} className="pe-2 pt-1 text-[13px] tabular-nums text-ink/65">{formatDate(r.created_at, locale, { hour: 'numeric', minute: '2-digit' })}</time>
                      <button type="button" onClick={() => setOpen(expanded ? null : r.id)} aria-expanded={expanded} aria-label={expanded ? t('hide') : t('show')} title={expanded ? t('hide') : t('show')}
                        className="inline-grid size-11 place-items-center rounded-ctl text-brand-700">
                        <Icon name="chevron" className={`size-4 ${expanded ? '-rotate-90' : 'rotate-90'}`} />
                      </button>
                    </div>
                  </div>
                  {expanded && <div className="mt-1"><MobileDiff row={r} /></div>}
                </li>
              )
            })}
          </ul>
        </section>
      ))}
      {meta && <MPager page={meta.current_page} lastPage={meta.last_page} total={meta.total} onPage={(p) => setFilter('page', String(p))} />}

      <BottomSheet open={sheet} onClose={() => setSheet(false)} title={t('mobile.filters')}
        footer={<>
          <button type="button" onClick={() => { onClear(); setSheet(false) }} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('clear')}</button>
          <button type="button" onClick={() => setSheet(false)} className={`${M_BTN_SECONDARY} h-12 flex-1`}>{t('mobile.done')}</button>
        </>}>
        <div className="space-y-4">
          <MSelect label={t('user')} value={f.user_id} onChange={(v) => setFilter('user_id', v)} options={[{ value: '', label: t('all_users') }, ...users.map((u) => ({ value: String(u.id), label: u.name }))]} />
          <div className="grid grid-cols-2 gap-3">
            {(['from', 'to'] as const).map((k) => (
              <label key={k} className="block min-w-0">
                <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{t(k)}</span>
                <input type="date" value={f[k]} onChange={(e) => setFilter(k, e.target.value)} className="h-12 w-full min-w-0 rounded-md border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
              </label>
            ))}
          </div>
        </div>
      </BottomSheet>
    </div>
  )
}

function show(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  return typeof v === 'object' ? JSON.stringify(v) : String(v)
}

/** Before / after as stacked pairs (no table on phones). */
function MobileDiff({ row }: { row: AuditRow }) {
  const { t } = useTranslation('audit')
  const keys = Array.from(new Set([...Object.keys(row.old_values), ...Object.keys(row.new_values)]))
  if (keys.length === 0) return <p className="ms-[42px] text-[13px] text-ink/65">{t('no_changes')}</p>
  return (
    <dl className="ms-[42px] divide-y divide-ink/10 rounded-ctl bg-page px-3">
      {keys.map((k) => {
        const before = show(row.old_values[k])
        const after = show(row.new_values[k])
        return (
          <Fragment key={k}>
            <div className="py-2">
              <dt dir="ltr" className="text-start font-mono text-xs text-ink/65 rtl:text-end">{k}</dt>
              <dd className="mt-0.5 grid grid-cols-2 gap-2 text-[13px]">
                <span className="min-w-0 break-all text-ink/65"><span className="block text-xs">{t('before')}</span><bdi>{before}</bdi></span>
                <span className={`min-w-0 break-all ${before !== after ? 'font-semibold text-ink' : 'text-ink/65'}`}><span className="block text-xs font-normal text-ink/65">{t('after')}</span><bdi>{after}</bdi></span>
              </dd>
            </div>
          </Fragment>
        )
      })}
    </dl>
  )
}
