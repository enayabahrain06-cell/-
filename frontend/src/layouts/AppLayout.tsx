import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../app/AuthContext'
import { NAV_SECTIONS } from '../app/nav'
import Icon from '../components/Icon'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { LogoMark, OrnamentStrip } from '../components/ornaments'

/** Staff shell: sidebar on desktop, drawer on mobile, header with language switcher and account. */
export default function AppLayout() {
  const { t } = useTranslation('nav')
  const { user, can, signOut } = useAuth()
  const [open, setOpen] = useState(false)
  const location = useLocation()

  useEffect(() => setOpen(false), [location.pathname])
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  const sections = NAV_SECTIONS.filter((s) => can(...s.permissions))

  const nav = (
    <nav aria-label={t('main')} className="flex flex-col gap-0.5 p-3">
      {sections.map((s) => (
        <NavLink
          key={s.key}
          to={s.path}
          end={s.path === '/'}
          className={({ isActive }) =>
            `flex items-center gap-3 rounded-xl px-3 py-2.5 text-[0.94rem] font-medium transition ${
              isActive ? 'bg-white/12 text-white shadow-[inset_3px_0_0_var(--color-gold-500)] rtl:shadow-[inset_-3px_0_0_var(--color-gold-500)]' : 'text-white/75 hover:bg-white/6 hover:text-white'
            }`
          }
        >
          <Icon name={s.icon} className="size-5 shrink-0 opacity-90" />
          <span className="truncate">{t(s.key)}</span>
        </NavLink>
      ))}
    </nav>
  )

  const brand = (
    <div className="flex items-center gap-3 px-5 pb-2 pt-5">
      <LogoMark className="size-10" />
      <div className="min-w-0">
        <p className="truncate font-display text-lg leading-tight text-gold-300">{t('common:app_name')}</p>
        <p className="truncate text-xs text-white/55">{t('common:authority')}</p>
      </div>
    </div>
  )

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-screen flex-col overflow-y-auto bg-deep lg:flex">
        <OrnamentStrip className="text-gold-400" />
        {brand}
        {nav}
      </aside>

      {/* Mobile drawer */}
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label={t('main')}>
          <button type="button" className="absolute inset-0 bg-ink/50" aria-label={t('close_menu')} onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col overflow-y-auto bg-deep shadow-2xl">
            <div className="flex items-start justify-between">
              {brand}
              <button type="button" onClick={() => setOpen(false)} className="m-3 rounded-lg p-2 text-white/80 hover:bg-white/10" aria-label={t('close_menu')}>
                <Icon name="close" />
              </button>
            </div>
            {nav}
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-ink/8 bg-page/90 px-4 py-3 backdrop-blur sm:px-6">
          <button type="button" onClick={() => setOpen(true)} className="rounded-lg p-2 text-ink hover:bg-ink/5 lg:hidden" aria-label={t('open_menu')}>
            <Icon name="menu" />
          </button>
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
        <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {/* One content width for every staff page (ui-design-system: page container). */}
          <div className="mx-auto w-full max-w-7xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
