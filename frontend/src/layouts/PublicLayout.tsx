import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { LogoMark, OrnamentStrip } from '../components/ornaments'

/** Public pages (registration, tracking): brand header with ornament strip, centred content. */
export default function PublicLayout({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  return (
    <div className="min-h-screen">
      <header className="bg-deep text-gold-300">
        <OrnamentStrip className="text-gold-400" />
        <div className="mx-auto flex max-w-4xl items-center gap-3 px-4 py-4">
          <Link to="/register" className="flex min-w-0 items-center gap-3">
            <LogoMark className="size-10" />
            <span className="min-w-0">
              <span className="block truncate font-display text-lg leading-tight">{t('app_name')}</span>
              <span className="block truncate text-xs text-white/60">{t('authority')}</span>
            </span>
          </Link>
          <LanguageSwitcher className="ms-auto text-white/85" />
        </div>
      </header>
      <main className="mx-auto max-w-4xl px-4 py-8">{children}</main>
    </div>
  )
}
