import { useTranslation } from 'react-i18next'
import { useAuth } from '../../app/AuthContext'
import LanguageSwitcher from '../../components/LanguageSwitcher'
import Button from '../../components/Button'

/** Temporary landing screen after sign-in, until the role dashboards (Phase 3.2 to 3.4) are built. */
export default function HomePage() {
  const { t } = useTranslation()
  const { user, signOut } = useAuth()
  if (!user) return null

  return (
    <div className="min-h-screen">
      <header className="flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3 sm:px-8">
        <div className="flex items-center gap-2">
          <img src="/favicon.svg" alt="" className="size-9 rounded-lg" />
          <span className="font-display text-lg text-brand-900">{t('app_name')}</span>
        </div>
        <LanguageSwitcher className="text-stone-600" />
      </header>
      <main className="mx-auto max-w-2xl px-4 py-12 sm:px-8">
        <div className="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
          <p className="text-sm text-stone-500" dir="ltr">
            {user.phone}
          </p>
          <h1 className="mt-1 text-2xl font-semibold text-stone-900">{user.name}</h1>
          <div className="mt-3 flex flex-wrap gap-2">
            {user.roles.map((r) => (
              <span key={r} className="rounded-full bg-brand-50 px-3 py-1 text-sm font-medium text-brand-700">
                {t(`roles.${r}`, r)}
              </span>
            ))}
          </div>
          <Button variant="ghost" className="mt-8 border border-stone-200 sm:w-auto" onClick={() => void signOut()}>
            {t('logout')}
          </Button>
        </div>
      </main>
    </div>
  )
}
