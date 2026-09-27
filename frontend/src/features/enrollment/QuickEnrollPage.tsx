import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PageTitle } from '../../components/ornaments'
import BulkImport from './BulkImport'
import QuickEnrollForm from './QuickEnrollForm'

type Tab = 'single' | 'import'

/** تسجيل سريع: staff register a student and place them in a circle in one step, or import a sheet. */
export default function QuickEnrollPage() {
  const { t } = useTranslation('enrollment')
  const [tab, setTab] = useState<Tab>('single')

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div>
        <PageTitle>{t('title')}</PageTitle>
        <p className="mt-2 text-sm text-ink/60">{t('subtitle')}</p>
      </div>

      <div role="tablist" aria-label={t('title')} className="inline-grid grid-cols-2 gap-1 rounded-2xl bg-stone-200/70 p-1">
        {(['single', 'import'] as const).map((k) => (
          <button
            key={k}
            type="button"
            role="tab"
            id={`tab-${k}`}
            aria-selected={tab === k}
            aria-controls={`panel-${k}`}
            onClick={() => setTab(k)}
            className={`rounded-xl px-4 py-2 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-brand-500 ${tab === k ? 'bg-white text-ink shadow-sm' : 'text-stone-600 hover:text-stone-900'}`}
          >
            {t(`tab_${k}`)}
          </button>
        ))}
      </div>

      <div id={`panel-${tab}`} role="tabpanel" aria-labelledby={`tab-${tab}`}>
        {tab === 'single' ? <QuickEnrollForm /> : <BulkImport />}
      </div>
    </div>
  )
}
