import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMenu } from '../app/menu'
import { useMenuV2 } from '../app/menuV2'
import { entryHref } from '../app/nav'
import Icon from './Icon'

const ITEM = 'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 text-[0.9375rem] font-medium transition'
const ACTIVE = 'bg-white/12 text-white shadow-[inset_3px_0_0_var(--color-gold-500)] rtl:shadow-[inset_-3px_0_0_var(--color-gold-500)]'
const IDLE = 'text-white/75 hover:bg-white/6 hover:text-white'

/**
 * nav_v2 desktop sidebar: لوحة التحكم, then the section names only (no sub-items, nothing to expand). A section
 * opens its first tab; the tabs themselves are the row on the section page. Ctrl+K opens the quick search.
 */
export default function SideMenuV2({ onSearch }: { onSearch: () => void }) {
  const { t } = useTranslation('nav')
  const { top, active: activeEntry } = useMenu()
  const { sections, active } = useMenuV2()

  return (
    <nav aria-label={t('main')} className="flex flex-col gap-0.5 p-3">
      <button type="button" onClick={onSearch} className={`${ITEM} mb-2 border border-white/12 text-white/70 hover:bg-white/6 hover:text-white`}>
        <Icon name="search" className="size-5 shrink-0 opacity-90" />
        <span className="min-w-0 flex-1 truncate text-start text-sm">{t('v2search.open')}</span>
        <kbd className="rounded-md border border-white/15 px-1.5 text-xs text-white/60">Ctrl K</kbd>
      </button>
      {top && (
        <Link to={entryHref(top)} aria-current={activeEntry === top.key ? 'page' : undefined} className={`${ITEM} ${activeEntry === top.key ? ACTIVE : IDLE}`}>
          <Icon name={top.icon} className="size-5 shrink-0 opacity-90" />
          <span className="truncate">{t(`menu.${top.key}`)}</span>
        </Link>
      )}
      {sections.map(({ section, href }) => {
        const on = active === section.key
        return (
          <Link key={section.key} to={href} aria-current={on ? 'page' : undefined} className={`${ITEM} ${on ? ACTIVE : IDLE}`}>
            <Icon name={section.icon} className="size-5 shrink-0 opacity-90" />
            <span className="truncate">{t(`sections.${section.key}`, { defaultValue: t(`v2.sections.${section.key}`) })}</span>
          </Link>
        )
      })}
    </nav>
  )
}
