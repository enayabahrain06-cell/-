import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { StudentProfile } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { Khatam } from '../../components/ornaments'
import BottomSheet from '../../components/mobile/BottomSheet'
import { StickyActionBar } from '../../components/mobile/ActionBars'
import { HeaderAction, MobilePage } from '../../components/mobile/MobileChrome'
import { MAvatar, MList, Pill, M_BTN_PRIMARY, M_BTN_SECONDARY } from '../../components/mobile/atoms'
import { formatMoney, formatNumber, formatPercent } from '../../lib/format'

/**
 * Student profile header below lg (mobile-redesign-spec.md §6.9): identity card, mini-KPIs, underline tabs and the
 * sticky actions. The tab panels below it are the same components as on desktop.
 */
export default function MobileProfileHeader({ header: h, tabs, tab, onTab, readOnly, report, onReport }: {
  header: StudentProfile['header']; tabs: readonly string[]; tab: string; onTab: (k: string) => void; readOnly: boolean
  report: 'idle' | 'loading' | 'error'; onReport: () => void
}) {
  const { t, i18n } = useTranslation('students')
  const locale = i18n.language
  const { can } = useAuth()
  const n = (v: number) => formatNumber(v, locale)
  const [menu, setMenu] = useState(false)
  const current = h.position.current

  return (
    <div className="space-y-4 lg:hidden">
      <MobilePage title={h.full_name} back="/students"
        breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/students' }, { label: h.full_name }]}
        actions={<>
          {can('messages.send') && <HeaderAction icon="messages" label={t('mobile.message')} to="/messages?tab=send" />}
          <HeaderAction icon="more" label={t('mobile.more_actions')} onClick={() => setMenu(true)} />
        </>} />

      <section className="relative overflow-hidden rounded-card border border-ink/10 bg-white p-4 shadow-card">
        <Khatam className="pointer-events-none absolute -end-5 -top-5 size-24 text-gold-500 opacity-10" />
        <div className="relative flex items-center gap-3">
          <MAvatar name={h.full_name} src={h.photo_url} size={52} />
          <div className="min-w-0 flex-1">
            <p title={h.full_name} className="truncate text-lg font-semibold text-ink"><bdi>{h.full_name}</bdi></p>
            <p className="line-clamp-2 text-[13px] text-ink/65">
              {h.lesson ? <Link to={`/lessons/${h.lesson.id}`} className="text-info"><bdi>{h.lesson.name}</bdi></Link> : <span className="font-semibold text-danger">{t('no_circle')}</span>}
              {h.age !== null && <> · {t('profile.age', { age: n(h.age) })}</>}
              {h.package && <> · <bdi>{h.package.name}</bdi></>}
            </p>
          </div>
        </div>
        <div className="relative mt-3 flex flex-wrap gap-1.5">
          <Pill tone="neutral"><span dir="ltr">{h.student_no}</span></Pill>
          {h.is_due && <Pill tone="err">{t('due_badge')}</Pill>}
          {h.open_issues.total > 0 && <Pill tone="warn">{t('profile.open_issues')} {n(h.open_issues.total)}</Pill>}
          {readOnly && <Pill>{t('profile.read_only')}</Pill>}
        </div>
        <dl className="relative mt-3 grid grid-cols-3 gap-2 border-t border-ink/10 pt-3 text-center">
          <Mini label={t('profile.attendance')} value={formatPercent(h.attendance_percent, locale)} />
          <Mini label={t('mobile.profile_juz')} value={current ? n(current.juz) : '—'} />
          <Mini label={t('profile.balance')} value={formatMoney(h.balance_fils, locale)} danger={h.is_due} />
        </dl>
      </section>

      <div role="tablist" aria-label={t('title')} className="no-scrollbar -mx-4 flex gap-1 overflow-x-auto border-b border-ink/10 px-4">
        {tabs.map((k) => (
          <button key={k} role="tab" type="button" aria-selected={tab === k} onClick={() => onTab(k)}
            className={`relative inline-flex min-h-11 shrink-0 items-center gap-1.5 px-3 text-[15px] ${tab === k ? 'font-semibold text-brand-700 shadow-[inset_0_-2px_0_var(--color-gold-500)]' : 'text-ink/65'}`}>
            {t(`tabs.${k}`)}
            {k === 'issues' && h.open_issues.total > 0 && <Pill tone="err" className="h-5 px-1.5">{n(h.open_issues.total)}</Pill>}
          </button>
        ))}
      </div>

      <BottomSheet open={menu} onClose={() => setMenu(false)} title={t('mobile.more_actions')}>
        <MList>
          <li>
            <button type="button" disabled={report === 'loading'} onClick={() => { onReport(); setMenu(false) }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink">
              <Icon name="printer" className="size-5 text-brand-700" />{t('profile.print_report')}
            </button>
          </li>
          <li>
            <button type="button" onClick={() => { onTab('certificates'); setMenu(false) }} className="flex min-h-[52px] w-full items-center gap-3 px-4 text-[15px] text-ink">
              <Icon name="certificate" className="size-5 text-brand-700" />{t('profile.certificates')}
            </button>
          </li>
        </MList>
        {report === 'error' && <p role="alert" className="mt-3 text-[13px] text-danger">{t('profile.report_error')}</p>}
      </BottomSheet>
    </div>
  )
}

function Mini({ label, value, danger }: { label: string; value: string; danger?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-xs text-ink/65">{label}</dt>
      <dd title={value} className={`mt-0.5 truncate text-sm font-semibold tabular-nums min-[360px]:text-base ${danger ? 'text-danger' : 'text-ink'}`}>{value}</dd>
    </div>
  )
}

/** The profile's sticky actions (rendered after the tab panel so its spacer ends the page). */
export function MobileProfileActions({ onTab }: { onTab: (k: string) => void }) {
  const { t } = useTranslation('students')
  const { can } = useAuth()
  const canRecite = can('evaluations.record')
  const canIssue = can('certificates.issue')
  if (!canRecite && !canIssue) return null
  return (
    <StickyActionBar>
      {canRecite && <Link to="/evaluation" className={`${M_BTN_PRIMARY} min-w-0 flex-1`}><Icon name="evaluation" className="size-5 shrink-0" /><span className="truncate">{t('mobile.new_recitation')}</span></Link>}
      {canIssue && (
        <button type="button" onClick={() => onTab('certificates')} className={`${M_BTN_SECONDARY} h-12 min-w-0 flex-1`}>
          <Icon name="certificate" className="size-5 shrink-0" /><span className="truncate">{t('mobile.issue_certificate')}</span>
        </button>
      )}
    </StickyActionBar>
  )
}

/** Claims the page header while the profile loads or fails, so the shell's default bar never flashes first (CLS). */
export function MobileProfilePending() {
  const { t } = useTranslation('students')
  return <MobilePage title={t('title')} back="/students" breadcrumb={[{ label: t('mobile:home'), to: '/' }, { label: t('title'), to: '/students' }]} />
}
