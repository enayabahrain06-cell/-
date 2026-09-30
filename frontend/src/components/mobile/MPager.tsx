import { useTranslation } from 'react-i18next'
import Icon from '../Icon'
import { formatNumber } from '../../lib/format'

/**
 * Mobile pager below lg: "page n of m" with 44px previous/next buttons (the desktop Pagination uses 40px ones).
 * Same `meta` and `onPage` as components/Pagination, so a list's page state is shared with desktop.
 */
export default function MPager({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (p: number) => void }) {
  const { t, i18n } = useTranslation()
  if (lastPage <= 1) return null
  const n = (v: number) => formatNumber(v, i18n.language)
  const btn = 'inline-grid size-11 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700 disabled:opacity-40'
  return (
    <nav aria-label={t('pagination.label')} className="flex items-center justify-between gap-3">
      <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className={btn} aria-label={t('pagination.previous')}>
        <Icon name="chevron" className="size-5 ltr:rotate-180" />
      </button>
      <p className="min-w-0 text-center text-[13px] tabular-nums text-ink/65">{t('pagination.summary', { page: n(page), last: n(lastPage), total: n(total) })}</p>
      <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)} className={btn} aria-label={t('pagination.next')}>
        <Icon name="chevron" className="size-5 rtl:rotate-180" />
      </button>
    </nav>
  )
}
