import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { Khatam, LogoMark, OrnamentPattern } from '../components/ornaments'
import { formatHijri, formatNumber } from '../lib/format'

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

      <main className="flex flex-col max-lg:min-w-0">
        {/* Small screens lose the brand panel: a compact deep-emerald header takes its place (mobile spec §6.1). */}
        <header className="relative overflow-hidden bg-deep px-4 pb-4 pt-3 text-white sm:px-8 lg:hidden">
          <Khatam className="pointer-events-none absolute -end-8 -top-8 size-32 text-gold-400 opacity-10" />
          <div className="relative flex items-center gap-2.5">
            <span className="inline-grid size-9 shrink-0 place-items-center rounded-ctl bg-white/10 text-gold-400 ring-1 ring-gold-300/25"><LogoMark className="size-6" /></span>
            <div className="min-w-0 flex-1">
              <p className="truncate font-display text-xl leading-normal text-gold-300">{t('app_name')}</p>
              <p className="truncate text-[13px] tabular-nums text-white/85">{formatHijri(new Date(), i18n.language)}</p>
            </div>
            <LanguageSwitcher className="min-h-11 shrink-0 text-white/85" />
          </div>
          <p lang="ar" dir="rtl" className="relative mt-3 font-quran text-xl leading-[1.9] text-gold-300">
            ﴿ وَلَقَدْ يَسَّرْنَا الْقُرْآنَ لِلذِّكْرِ فَهَلْ مِن مُّدَّكِرٍ ﴾
          </p>
        </header>
        <div className="flex flex-1 flex-col px-4 py-6 sm:px-8">
          <div className="flex items-center justify-between gap-3 max-lg:hidden">
            <div className="flex items-center gap-2 lg:invisible">
              <LogoMark className="size-9" />
              <span className="font-display text-lg text-brand-900">{t('app_name')}</span>
            </div>
            <LanguageSwitcher className="text-ink/65" />
          </div>
          <div className="flex flex-1 items-center justify-center py-10 max-lg:items-start max-lg:py-0">
            <div className="w-full max-w-md">{children}</div>
          </div>
        </div>
      </main>
    </div>
  )
}
