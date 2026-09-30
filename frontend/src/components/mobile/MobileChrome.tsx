import { Fragment, useEffect, useMemo, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Link, NavLink, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { authApi } from '../../api/auth'
import { tokenStore } from '../../api/client'
import { dashboardApi } from '../../api/dashboard'
import { useAuth } from '../../app/AuthContext'
import { NAV_SECTIONS, type NavSection } from '../../app/nav'
import { ChromeContext, TAB_ICON, useMobileChrome, useMobileTabs, type ChromeState, type Crumb } from './chrome'
import { setLocale } from '../../lib/i18n'
import { formatNumber } from '../../lib/format'
import { useInstall } from '../../lib/pwa'
import Icon from '../Icon'
import { LogoMark } from '../ornaments'
import BottomSheet from './BottomSheet'
import { MAvatar, Pill } from './atoms'

/**
 * The mobile shell (mobile-redesign-spec.md §4), rendered only below lg. The desktop header and sidebar are untouched.
 *
 * Root tabs (the bottom-nav destinations) get the MobileAppBar and the bottom nav. Every other page gets a
 * MobilePageHeader (back + title + up to two actions) and a one-line breadcrumb. A page can replace the default
 * header with its own by rendering <MobilePage …>, which portals into the header slot.
 */

/** Section of a path ("/students/7" → students). */
function sectionOf(pathname: string): NavSection | undefined {
  const seg = pathname.split('/')[1] ?? ''
  return NAV_SECTIONS.find((s) => s.path === `/${seg}`)
}

export function MobileChromeProvider({ children }: { children: (state: { bottomNav: boolean; header: ReactNode; more: ReactNode; nav: ReactNode; setSlot: (el: HTMLElement | null) => void }) => ReactNode }) {
  const location = useLocation()
  const tabs = useMobileTabs()
  const [slot, setSlot] = useState<HTMLElement | null>(null)
  const [claims, setClaims] = useState(0)
  const [moreOpen, setMoreOpen] = useState(false)

  const isRoot = tabs.some((t) => t.path === location.pathname)
  const state = useMemo<ChromeState>(() => ({
    bottomNav: isRoot,
    slot,
    claim: () => {
      setClaims((n) => n + 1)
      return () => setClaims((n) => n - 1)
    },
    openMore: () => setMoreOpen(true),
  }), [isRoot, slot])

  const header = claims > 0 ? null : isRoot ? <MobileAppBar onAccount={() => setMoreOpen(true)} /> : <DefaultPageHeader pathname={location.pathname} />

  return (
    <ChromeContext.Provider value={state}>
      {children({
        bottomNav: isRoot,
        header,
        setSlot,
        nav: isRoot ? <MobileBottomNav tabs={tabs} moreOpen={moreOpen} onMore={() => setMoreOpen(true)} /> : null,
        more: <MoreSheet open={moreOpen} onClose={() => setMoreOpen(false)} />,
      })}
    </ChromeContext.Provider>
  )
}

/** A page's own mobile header, portalled into the shell's header slot (replaces the default one). */
export function MobilePage({ title, back, actions, breadcrumb }: { title: string; back?: string; actions?: ReactNode; breadcrumb?: Crumb[] }) {
  const { slot, claim, bottomNav } = useMobileChrome()
  useEffect(() => claim(), [claim])
  if (!slot) return null
  return createPortal(bottomNav ? <MobileAppBarSlotTitle /> : <MobilePageHeader title={title} back={back ?? '/'} actions={actions} breadcrumb={breadcrumb} />, slot)
}

/** On a root tab a page cannot replace the app bar; it keeps the standard one. */
function MobileAppBarSlotTitle() {
  const { openMore } = useMobileChrome()
  return <MobileAppBar onAccount={openMore} />
}

/** §4.1: logo tile + app name, bell (gold dot when alerts are open) and the account avatar. */
export function MobileAppBar({ onAccount }: { onAccount: () => void }) {
  const { t } = useTranslation('mobile')
  const { user, can } = useAuth()
  const alerts = useQuery({
    queryKey: ['alerts-count'],
    queryFn: () => dashboardApi.alerts({ per_page: 1 }),
    enabled: can('dashboard.view'),
    staleTime: 60_000,
  })
  const unread = (alerts.data?.meta.all_total ?? alerts.data?.meta.total ?? 0) > 0

  return (
    <header className="flex h-14 items-center justify-between gap-3 border-b border-ink/10 bg-page px-4">
      <Link to="/" className="flex min-w-0 items-center gap-2.5 rounded-ctl">
        <span className="inline-grid size-9 shrink-0 place-items-center rounded-[10px] bg-deep text-gold-400"><LogoMark className="size-6" /></span>
        <span className="truncate font-display text-xl leading-normal text-brand-900">{t('common:app_name')}</span>
      </Link>
      <div className="flex shrink-0 items-center gap-1">
        {can('dashboard.view') && (
          <Link to="/#alerts" aria-label={unread ? t('notifications_unread') : t('notifications')} className="relative inline-grid size-11 place-items-center rounded-ctl text-ink">
            <Icon name="bell" className="size-[22px]" />
            {unread && <span aria-hidden className="absolute end-2.5 top-2.5 size-2 rounded-full bg-gold-500 ring-2 ring-page" />}
          </Link>
        )}
        <button type="button" onClick={onAccount} aria-label={t('account')} className="inline-grid size-11 place-items-center rounded-full">
          <MAvatar name={user?.name ?? '?'} size={34} />
        </button>
      </div>
    </header>
  )
}

/** §4.2: back (44px), title, up to two 44px actions; breadcrumb line under it. */
export function MobilePageHeader({ title, back, actions, breadcrumb }: { title: string; back: string; actions?: ReactNode; breadcrumb?: Crumb[] }) {
  const { t } = useTranslation('mobile')
  return (
    <div className="border-b border-ink/10 bg-page">
      <header className="flex h-14 items-center gap-1 pe-2 ps-1">
        <Link to={back} aria-label={t('back')} className="inline-grid size-11 shrink-0 place-items-center rounded-ctl text-brand-900">
          <Icon name="chevron" className="size-5 rtl:rotate-0 ltr:rotate-180" />
        </Link>
        <h1 className="min-w-0 flex-1 truncate font-display text-[22px] leading-normal text-brand-900"><bdi>{title}</bdi></h1>
        {actions && <div className="flex shrink-0 items-center">{actions}</div>}
      </header>
      {breadcrumb && breadcrumb.length > 0 && (
        <nav aria-label={t('breadcrumb')} className="truncate px-4 pb-2 text-xs text-ink/65">
          {breadcrumb.map((c, i) => (
            <Fragment key={i}>
              {i > 0 && <span aria-hidden className="mx-1">›</span>}
              {c.to ? <Link to={c.to} className="text-info">{c.label}</Link> : <span aria-current="page">{c.label}</span>}
            </Fragment>
          ))}
        </nav>
      )}
    </div>
  )
}

/** 44px icon action for the page header. */
export function HeaderAction({ icon, label, to, onClick }: { icon: string; label: string; to?: string; onClick?: () => void }) {
  const cls = 'inline-grid size-11 place-items-center rounded-ctl text-brand-900'
  return to
    ? <Link to={to} aria-label={label} title={label} className={cls}><Icon name={icon} className="size-[22px]" /></Link>
    : <button type="button" onClick={onClick} aria-label={label} title={label} className={cls}><Icon name={icon} className="size-[22px]" /></button>
}

/** Header for pages that have not declared their own: the section name, back to its list (or home). */
function DefaultPageHeader({ pathname }: { pathname: string }) {
  const { t } = useTranslation('nav')
  const section = sectionOf(pathname)
  const detail = !!section && pathname !== section.path
  const home = { label: t('mobile:home'), to: '/' }
  if (!section) return <MobilePageHeader title={t('common:app_name')} back="/" />
  return (
    <MobilePageHeader
      title={t(section.key)}
      back={detail ? section.path : '/'}
      breadcrumb={detail ? [home, { label: t(section.key), to: section.path }] : [home, { label: t(section.key) }]}
    />
  )
}

/** §4.3: fixed bottom nav, the role's tabs plus المزيد. */
function MobileBottomNav({ tabs, moreOpen, onMore }: { tabs: NavSection[]; moreOpen: boolean; onMore: () => void }) {
  const { t } = useTranslation('mobile')
  const item = 'relative flex min-h-11 flex-col items-center justify-center gap-1 pt-1 text-[11px] leading-[14px]'
  return (
    <nav aria-label={t('bottom_nav')} className="fixed inset-x-0 bottom-0 z-30 border-t border-ink/10 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
      <div className="grid h-[76px] gap-1 px-2 pb-[14px] pt-1.5" style={{ gridTemplateColumns: `repeat(${tabs.length + 1}, minmax(0, 1fr))` }}>
        {tabs.map((s) => (
          <NavLink key={s.key} to={s.path} end={s.path === '/'}
            className={({ isActive }) => `${item} ${isActive ? 'font-semibold text-brand-700 shadow-[inset_0_2px_0_var(--color-gold-500)]' : 'text-ink/65'}`}>
            <Icon name={TAB_ICON[s.key] ?? s.icon} className="size-[22px]" />
            <span className="max-w-full truncate">{t(`tabs.${s.key}`, { defaultValue: t(`nav:${s.key}`) })}</span>
          </NavLink>
        ))}
        <button type="button" onClick={onMore} aria-haspopup="dialog" aria-expanded={moreOpen}
          className={`${item} ${moreOpen ? 'font-semibold text-brand-700 shadow-[inset_0_2px_0_var(--color-gold-500)]' : 'text-ink/65'}`}>
          <Icon name="more" className="size-[22px]" />
          <span>{t('more')}</span>
        </button>
      </div>
    </nav>
  )
}

/** §4.4: profile, the sidebar's groups (only what the user may open), language, logout. */
function MoreSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t, i18n } = useTranslation('mobile')
  const { user, can, signOut } = useAuth()
  const locale = i18n.language
  const alerts = useQuery({ queryKey: ['alerts-count'], queryFn: () => dashboardApi.alerts({ per_page: 1 }), enabled: open && can('dashboard.view'), staleTime: 60_000 })
  // Count pills (spec §4.4): open dashboard alerts of each type, on the section that handles them.
  const byType = alerts.data?.meta.by_type ?? {}
  const counts: Record<string, number> = {
    packages: byType.registration_request ?? 0,
    lessons: (byType.location_conflict ?? 0) + (byType.lesson_no_teacher ?? 0),
    attendance: byType.repeated_absence ?? 0,
    lottery: byType.lottery_pending ?? 0,
    payments: byType.invoice_overdue ?? 0,
  }

  const sections = NAV_SECTIONS.filter((s) => s.path !== '/' && can(...s.permissions))
  const groups = [...new Set(sections.map((s) => s.group))]
  const switchTo = (next: 'ar' | 'en') => {
    if (next === locale) return
    setLocale(next)
    if (tokenStore.get()) void authApi.updateLocale(next).catch(() => undefined)
  }
  const roles = user?.roles.map((r) => t(`common:roles.${r}`, r)).join(' · ')
  const install = useInstall()

  return (
    <BottomSheet title={t('more')} open={open} onClose={onClose}>
      <div className="space-y-6">
        <div className="flex items-center gap-3 rounded-card border border-ink/10 bg-white p-4 shadow-card">
          <MAvatar name={user?.name ?? '?'} size={44} />
          <div className="min-w-0">
            <p className="truncate text-[15px] font-semibold text-ink"><bdi>{user?.name}</bdi></p>
            <p className="truncate text-[13px] text-ink/65">{roles}{user?.track && <> · {t(`tracks.${user.track}`)}</>}</p>
          </div>
        </div>

        {groups.map((g) => (
          <section key={g ?? 'top'} className="space-y-2">
            {g && <h3 className="px-1 text-xs font-semibold text-ink/65">{t(`nav:groups.${g}`)}</h3>}
            <ul className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card">
              {sections.filter((s) => s.group === g).map((s) => (
                <li key={s.key}>
                  <NavLink to={s.path} onClick={onClose} className={({ isActive }) => `flex min-h-[52px] items-center gap-3 px-4 py-2 ${isActive ? 'bg-brand-50/60' : ''}`}>
                    <span className="inline-grid size-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700"><Icon name={s.icon} className="size-[18px]" /></span>
                    <span className="min-w-0 flex-1 truncate text-[15px] text-ink">{t(`nav:${s.key}`)}</span>
                    {counts[s.key] > 0 && <Pill tone="warn">{formatNumber(counts[s.key], locale)}</Pill>}
                    <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />
                  </NavLink>
                </li>
              ))}
            </ul>
          </section>
        ))}

        <div className="flex min-h-[52px] items-center gap-3 rounded-card border border-ink/10 bg-white px-4 py-2 shadow-card">
          <span className="inline-grid size-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700"><Icon name="globe" className="size-[18px]" /></span>
          <span className="min-w-0 flex-1 text-[15px] text-ink">{t('language')}</span>
          <div role="group" aria-label={t('language')} className="flex gap-[3px] rounded-ctl bg-ink/5 p-[3px]">
            {(['ar', 'en'] as const).map((l) => (
              <button key={l} type="button" lang={l} aria-pressed={locale === l} onClick={() => switchTo(l)}
                className={`h-9 min-w-16 rounded-lg px-3 text-[13px] font-semibold ${locale === l ? 'bg-white text-brand-700 shadow-card' : 'text-ink/65'}`}>
                {l === 'ar' ? 'العربية' : 'English'}
              </button>
            ))}
          </div>
        </div>

        {install.mode && (
          <div className="flex min-h-[52px] items-center gap-3 rounded-card border border-ink/10 bg-white px-4 py-3 shadow-card">
            <img src="/icon-192.png" alt="" className="size-8 shrink-0 rounded-lg" />
            <div className="min-w-0 flex-1">
              <p className="text-[15px] text-ink">{t('install')}</p>
              <p className="text-[13px] text-ink/65">{install.mode === 'ios' ? t('install_ios') : t('install_sub')}</p>
            </div>
            {install.mode === 'prompt' && (
              <button type="button" onClick={() => void install.install()} className="inline-flex min-h-11 shrink-0 items-center rounded-ctl border border-ink/10 bg-white px-3 text-[13px] font-semibold text-brand-700">{t('install_action')}</button>
            )}
          </div>
        )}

        <button type="button" onClick={() => void signOut()} className="flex min-h-[52px] w-full items-center gap-3 rounded-card border border-ink/10 bg-white px-4 text-[15px] font-semibold text-danger shadow-card">
          <Icon name="logout" className="size-5" />
          {t('common:logout')}
        </button>
      </div>
    </BottomSheet>
  )
}
