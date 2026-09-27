import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { LogoMark, OrnamentPattern, OrnamentStrip } from '../components/ornaments'
import { formatNumber } from '../lib/format'

/** Split screen: brand panel (hidden on small screens) and the form column. */
export default function AuthLayout({ children }: { children: ReactNode }) {
  const { t, i18n } = useTranslation()

  return (
    <div className="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <aside className="relative hidden overflow-hidden bg-deep text-gold-300 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <OrnamentPattern />
        <div className="relative flex items-center gap-3">
          <LogoMark className="size-12" />
          <div>
            <p className="font-display text-2xl leading-tight">{t('app_name')}</p>
            <p className="text-sm text-white/85">{t('authority')}</p>
          </div>
        </div>
        <blockquote className="relative max-w-md">
          <p lang="ar" dir="rtl" className="font-quran text-4xl leading-[1.9] text-gold-300">
            ﴿ وَلَقَدْ يَسَّرْنَا الْقُرْآنَ لِلذِّكْرِ فَهَلْ مِن مُّدَّكِرٍ ﴾
          </p>
          <footer className="mt-3 text-sm text-white/85" lang="ar" dir="rtl">
            سورة القمر — ١٧
          </footer>
        </blockquote>
        <p className="relative text-xs text-white/85">© {formatNumber(new Date().getFullYear(), i18n.language, { useGrouping: false })} {t('authority')}</p>
      </aside>

      <main className="flex flex-col">
        {/* Small screens lose the brand panel, so they keep a thin ornamental band on top. */}
        <div className="bg-deep lg:hidden">
          <OrnamentStrip className="text-gold-400" />
        </div>
        <div className="flex flex-1 flex-col px-4 py-6 sm:px-8">
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2 lg:invisible">
              <LogoMark className="size-9" />
              <span className="font-display text-lg text-brand-900">{t('app_name')}</span>
            </div>
            <LanguageSwitcher className="text-stone-600" />
          </div>
          <div className="flex flex-1 items-center justify-center py-10">
            <div className="w-full max-w-md">{children}</div>
          </div>
        </div>
      </main>
    </div>
  )
}
