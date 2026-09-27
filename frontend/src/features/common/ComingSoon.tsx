import { useTranslation } from 'react-i18next'
import Icon from '../../components/Icon'

/** Placeholder for sections whose screens are not built yet (keeps navigation working). */
export default function ComingSoon({ section, icon }: { section: string; icon: string }) {
  const { t } = useTranslation('nav')

  return (
    <div className="mx-auto max-w-3xl">
      <h1 className="font-display text-3xl text-ink">{t(section)}</h1>
      <div className="mt-6 rounded-2xl border border-dashed border-ink/15 bg-white/60 p-10 text-center">
        <span className="mx-auto inline-flex rounded-2xl bg-brand-50 p-3 text-brand-700">
          <Icon name={icon} className="size-7" />
        </span>
        <p className="mt-4 font-semibold text-ink">{t('coming_soon')}</p>
        <p className="mt-1 text-sm text-ink/60">{t('coming_soon_body')}</p>
      </div>
    </div>
  )
}
