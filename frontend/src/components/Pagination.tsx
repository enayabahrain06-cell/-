import { useTranslation } from 'react-i18next'
import Icon from './Icon'
import { formatNumber } from '../lib/format'

const PAGE_BTN = 'grid size-10 place-items-center rounded-lg border border-ink/10 bg-white text-ink/70 hover:bg-ink/5 hover:text-ink focus-visible:outline-2 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-40'

export default function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (p: number) => void }) {
  const { t, i18n } = useTranslation()
  if (lastPage <= 1) return null
  const n = (v: number) => formatNumber(v, i18n.language)

  return (
    <nav aria-label={t('pagination.label')} className="flex flex-wrap items-center justify-between gap-3 text-sm text-ink/60">
      <p>{t('pagination.summary', { page: n(page), last: n(lastPage), total: n(total) })}</p>
      <div className="flex gap-1.5">
        <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className={PAGE_BTN} aria-label={t('pagination.previous')}>
          <Icon name="chevron" className="size-4 ltr:rotate-180" />
        </button>
        <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)} className={PAGE_BTN} aria-label={t('pagination.next')}>
          <Icon name="chevron" className="size-4 rtl:rotate-180" />
        </button>
      </div>
    </nav>
  )
}
