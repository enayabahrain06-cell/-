import { Fragment } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../app/AuthContext'
import { NAV_SECTIONS } from '../app/nav'
import Icon from '../components/Icon'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { LogoMark, OrnamentStrip } from '../components/ornaments'
import { MobileChromeProvider } from '../components/mobile/MobileChrome'
import TeacherTodayDialog from '../features/attendance/TeacherTodayDialog'

/**
 * Staff shell: sidebar and header on desktop (lg+). Below lg the mobile shell (components/mobile) takes over:
 * app bar or page header, bottom nav on the root tabs, and the المزيد sheet with the full section map.
 */
export default function AppLayout() {
  const { t } = useTranslation('nav')
  const { user, can, signOut } = useAuth()
  const sections = NAV_SECTIONS.filter((s) => can(...s.permissions))

  const nav = (
    <nav aria-label={t('main')} className="flex flex-col gap-0.5 p-3">
      {sections.map((s, i) => (
        <Fragment key={s.key}>
          {s.group && s.group !== sections[i - 1]?.group && (
            <p className="mt-4 px-3 pb-1 text-xs font-semibold text-gold-300/70">{t(`groups.${s.group}`)}</p>
          )}
          <NavLink
            to={s.path}
            end={s.path === '/'}
            className={({ isActive }) =>
              `flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-[0.9375rem] font-medium transition ${
                isActive ? 'bg-white/12 text-white shadow-[inset_3px_0_0_var(--color-gold-500)] rtl:shadow-[inset_-3px_0_0_var(--color-gold-500)]' : 'text-white/75 hover:bg-white/6 hover:text-white'
              }`
            }
          >
            <Icon name={s.icon} className="size-5 shrink-0 opacity-90" />
            <span className="truncate">{t(s.key)}</span>
          </NavLink>
        </Fragment>
      ))}
    </nav>
  )

  const brand = (
    <div className="flex items-center gap-3 px-5 pb-2 pt-5">
      <LogoMark className="size-10" />
      <div className="min-w-0">
        <p className="truncate font-display text-lg leading-normal text-gold-300">{t('common:app_name')}</p>
        <p className="truncate text-xs text-white/55">{t('common:authority')}</p>
      </div>
    </div>
  )

  return (
    <MobileChromeProvider>
      {(mobile) => (
    <div className="min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-screen flex-col overflow-y-auto bg-deep lg:flex">
        <OrnamentStrip className="text-gold-400" />
        {brand}
        {nav}
      </aside>

      <div className="flex min-w-0 flex-col">
        {/* Mobile header: app bar on root tabs, back + title elsewhere; a page may portal its own into the slot. */}
        <div className="sticky top-0 z-30 lg:hidden">
          {mobile.header}
          <div ref={mobile.setSlot} />
        </div>
        <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-ink/8 bg-page/90 px-4 py-3 backdrop-blur max-lg:hidden sm:px-6 lg:px-8">
          <div className="ms-auto flex items-center gap-2 sm:gap-3">
            <LanguageSwitcher className="text-ink/70" />
            <div className="hidden text-end sm:block">
              <p className="text-sm font-semibold leading-tight text-ink">{user?.name}</p>
              <p className="text-xs text-ink/55">{user?.roles.map((r) => t(`common:roles.${r}`, r)).join(' · ')}</p>
            </div>
            <button type="button" onClick={() => void signOut()} className="rounded-lg p-2 text-ink/70 hover:bg-ink/5 hover:text-danger" aria-label={t('common:logout')} title={t('common:logout')}>
              <Icon name="logout" />
            </button>
          </div>
        </header>
        <main className={`min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8 max-lg:pt-4 ${mobile.bottomNav ? 'max-lg:pb-28' : 'max-lg:pb-6'}`}>
          {/* The one page container for every staff page (ui-design-system: page container). It fills the
              column beside the sidebar; max-w-page only stops lines running across QHD/4K screens. */}
          <div className="mx-auto w-full max-w-page">
            <Outlet />
            <TeacherTodayDialog />
          </div>
        </main>
      </div>
      {mobile.nav}
      {mobile.more}
    </div>
      )}
    </MobileChromeProvider>
  )
}
