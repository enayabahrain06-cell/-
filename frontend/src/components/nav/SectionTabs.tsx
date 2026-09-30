import { useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'

export interface SectionTab { key: string; label: string; href: string; active: boolean }

/**
 * nav_v2: one row of tabs per section, each tab its own URL (a real <a>). On narrow screens the row scrolls
 * sideways and keeps the active tab in view. Tabs are 44px tall; the active one carries the brand underline.
 */
export default function SectionTabs({ label, tabs }: { label: string; tabs: SectionTab[] }) {
  const active = useRef<HTMLAnchorElement>(null)
  const current = tabs.find((t) => t.active)?.key

  useEffect(() => {
    // Centre the active tab inside the row only; `block: 'nearest'` keeps the page itself from scrolling.
    active.current?.scrollIntoView?.({ inline: 'center', block: 'nearest' })
  }, [current])

  if (tabs.length < 2) return null
  return (
    <nav aria-label={label} className="-mx-4 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0">
      <ul className="flex min-w-max gap-1 border-b border-ink/10">
        {tabs.map((t) => (
          <li key={t.key}>
            <Link ref={t.active ? active : undefined} to={t.href} aria-current={t.active ? 'page' : undefined}
              className={`-mb-px inline-flex min-h-11 items-center whitespace-nowrap border-b-2 px-3 text-sm transition ${
                t.active ? 'border-brand-700 font-semibold text-brand-800' : 'border-transparent font-medium text-ink/65 hover:border-ink/20 hover:text-ink'
              }`}>
              {t.label}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  )
}
