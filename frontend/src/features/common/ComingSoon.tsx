import { useTranslation } from 'react-i18next'
import { EmptyState, PageTitle } from '../../components/ornaments'

/** Placeholder for sections whose screens are not built yet (keeps navigation working). */
export default function ComingSoon({ section, icon }: { section: string; icon: string }) {
  const { t } = useTranslation('nav')

  return (
    <div className="mx-auto max-w-3xl">
      <PageTitle>{t(section)}</PageTitle>
      <div className="mt-6 rounded-2xl border border-dashed border-ink/15 bg-white/60">
        <EmptyState icon={icon} title={t('coming_soon')} body={t('coming_soon_body')} />
      </div>
    </div>
  )
}
