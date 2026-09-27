import { useTranslation } from 'react-i18next'
import { authApi } from '../api/auth'
import { tokenStore } from '../api/client'
import { setLocale, type AppLocale } from '../lib/i18n'

export default function LanguageSwitcher({ className = '' }: { className?: string }) {
  const { t, i18n } = useTranslation()
  const next: AppLocale = i18n.language === 'ar' ? 'en' : 'ar'

  const toggle = () => {
    setLocale(next)
    if (tokenStore.get()) void authApi.updateLocale(next).catch(() => undefined)
  }

  return (
    <button
      type="button"
      onClick={toggle}
      lang={next}
      aria-label={t('language')}
      className={`inline-flex items-center gap-2 rounded-full border border-current/20 px-3 py-1.5 text-sm font-medium transition hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${className}`}
    >
      <svg aria-hidden viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth="1.8">
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z" />
      </svg>
      {t('switch_to')}
    </button>
  )
}
