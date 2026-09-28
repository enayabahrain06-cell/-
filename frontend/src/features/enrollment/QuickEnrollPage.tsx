import { useRef, useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PageBand } from '../../components/ornaments'
import BulkImport from './BulkImport'
import QuickEnrollForm from './QuickEnrollForm'

type Tab = 'single' | 'import'
const TABS = ['single', 'import'] as const

/** تسجيل سريع: staff register a student and place them in a circle in one step, or import a sheet. */
export default function QuickEnrollPage() {
  const { t } = useTranslation('enrollment')
  const [tab, setTab] = useState<Tab>('single')
  const refs = useRef<Partial<Record<Tab, HTMLButtonElement | null>>>({})

  // WAI-ARIA tabs: arrow keys move between tabs (automatic activation), Home/End jump to the ends.
  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const i = TABS.indexOf(tab)
    const rtl = getComputedStyle(e.currentTarget).direction === 'rtl'
    const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[e.key as 'ArrowRight' | 'ArrowLeft']
    const next = step !== undefined ? TABS[(i + step + TABS.length) % TABS.length] : e.key === 'Home' ? TABS[0] : e.key === 'End' ? TABS[TABS.length - 1] : null
    if (!next) return
    e.preventDefault()
    setTab(next)
    refs.current[next]?.focus()
  }

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')} />

      <div role="tablist" aria-label={t('title')} onKeyDown={onKeyDown} className="inline-flex flex-wrap gap-1 rounded-xl bg-ink/5 p-1">
        {TABS.map((k) => (
          <button
            key={k}
            ref={(el) => { refs.current[k] = el }}
            type="button"
            role="tab"
            id={`tab-${k}`}
            aria-selected={tab === k}
            aria-controls={`panel-${k}`}
            tabIndex={tab === k ? 0 : -1}
            onClick={() => setTab(k)}
            className={`min-h-10 rounded-lg px-4 py-2 text-sm font-medium transition ${tab === k ? 'bg-white text-ink shadow-sm ring-1 ring-ink/10' : 'text-ink/60 hover:text-ink'}`}
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
