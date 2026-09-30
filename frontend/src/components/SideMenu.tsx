import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMenu } from '../app/menu'
import { entryHref, type MenuEntry } from '../app/nav'
import Icon from './Icon'

const OPEN_KEY = 'ahl.menu.open'

function readOpen(): string[] {
  try {
    const v = JSON.parse(localStorage.getItem(OPEN_KEY) ?? '[]')
    return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : []
  } catch {
    return []
  }
}

/**
 * Desktop sidebar: لوحة التحكم on its own, then the collapsible sections with their exact-name entries.
 * The section holding the current page is always open; the others remember whether the user opened them.
 */
export default function SideMenu() {
  const { t } = useTranslation('nav')
  const { top, sections, active } = useMenu()
  const [open, setOpen] = useState<string[]>(readOpen)
  const activeSection = sections.find((s) => s.entries.some((e) => e.key === active))?.key

  useEffect(() => {
    try { localStorage.setItem(OPEN_KEY, JSON.stringify(open)) } catch { /* storage unavailable */ }
  }, [open])

  const toggle = (key: string) => setOpen((o) => (o.includes(key) ? o.filter((k) => k !== key) : [...o, key]))

  return (
    <nav aria-label={t('main')} className="flex flex-col gap-0.5 p-3">
      {top && <Item entry={top} active={active === top.key} />}
      {sections.map((s) => {
        const isOpen = s.key === activeSection || open.includes(s.key)
        const panel = `menu-${s.key}`
        return (
          <div key={s.key} className="mt-1">
            <button type="button" onClick={() => toggle(s.key)} aria-expanded={isOpen} aria-controls={panel}
              className={`flex min-h-10 w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-[0.9375rem] font-semibold transition hover:bg-white/6 ${s.key === activeSection ? 'text-gold-300' : 'text-white/85'}`}>
              <Icon name={s.icon} className="size-5 shrink-0 opacity-90" />
              <span className="min-w-0 flex-1 truncate">{t(`sections.${s.key}`)}</span>
              <Icon name="chevron" className={`size-4 shrink-0 opacity-70 transition-transform ${isOpen ? 'rotate-90' : 'rtl:rotate-180'}`} />
            </button>
            {isOpen && (
              <div id={panel} className="mt-0.5 flex flex-col gap-0.5 border-s border-white/10 ms-5 ps-2">
                {s.entries.map((e) => <Item key={e.key} entry={e} active={active === e.key} nested />)}
              </div>
            )}
          </div>
        )
      })}
    </nav>
  )
}

function Item({ entry, active, nested = false }: { entry: MenuEntry; active: boolean; nested?: boolean }) {
  const { t } = useTranslation('nav')
  return (
    <Link to={entryHref(entry)} aria-current={active ? 'page' : undefined}
      className={`flex items-center gap-3 rounded-xl px-3 transition ${nested ? 'min-h-9 py-1.5 text-sm' : 'min-h-10 py-2 text-[0.9375rem] font-medium'} ${
        active ? 'bg-white/12 text-white shadow-[inset_3px_0_0_var(--color-gold-500)] rtl:shadow-[inset_-3px_0_0_var(--color-gold-500)]' : 'text-white/75 hover:bg-white/6 hover:text-white'
      }`}>
      {!nested && <Icon name={entry.icon} className="size-5 shrink-0 opacity-90" />}
      <span className="truncate">{t(`menu.${entry.key}`)}</span>
    </Link>
  )
}
