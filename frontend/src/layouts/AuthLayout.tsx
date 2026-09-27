import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../components/LanguageSwitcher'
import { formatNumber } from '../lib/format'

/** Split screen: brand panel (hidden on small screens) and the form column. */
export default function AuthLayout({ children }: { children: ReactNode }) {
  const { t, i18n } = useTranslation()

  return (
    <div className="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <aside className="relative hidden overflow-hidden bg-brand-700 text-gold-300 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 opacity-[0.09]"
          style={{
            backgroundImage:
              "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cg fill='none' stroke='%23f4e3b5' stroke-width='1.2'%3E%3Cpath d='M40 4l10 26 26 10-26 10-10 26-10-26L4 40l26-10z'/%3E%3Crect x='22' y='22' width='36' height='36' transform='rotate(45 40 40)'/%3E%3C/g%3E%3C/svg%3E\")",
          }}
        />
        <div className="relative flex items-center gap-3">
          <img src="/favicon.svg" alt="" className="size-12 rounded-xl ring-1 ring-gold-300/30" />
          <div>
            <p className="font-display text-2xl leading-tight">{t('app_name')}</p>
            <p className="text-sm text-gold-300/70">{t('authority')}</p>
          </div>
        </div>
        <blockquote className="relative max-w-md">
          <p lang="ar" dir="rtl" className="font-display text-4xl leading-[1.7] text-gold-300">
            ﴿ وَلَقَدْ يَسَّرْنَا الْقُرْآنَ لِلذِّكْرِ فَهَلْ مِن مُّدَّكِرٍ ﴾
          </p>
          <footer className="mt-3 text-sm text-gold-300/60" lang="ar" dir="rtl">
            سورة القمر — ١٧
          </footer>
        </blockquote>
        <p className="relative text-xs text-gold-300/50">© {formatNumber(new Date().getFullYear(), i18n.language, { useGrouping: false })} {t('authority')}</p>
      </aside>

      <main className="flex flex-col px-4 py-6 sm:px-8">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2 lg:invisible">
            <img src="/favicon.svg" alt="" className="size-9 rounded-lg" />
            <span className="font-display text-lg text-brand-900">{t('app_name')}</span>
          </div>
          <LanguageSwitcher className="text-stone-600" />
        </div>
        <div className="flex flex-1 items-center justify-center py-10">
          <div className="w-full max-w-md">{children}</div>
        </div>
      </main>
    </div>
  )
}
