import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { MobilePageHeader } from '../components/mobile/MobileChrome'
import { LogoMark, OrnamentStrip } from '../components/ornaments'

/**
 * Public pages (registration, tracking): brand header with ornament strip, centred content.
 * With `mobile`, phones (below lg) get the mobile page header instead: back + title + language pill (spec §6.2).
 */
export default function PublicLayout({ children, mobile }: { children: ReactNode; mobile?: { title: string; back: string } }) {
  const { t } = useTranslation()
  return (
    <div className="min-h-screen">
      {mobile && (
        <div className="sticky top-0 z-30 lg:hidden">
          <MobilePageHeader title={mobile.title} back={mobile.back} actions={<LanguageSwitcher className="min-h-11 text-ink/65" />} />
        </div>
      )}
      <header className={mobile ? 'bg-deep text-gold-300 max-lg:hidden' : 'bg-deep text-gold-300'}>
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
      <main className={mobile ? 'mx-auto max-w-4xl px-4 py-8 max-lg:pb-28 max-lg:pt-4' : 'mx-auto max-w-4xl px-4 py-8'}>{children}</main>
    </div>
  )
}
