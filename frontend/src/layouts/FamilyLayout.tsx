import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../app/AuthContext'
import Icon from '../components/Icon'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { LogoMark, OrnamentStrip } from '../components/ornaments'

/** Student / guardian pages: brand header with a small section nav (home, exams, honor board). */
export default function FamilyLayout({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const { t: tn } = useTranslation('nav')
  const { signOut } = useAuth()
  const link = ({ isActive }: { isActive: boolean }) => `rounded-lg px-3 py-1.5 text-sm font-medium ${isActive ? 'bg-white/15 text-white' : 'text-white/75 hover:text-white'}`

  return (
    <div className="min-h-screen">
      <header className="bg-deep text-gold-300">
        <OrnamentStrip className="text-gold-400" />
        <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-3">
          <LogoMark className="size-9" />
          <span className="font-display text-lg">{t('app_name')}</span>
          <nav className="flex flex-wrap gap-1" aria-label={tn('main')}>
            <NavLink to="/" end className={link}>{tn('dashboard')}</NavLink>
            <NavLink to="/my/exams" className={link}>{tn('exams')}</NavLink>
            <NavLink to="/my/honor" className={link}>{tn('my_honor')}</NavLink>
          </nav>
          <div className="ms-auto flex items-center gap-2">
            <LanguageSwitcher className="text-white/85" />
            <button type="button" onClick={() => void signOut()} className="rounded-lg p-2 text-white/75 hover:bg-white/10" aria-label={t('logout')}><Icon name="logout" /></button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-6">{children}</main>
    </div>
  )
}
